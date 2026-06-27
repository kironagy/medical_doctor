<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Models\SyncState;
use App\Services\OfflineSyncEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ProcessSyncJob
 *
 * Handles the full synchronization cycle asynchronously:
 *   1. Upload pending offline queue to the remote server.
 *   2. Download delta changes from the remote server.
 *
 * Design decisions:
 * - Runs on the dedicated "sync" queue, separate from default jobs.
 * - Timeout: 3600 seconds (1 hour) to support 10,000+ record syncs.
 * - Max exceptions: 3 — prevents infinite retry loops on systemic failures.
 * - The SyncJob model (not the Laravel job) tracks real-time progress.
 * - All data is read from the database on execution — safe across restarts.
 */
class ProcessSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum seconds this job may run before the worker times it out.
     * 3600 = 1 hour, sufficient for 10,000 records with file attachments.
     */
    public int $timeout = 3600;

    /**
     * Maximum number of unhandled exceptions before the job is marked failed.
     */
    public int $maxExceptions = 3;

    /**
     * How many seconds to wait before retrying after a failure.
     */
    public int $backoff = 60;

    public function __construct(
        private readonly string $syncJobUuid,
        private readonly string $token
    ) {
    }

    public function handle(OfflineSyncEngine $engine): void
    {
        // Re-fetch the SyncJob record from database (safe after restarts)
        $syncJob = SyncJob::where('uuid', $this->syncJobUuid)->firstOrFail();

        Log::info('ProcessSyncJob: started.', [
            'sync_job_uuid'  => $this->syncJobUuid,
            'laravel_job_id' => $this->job?->getJobId(),
            'queue'          => $this->queue,
        ]);

        try {
            $result = $engine->sync($this->token, $syncJob);

            $uploaded  = $result['uploaded']  ?? 0;
            $downloaded = $result['downloaded'] ?? 0;

            // Count final failed/skipped items from DB for accuracy
            $failed  = \App\Models\SyncQueueItem::where('status', 'failed')->count();
            $skipped = \App\Models\SyncQueueItem::where('status', 'skipped')->count();

            $syncJob->markCompleted($uploaded, $downloaded, $failed, $skipped);

            Log::info('ProcessSyncJob: completed.', [
                'sync_job_uuid' => $this->syncJobUuid,
                'uploaded'      => $uploaded,
                'downloaded'    => $downloaded,
                'failed'        => $failed,
                'skipped'       => $skipped,
            ]);

        } catch (Throwable $throwable) {
            $syncJob->markFailed($throwable->getMessage());

            Log::error('ProcessSyncJob: failed with exception.', [
                'sync_job_uuid' => $this->syncJobUuid,
                'message'       => $throwable->getMessage(),
                'trace'         => $throwable->getTraceAsString(),
            ]);

            // Re-throw so Laravel queue records the failure in failed_jobs
            throw $throwable;
        }
    }

    /**
     * Called when the job fails permanently (after maxExceptions).
     */
    public function failed(Throwable $exception): void
    {
        $syncJob = SyncJob::where('uuid', $this->syncJobUuid)->first();
        $syncJob?->markFailed('Job permanently failed: ' . $exception->getMessage());

        Log::error('ProcessSyncJob: permanently failed.', [
            'sync_job_uuid' => $this->syncJobUuid,
            'message'       => $exception->getMessage(),
        ]);
    }
}
