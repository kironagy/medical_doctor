<?php

namespace App\Domains\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Domains\Media\Services\UploadService;
use App\Domains\Patients\Models\Patient;
use Exception;

/**
 * Merge a finalised upload session into a PatientFile record.
 *
 * This job runs on the "uploads" queue. The HTTP /complete endpoint returns
 * 202 Accepted immediately — the client is never blocked here.
 *
 * After merging:
 *  - Non-video files → marked "ready" instantly (no further processing needed).
 *  - Video files     → dispatched to OptimizeVideoJob, which also marks "ready"
 *                      instantly and then schedules a background thumbnail job.
 *
 * Timing for every step is stored in patientFile.processing_times so operators
 * can generate the pipeline report at any time.
 */
class MergeChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min (large files)
    public int $tries   = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $sessionId,
        public readonly int    $totalChunks,
        public readonly int    $patientId,
        public readonly array  $metadata,
        public readonly int    $uploaderId,
    ) {}

    public function handle(UploadService $uploadService): void
    {
        $t0 = microtime(true);

        $patient = Patient::findOrFail($this->patientId);

        // Merge chunks → creates PatientFile row with upload_status = 'queued'.
        $file = $uploadService->mergeChunks(
            $this->sessionId,
            $this->totalChunks,
            $patient,
            $this->metadata,
            $this->uploaderId,
        );

        $mergeMs = round((microtime(true) - $t0) * 1000, 2);

        Log::channel('upload')->info('MergeChunksJob: merge complete', [
            'session'    => $this->sessionId,
            'file_uuid'  => $file->uuid,
            'type'       => $file->type,
            'merge_ms'   => $mergeMs,
        ]);

        if ($file->type === 'video') {
            OptimizeVideoJob::dispatch($file)->onQueue('video');
        } else {
            $file->update(['upload_status' => 'ready']);
        }

        // Record timing — best-effort, never blocks the critical path.
        try {
            $times = [
                'merge_ms'        => $mergeMs,
                'file_size_bytes' => $file->size,
                'post_merge_action' => $file->type === 'video' ? 'dispatched_optimize' : 'marked_ready_directly',
            ];
            $file->update(['processing_times' => $times]);
        } catch (\Throwable) {
            // instrumentation is optional
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('upload')->error('MergeChunksJob failed', [
            'session' => $this->sessionId,
            'error'   => $exception->getMessage(),
        ]);
    }
}
