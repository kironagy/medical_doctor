<?php

namespace App\Jobs;

use App\Models\PatientFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;
    
    public function __construct(private readonly int $patientFileId) {}

    public function handle(): void
    {
        $patientFile = PatientFile::find($this->patientFileId);
        if (!$patientFile || $patientFile->type !== 'video') {
            return;
        }

        $inputPath = Storage::disk('local')->path($patientFile->file_path);
        if (!file_exists($inputPath)) {
            return;
        }

        $thumbRel = substr($patientFile->file_path, 0, strrpos($patientFile->file_path, '.')) . '_thumb.jpg';
        $thumbAbs = Storage::disk('local')->path($thumbRel);
        $thumbDir = dirname($thumbAbs);
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        $ffmpegExists = false;
        if (function_exists('exec')) {
            @exec(DIRECTORY_SEPARATOR === '\\' ? 'where ffmpeg' : 'which ffmpeg', $output, $returnVar);
            $ffmpegExists = ($returnVar === 0);
        }

        if (!$ffmpegExists) {
            return;
        }

        $process = new Process([
            'ffmpeg', '-y', '-ss', '1', '-i', $inputPath,
            '-vframes', '1', '-vf', 'scale=-1:300', '-q:v', '5', $thumbAbs,
        ]);
        $process->setTimeout(60);
        $process->run();

        if ($process->isSuccessful() && file_exists($thumbAbs) && filesize($thumbAbs) > 512) {
            $patientFile->update(['thumbnail_path' => $thumbRel]);
            Log::channel('upload')->info('Thumbnail generated', ['uuid' => $patientFile->uuid]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('upload')->warning('GenerateThumbnailJob failed', ['error' => $e->getMessage()]);
    }
}
