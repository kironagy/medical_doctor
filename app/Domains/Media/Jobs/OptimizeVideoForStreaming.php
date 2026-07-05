<?php

namespace App\Domains\Media\Jobs;

use App\Domains\Media\Models\PatientFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class OptimizeVideoForStreaming implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours
    public $tries = 3;

    public function __construct(
        public int $fileId
    ) {}

    public function handle()
    {
        $file = PatientFile::find($this->fileId);
        if (!$file) {
            Log::warning('Video optimization skipped: file not found', ['id' => $this->fileId]);
            return;
        }

        $disk = Storage::disk('local');
        $path = $file->file_path;

        if (!$disk->exists($path)) {
            Log::warning('Video optimization skipped: file not found on disk', ['uuid' => $file->uuid, 'path' => $path]);
            return;
        }

        if (!str_starts_with($file->mime_type, 'video/')) {
            return;
        }

        // Check if ffmpeg is available
        $ffmpegExists = false;
        if (function_exists('exec')) {
            try {
                $whichCmd = DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg' : 'which ffmpeg';
                @exec($whichCmd, $output, $returnVar);
                $ffmpegExists = ($returnVar === 0);
            } catch (\Throwable $e) {
                $ffmpegExists = false;
            }
        }

        if (!$ffmpegExists) {
            Log::warning('Video optimization skipped: ffmpeg not found on system');
            return;
        }

        $absolutePath = $disk->path($path);
        $pathInfo = pathinfo($absolutePath);
        $tempPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.optimized.' . $pathInfo['extension'];

        // Remove temp file if exists from previous failed run
        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }

        $process = new Process([
            'ffmpeg',
            '-y',
            '-i', $absolutePath,
            '-c', 'copy',
            '-movflags', 'faststart',
            $tempPath
        ]);
        $process->setTimeout(7200);

        try {
            $process->mustRun();
        } catch (\Throwable $e) {
            Log::error('Video optimization failed', [
                'id' => $this->fileId,
                'uuid' => $file->uuid,
                'path' => $path,
                'error' => $e->getMessage(),
                'output' => $process->getErrorOutput(),
            ]);
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw $e;
        }

        if (file_exists($tempPath)) {
            rename($tempPath, $absolutePath);
            Log::info('Video optimized for streaming', ['id' => $this->fileId, 'uuid' => $file->uuid, 'path' => $path]);
        } else {
            Log::warning('Video optimization completed but temp file missing', ['id' => $this->fileId, 'uuid' => $file->uuid]);
        }
    }
}
