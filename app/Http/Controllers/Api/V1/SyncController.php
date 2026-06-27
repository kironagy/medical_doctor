<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSyncJob;
use App\Models\SyncJob;
use App\Models\SyncState;
use App\Services\SyncService;
use Illuminate\Http\Request;

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

    // ─── Push (upload from mobile client) ─────────────────────────────────────

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

    // ─── Trigger Async Sync (mobile → dispatch queue job) ────────────────────

    /**
     * Accepts a sync request and immediately dispatches a background job.
     * Returns 202 Accepted with the job UUID for polling via /sync/status/{uuid}.
     */
    public function triggerNow(Request $request)
    {
        // Token is read from SyncState on the mobile (SQLite) side only.
        // On the server side this endpoint is auth-guarded via Sanctum.
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

        ProcessSyncJob::dispatch($syncJob->uuid, $token)
            ->onQueue('sync');

        return response()->json([
            'success'      => true,
            'sync_job_id'  => $syncJob->uuid,
            'status'       => 'pending',
            'message'      => 'Synchronization job queued successfully.',
        ], 202);
    }

    // ─── Status Check (mobile polls this) ─────────────────────────────────────

    /**
     * Returns the current status of a sync job.
     * The mobile app polls this every 3-5 seconds after receiving a 202.
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
