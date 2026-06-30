<?php

namespace App\Domains\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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
        public readonly string $fileUuid,
        public readonly int    $totalChunks,
        public readonly int    $patientId,
        public readonly array  $metadata,
        public readonly int    $uploaderId,
    ) {}

    public function handle(UploadService $uploadService): void
    {
        $t0 = microtime(true);
        $sessionKey = "upload:{$this->sessionId}";

        Log::channel('upload')->info('MergeChunksJob: started', [
            'session'    => $this->sessionId,
            'file_uuid'  => $this->fileUuid,
            'total_chunks' => $this->totalChunks,
        ]);

        $patient = Patient::findOrFail($this->patientId);

        // Update session status to 'merging' before starting the merge
        $sessionData = Cache::get($sessionKey, []);
        $sessionData['status'] = 'merging';
        $sessionData['merge_started_at'] = now()->toIso8601String();
        Cache::put($sessionKey, $sessionData, now()->addHours(6));

        // Merge chunks → creates PatientFile row with upload_status = 'queued'.
        $file = $uploadService->mergeChunks(
            $this->sessionId,
            $this->fileUuid,
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

        // Update session status to 'processing' after PatientFile is created
        $sessionData = Cache::get($sessionKey, []);
        $sessionData['status'] = 'processing';
        $sessionData['patient_file_created_at'] = now()->toIso8601String();
        Cache::put($sessionKey, $sessionData, now()->addHours(6));

        if ($file->type === 'video') {
            OptimizeVideoJob::dispatch($file)->onQueue('video');
        } else {
            $file->update(['upload_status' => 'ready']);
            
            // Update session status to 'ready' for non-video files
            $sessionData = Cache::get($sessionKey, []);
            $sessionData['status'] = 'ready';
            $sessionData['ready_at'] = now()->toIso8601String();
            Cache::put($sessionKey, $sessionData, now()->addHours(6));
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
        $sessionKey = "upload:{$this->sessionId}";
        
        // Update session status to 'failed'
        $sessionData = Cache::get($sessionKey, []);
        $sessionData['status'] = 'failed';
        $sessionData['error'] = $exception->getMessage();
        $sessionData['failed_at'] = now()->toIso8601String();
        Cache::put($sessionKey, $sessionData, now()->addHours(6));
        
        Log::channel('upload')->error('MergeChunksJob failed', [
            'session' => $this->sessionId,
            'file_uuid' => $this->fileUuid,
            'error'   => $exception->getMessage(),
        ]);
    }
}
