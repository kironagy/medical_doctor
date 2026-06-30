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
 * Merge a finalized upload session into a PatientFile and then dispatch any
 * post-processing (e.g. OptimizeVideoJob). Runs on the "uploads" queue so the
 * HTTP complete request never blocks on disk I/O for big files.
 */
class MergeChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 min
    public $tries = 3;
    public $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $sessionId,
        public readonly int $totalChunks,
        public readonly int $patientId,
        public readonly array $metadata,
        public readonly int $uploaderId,
    ) {}

    public function handle(UploadService $uploadService): void
    {
        $t0 = microtime(true);

        $patient = Patient::findOrFail($this->patientId);

        // The merge creates the PatientFile row and cleans the session dir.
        $file = $uploadService->mergeChunks(
            $this->sessionId,
            $this->totalChunks,
            $patient,
            $this->metadata,
            $this->uploaderId,
        );

        Log::channel('upload')->info('MergeChunksJob done', [
            'session' => $this->sessionId,
            'file_uuid' => $file->uuid,
            'total_ms' => round((microtime(true) - $t0) * 1000, 2),
        ]);

        // Hand off to post-processing. For non-video files we mark ready
        // immediately (mirrors the legacy synchronous behaviour).
        if ($file->type === 'video') {
            OptimizeVideoJob::dispatch($file)->onQueue('video');
        } else {
            $file->update(['upload_status' => 'ready']);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('upload')->error('MergeChunksJob failed', [
            'session' => $this->sessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}