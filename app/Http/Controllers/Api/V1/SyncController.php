<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSyncJob;
use App\Models\SyncJob;
use App\Models\SyncQueueItem;
use App\Models\SyncState;
use App\Services\OfflineSyncEngine;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncController extends Controller
{
    // ─── Seed (initial full download) ─────────────────────────────────────────

    public function seed(Request $request, SyncService $sync)
    {
        $page  = (int) $request->query('page', 1);
        $limit = min(max((int) $request->query('limit', 100), 1), 500);

        return response()->json($sync->initialSeed($page, $limit));
    }

    // ─── Changes (delta download, cursor-paginated) ────────────────────────────

    public function changes(Request $request, SyncService $sync)
    {
        $request->validate([
            'since'  => ['nullable', 'date'],
            'cursor' => ['nullable', 'string'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $limit = (int) $request->query('limit', 100);

        return response()->json(
            $sync->changes(
                since:  $request->query('since'),
                cursor: $request->query('cursor'),
                limit:  $limit,
            )
        );
    }

    // ─── Push (upload from mobile client to server) ────────────────────────────

    public function push(Request $request, SyncService $sync)
    {
        $data = $request->validate([
            'operations'               => ['required', 'array'],
            'operations.*.uuid'        => ['nullable', 'uuid'],
            'operations.*.record_uuid' => ['nullable', 'uuid'],
            'operations.*.table'       => ['required', 'string'],
            'operations.*.operation'   => ['required', 'string', 'in:create,update,delete'],
            'operations.*.payload'     => ['nullable', 'array'],
        ]);

        return response()->json([
            'server_time' => now()->toISOString(),
            'results'     => $sync->applyOperations($data['operations']),
        ]);
    }

    // ─── Trigger Sync — Context-Aware ─────────────────────────────────────────

    /**
     * Trigger a synchronization cycle.
     *
     * TWO EXECUTION PATHS depending on the database driver:
     *
     * ┌─ MOBILE (SQLite) ──────────────────────────────────────────────────────┐
     * │ NativePHP Mobile has NO background queue workers.                      │
     * │ Dispatching to the database queue would write a job to SQLite that      │
     * │ is NEVER picked up — causing the UI to hang forever.                   │
     * │                                                                         │
     * │ Fix: Run the sync job INLINE via dispatchSync().                        │
     * │ The HTTP request blocks until sync completes (local loopback — fast).  │
     * │ Response returns HTTP 200 with status='completed'.                     │
     * │ The JS receives the final result directly — no polling needed.         │
     * └────────────────────────────────────────────────────────────────────────┘
     *
     * ┌─ SERVER (MySQL + Supervisor) ──────────────────────────────────────────┐
     * │ Supervisor keeps queue workers alive permanently.                       │
     * │ Dispatching to the queue is safe — workers will pick up the job.       │
     * │ Returns HTTP 202 Accepted immediately.                                  │
     * │ Mobile polls /sync/status/{uuid} for progress.                         │
     * └────────────────────────────────────────────────────────────────────────┘
     */
    public function triggerNow(Request $request, OfflineSyncEngine $engine)
    {
        $token = SyncState::where('key', 'api_token')->first()?->value['data'];

        if (!$token) {
            return response()->json([
                'success' => false,
                'error'   => 'No API token available for synchronization.',
            ], 401);
        }

        $syncJob = SyncJob::create([
            'status'    => 'pending',
            'direction' => 'both',
        ]);

        $isMobile = config('database.default') === 'sqlite';

        if ($isMobile) {
            // ── MOBILE PATH: Run inline (no background workers available) ─────
            return $this->runSyncInline($engine, $syncJob, $token);
        }

        // ── SERVER PATH: Dispatch to queue, return 202 immediately ───────────
        ProcessSyncJob::dispatch($syncJob->uuid, $token)
            ->onQueue('sync');

        Log::info('sync.trigger: dispatched to queue.', ['sync_job_uuid' => $syncJob->uuid]);

        return response()->json([
            'success'     => true,
            'sync_job_id' => $syncJob->uuid,
            'status'      => 'pending',
            'inline'      => false,
            'message'     => 'Synchronization job queued.',
        ], 202);
    }

    /**
     * Run the sync inline (mobile path).
     *
     * Executes the full sync cycle within the current HTTP request.
     * Uses the same OfflineSyncEngine and chunking logic as the queue job —
     * the only difference is execution context (inline vs. background worker).
     *
     * The response includes the final result so the JS needs NO polling.
     */
    private function runSyncInline(OfflineSyncEngine $engine, SyncJob $syncJob, string $token)
    {
        Log::info('sync.trigger: running inline (mobile/SQLite context).', [
            'sync_job_uuid' => $syncJob->uuid,
        ]);

        try {
            $result = $engine->sync($token, $syncJob);

            $uploaded   = $result['uploaded']   ?? 0;
            $downloaded = $result['downloaded']  ?? 0;
            $failed     = SyncQueueItem::where('status', 'failed')->count();
            $skipped    = SyncQueueItem::where('status', 'skipped')->count();

            $syncJob->markCompleted($uploaded, $downloaded, $failed, $skipped);

            Log::info('sync.trigger: inline sync completed.', [
                'sync_job_uuid' => $syncJob->uuid,
                'uploaded'      => $uploaded,
                'downloaded'    => $downloaded,
                'failed'        => $failed,
                'skipped'       => $skipped,
            ]);

            return response()->json([
                'success'         => true,
                'sync_job_id'     => $syncJob->uuid,
                'status'          => 'completed',
                'inline'          => true,
                'uploaded'        => $uploaded,
                'downloaded'      => $downloaded,
                'failed'          => $failed,
                'skipped'         => $skipped,
                'progress'        => 100,
                'processed_items' => $uploaded + $downloaded,
                'failed_items'    => $failed,
                'skipped_items'   => $skipped,
            ], 200);

        } catch (Throwable $throwable) {
            $syncJob->markFailed($throwable->getMessage());

            Log::error('sync.trigger: inline sync failed.', [
                'sync_job_uuid' => $syncJob->uuid,
                'message'       => $throwable->getMessage(),
                'trace'         => $throwable->getTraceAsString(),
            ]);

            return response()->json([
                'success'     => false,
                'sync_job_id' => $syncJob->uuid,
                'status'      => 'failed',
                'inline'      => true,
                'error'       => $throwable->getMessage(),
            ], 500);
        }
    }

    // ─── Status Check (for server-side polling) ────────────────────────────────

    /**
     * Returns the current status of a sync job.
     * Used by the mobile app when syncing against the server queue (non-inline path).
     */
    public function status(string $uuid)
    {
        $syncJob = SyncJob::where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'sync_job_id'     => $syncJob->uuid,
            'status'          => $syncJob->status,
            'progress'        => $syncJob->progressPercentage(),
            'total_items'     => $syncJob->total_items,
            'processed_items' => $syncJob->processed_items,
            'failed_items'    => $syncJob->failed_items,
            'skipped_items'   => $syncJob->skipped_items,
            'started_at'      => $syncJob->started_at?->toISOString(),
            'completed_at'    => $syncJob->completed_at?->toISOString(),
            'error'           => $syncJob->error,
        ]);
    }
}
