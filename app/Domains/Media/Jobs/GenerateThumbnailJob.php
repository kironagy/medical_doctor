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
 * Optional background thumbnail generation for video files.
 *
 * This job runs after a video file is already uploaded and marked as 'ready'.
 * Thumbnail generation failure NEVER affects file usability - the video
 * remains playable regardless of thumbnail success/failure.
 *
 * Simple approach:
 * - Extract one frame at 1 second using ffmpeg
 * - If ffmpeg fails or is unavailable, log and exit cleanly
 * - Medical clinic context: reliability over optimization
 */
class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // 2 minutes max
    public int $tries = 1; // Single attempt - thumbnail is optional
    
    public function __construct(private readonly int $patientFileId) {}

    public function handle(): void
    {
        // ── PERF FIX: Skip on the embedded device. ─────────────────────────
        // On the NativePHP app QUEUE_CONNECTION=sync, so this job runs INLINE
        // inside the upload-complete HTTP request. ffmpeg frame extraction can
        // block the request for a long time. Thumbnails are generated on the
        // production server; the mobile UI falls back to a play icon when no
        // thumbnail exists.
        if (config('database.default') === 'sqlite') {
            return;
        }

        $patientFile = PatientFile::find($this->patientFileId);
        
        if (!$patientFile) {
            Log::warning('GenerateThumbnailJob: PatientFile not found', [
                'patient_file_id' => $this->patientFileId,
            ]);
            return;
        }

        // Only generate thumbnails for video files
        if ($patientFile->type !== 'video') {
            return;
        }

        $inputPath = Storage::disk('local')->path($patientFile->file_path);

        if (!file_exists($inputPath)) {
            Log::warning('GenerateThumbnailJob: video file not found', [
                'uuid' => $patientFile->uuid,
                'file_path' => $patientFile->file_path,
            ]);
            return;
        }

        try {
            $this->generateThumbnail($patientFile, $inputPath);
        } catch (\Exception $e) {
            Log::warning('GenerateThumbnailJob: thumbnail generation failed (non-critical)', [
                'uuid' => $patientFile->uuid,
                'error' => $e->getMessage(),
            ]);
            // Continue - thumbnail failure should never block anything
        }
    }

    private function generateThumbnail(PatientFile $patientFile, string $inputPath): void
    {
        // Generate thumbnail path
        $thumbRelPath = $this->generateThumbnailPath($patientFile);
        $thumbAbsPath = Storage::disk('local')->path($thumbRelPath);
        
        // Ensure thumbnail directory exists
        $thumbDir = dirname($thumbAbsPath);
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        // FFmpeg command to extract thumbnail at 1 second
        $cmd = [
            'ffmpeg',
            '-y', // overwrite output files
            '-ss', '1', // seek to 1 second
            '-i', $inputPath,
            '-vframes', '1', // extract one frame
            '-vf', 'scale=-1:300', // scale to 300px height, maintain aspect ratio
            '-q:v', '5', // good JPEG quality
            $thumbAbsPath,
        ];

        $process = new Process($cmd);
        $process->setTimeout(60);
        $process->run();

        // Check if thumbnail was created successfully
        if (!$process->isSuccessful() || !file_exists($thumbAbsPath) || filesize($thumbAbsPath) < 100) {
            throw new \Exception('FFmpeg thumbnail extraction failed: ' . $process->getErrorOutput());
        }

        // Update PatientFile with thumbnail path
        $patientFile->update([
            'thumbnail_path' => $thumbRelPath,
        ]);

        Log::info('Thumbnail generated successfully', [
            'uuid' => $patientFile->uuid,
            'thumbnail_path' => $thumbRelPath,
        ]);
    }

    private function generateThumbnailPath(PatientFile $patientFile): string
    {
        $pathInfo = pathinfo($patientFile->file_path);
        return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_thumb.jpg';
    }

    /**
     * Handle job failure - log but don't block anything.
     */
    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateThumbnailJob failed permanently (non-critical)', [
            'patient_file_id' => $this->patientFileId,
            'error' => $e->getMessage(),
        ]);
    }
}
