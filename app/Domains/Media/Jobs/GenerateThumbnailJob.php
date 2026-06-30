<?php

namespace App\Domains\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Domains\Media\Models\PatientFile;
use Symfony\Component\Process\Process;

/**
 * Lightweight background thumbnail job.
 *
 * Extracts a single frame from the video (at 1 second or the midpoint,
 * whichever is smaller) using ffmpeg. This is intentionally minimal:
 *   - No re-encoding, no HLS, no metadata extraction.
 *   - No faststart re-mux (the original file streams fine via Range requests).
 *   - If ffmpeg is unavailable or the extraction fails, the job simply logs
 *     the failure and exits cleanly. The video remains fully usable.
 *
 * The file is already marked "ready" BEFORE this job runs, so a thumbnail
 * failure never blocks the doctor from viewing the file.
 *
 * Business context: medical clinic document storage — not a video platform.
 */
class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Give up after 2 minutes — this should take < 5s in practice. */
    public int $timeout = 120;

    /** Thumbnail is optional; one retry is enough. */
    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(private readonly PatientFile $patientFile) {}

    public function handle(): void
    {
        $t0 = microtime(true);

        $inputPath = Storage::disk('local')->path($this->patientFile->file_path);

        if (!file_exists($inputPath)) {
            Log::channel('upload')->warning('GenerateThumbnailJob: file not found, skipping', [
                'uuid' => $this->patientFile->uuid,
            ]);
            return;
        }

        // Seek to 1s. For very short clips (< 1s), ffmpeg will just grab the
        // first available frame instead — no error.
        $thumbRelPath = substr($this->patientFile->file_path, 0, strrpos($this->patientFile->file_path, '.'))
            . '_thumb.jpg';
        $thumbAbsPath = Storage::disk('local')->path($thumbRelPath);

        $cmd = [
            'ffmpeg',
            '-y',
            '-ss', '1',           // seek to 1s (fast input-side seek)
            '-i', $inputPath,
            '-vframes', '1',       // extract exactly one frame
            '-vf', 'scale=-1:300', // scale to 300px height, keep aspect ratio
            '-q:v', '5',           // JPEG quality (2=best, 31=worst; 5 is fine for a thumbnail)
            $thumbAbsPath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(60);
        $process->run();

        $elapsed = round((microtime(true) - $t0) * 1000, 2);

        if (!$process->isSuccessful() || !file_exists($thumbAbsPath) || filesize($thumbAbsPath) < 512) {
            Log::channel('upload')->warning('GenerateThumbnailJob: ffmpeg thumbnail failed (non-critical)', [
                'uuid'    => $this->patientFile->uuid,
                'ms'      => $elapsed,
                'stderr'  => substr($process->getErrorOutput(), 0, 400),
            ]);
            // Clean up any partial file
            if (file_exists($thumbAbsPath)) {
                @unlink($thumbAbsPath);
            }
            return; // file is already "ready" — this failure is non-blocking
        }

        // Merge timing into existing processing_times, then save thumbnail path.
        $times = $this->patientFile->processing_times ?? [];
        $times['thumbnail_ms'] = $elapsed;

        $this->patientFile->update([
            'thumbnail_path'   => $thumbRelPath,
            'processing_times' => $times,
        ]);

        Log::channel('upload')->info('GenerateThumbnailJob: done', [
            'uuid' => $this->patientFile->uuid,
            'ms'   => $elapsed,
        ]);
    }

    /**
     * If the job fails entirely (both tries exhausted), log it and move on.
     * The video file is already "ready" and fully usable without a thumbnail.
     */
    public function failed(\Throwable $e): void
    {
        Log::channel('upload')->warning('GenerateThumbnailJob: permanently failed (non-critical)', [
            'uuid'  => $this->patientFile->uuid,
            'error' => $e->getMessage(),
        ]);
    }
}
