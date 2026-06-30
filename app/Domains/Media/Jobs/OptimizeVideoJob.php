<?php

namespace App\Domains\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Domains\Media\Models\PatientFile;

/**
 * Post-upload video handler — medical clinic edition.
 *
 * Previous behaviour (removed):
 *   ❌  ffprobe full metadata extraction
 *   ❌  MP4 faststart re-mux / codec transcode (files already stream fine)
 *   ❌  Multi-timestamp thumbnail extraction (4 attempts)
 *   ❌  HLS adaptive streaming (1080p / 720p / 480p / 360p renditions)
 *
 * Why removed: this is a document-storage system. The original file is served
 * with HTTP Range support (206 Partial Content) so byte-range seeking works
 * perfectly without any transcoding. HLS is a video-platform feature with no
 * benefit here — it was blocking "ready" status for potentially hours.
 *
 * New behaviour:
 *   1. Mark the file "ready" immediately — doctor can view it right away.
 *   2. Dispatch GenerateThumbnailJob to a low-priority background queue.
 *      If thumbnail extraction fails, the file remains fully usable.
 *
 * Total time added to the critical path: ~0 ms.
 */
class OptimizeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries = 2;

    public function __construct(private readonly PatientFile $patientFile) {}

    public function handle(): void
    {
        $t0 = microtime(true);

        // The file is on disk and ready to be served right now.
        // Mark it available without any processing delay.
        $this->patientFile->update(['upload_status' => 'ready']);

        $elapsed = round((microtime(true) - $t0) * 1000, 2);

        // Record timing — best-effort, never blocks marking the file ready.
        try {
            $times = $this->patientFile->processing_times ?? [];
            $times['mark_ready_ms'] = $elapsed;
            $this->patientFile->update(['processing_times' => $times]);
        } catch (\Throwable) {
            // instrumentation is optional
        }

        Log::channel('upload')->info('OptimizeVideoJob: file marked ready immediately', [
            'uuid' => $this->patientFile->uuid,
            'ms'   => $elapsed,
        ]);

        // Thumbnail is optional and runs entirely in the background.
        // Uses the same 'video' queue — no separate worker needed.
        GenerateThumbnailJob::dispatch($this->patientFile)->onQueue('video');
    }

    public function failed(\Throwable $e): void
    {
        // Even if this job fails somehow, try to mark the file usable.
        try {
            $this->patientFile->update(['upload_status' => 'ready']);
        } catch (\Throwable) {
            // best effort
        }

        Log::channel('upload')->error('OptimizeVideoJob failed', [
            'uuid'  => $this->patientFile->uuid,
            'error' => $e->getMessage(),
        ]);
    }
}
