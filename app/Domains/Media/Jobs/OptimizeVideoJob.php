<?php

namespace App\Domains\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Domains\Media\Models\PatientFile;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

class OptimizeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour max
    public $tries = 3;
    public $backoff = 60; // Wait 1 min before retrying
    
    public function __construct(private readonly PatientFile $patientFile) {}

    public function handle(): void
    {
        $this->patientFile->update(['upload_status' => 'processing']);

        $inputPath = Storage::disk('local')->path($this->patientFile->file_path);
        if (!file_exists($inputPath)) {
            $this->fail(new Exception("File not found at path: {$inputPath}"));
            return;
        }

        // 1. FFPROBE METADATA
        $ffprobeCmd = [
            'ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $inputPath
        ];
        $process = new Process($ffprobeCmd);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->fail(new Exception("ffprobe failed: " . $process->getErrorOutput()));
            return;
        }

        $metadata = json_decode($process->getOutput(), true);
        $hasVideo = false;
        $codec = null;
        
        foreach ($metadata['streams'] ?? [] as $stream) {
            if (isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                $hasVideo = true;
                $codec = $stream['codec_name'] ?? null;
                break;
            }
        }

        // If not a video, mark completed immediately
        if (!$hasVideo) {
            $this->patientFile->update(['upload_status' => 'ready']);
            return;
        }

        $this->patientFile->update(['upload_status' => 'optimizing']);

        // 2. FFMPEG FASTSTART (Progressive MP4)
        $ext = pathinfo($inputPath, PATHINFO_EXTENSION);
        $outputPath = substr($inputPath, 0, -(strlen($ext) + 1)) . '_optimized.mp4';

        if (in_array($codec, ['h264', 'hevc'])) {
            // ZERO Re-encoding, pure disk copy
            $ffmpegCmd = [
                'ffmpeg', '-y', '-i', $inputPath,
                '-c', 'copy',
                '-movflags', '+faststart',
                $outputPath
            ];
            Log::info("Running ZERO RE-ENCODING faststart on " . $this->patientFile->uuid);
        } else {
            // Transcode
            $ffmpegCmd = [
                'ffmpeg', '-y', '-i', $inputPath,
                '-c:v', 'libx264',
                '-preset', 'fast',
                '-c:a', 'aac',
                '-movflags', '+faststart',
                $outputPath
            ];
            Log::info("Running Transcode faststart on " . $this->patientFile->uuid);
        }

        $process = new Process($ffmpegCmd);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->patientFile->update(['upload_status' => 'failed']);
            $this->fail(new Exception("ffmpeg failed: " . $process->getErrorOutput()));
            return;
        }

        // 3. CLEANUP & SWAP
        unlink($inputPath);
        
        // Ensure the final path is .mp4 now, update DB if extension changed
        $newRelativePath = substr($this->patientFile->file_path, 0, -(strlen($ext) + 1)) . '.mp4';
        $newAbsolutePath = Storage::disk('local')->path($newRelativePath);
        
        rename($outputPath, $newAbsolutePath); 

        $this->patientFile->update(['upload_status' => 'generating_preview']);

        // 4. THUMBNAIL EXTRACTION
        $thumbRelativePath = substr($newRelativePath, 0, -4) . '_thumb.jpg';
        $thumbAbsolutePath = Storage::disk('local')->path($thumbRelativePath);
        
        $thumbCmd = [
            'ffmpeg', '-y', '-i', $newAbsolutePath,
            '-ss', '00:00:01', // extract at 1 second
            '-vframes', '1',
            '-vf', 'scale=-1:300', // scale height to 300px to save space
            $thumbAbsolutePath
        ];
        
        $thumbProcess = new Process($thumbCmd);
        $thumbProcess->run();
        
        $finalThumbPath = $thumbProcess->isSuccessful() ? $thumbRelativePath : null;

        // 5. COMPLETE
        $dbMeta = $this->patientFile->video_metadata ?? [];
        $dbMeta['codec'] = $codec;
        $dbMeta['optimized'] = true;
        
        $this->patientFile->update([
            'upload_status' => 'ready',
            'video_metadata' => $dbMeta,
            'file_path' => $newRelativePath,
            'thumbnail_path' => $finalThumbPath
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $dbMeta = $this->patientFile->video_metadata ?? [];
        $dbMeta['error'] = $exception->getMessage();
        
        $this->patientFile->update([
            'upload_status' => 'failed',
            'video_metadata' => $dbMeta
        ]);
        
        Log::error("OptimizeVideoJob failed for File {$this->patientFile->uuid}: " . $exception->getMessage());
    }
}
