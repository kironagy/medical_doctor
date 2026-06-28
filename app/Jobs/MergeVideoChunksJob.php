<?php

namespace App\Jobs;

use App\Models\PatientFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MergeVideoChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    protected string $uuid;
    protected int $totalChunks;
    protected string $extension;

    public function __construct(string $uuid, int $totalChunks, string $extension)
    {
        $this->uuid = $uuid;
        $this->totalChunks = $totalChunks;
        $this->extension = $extension;
    }

    public function handle(): void
    {
        $tempDir = 'chunks/' . $this->uuid;

        $finalName = Str::random(40) . '.' . $this->extension;
        $finalPath = storage_path('app/public/patient_files/' . $finalName);

        if (!file_exists(storage_path('app/public/patient_files'))) {
            mkdir(storage_path('app/public/patient_files'), 0777, true);
        }

        $finalFile = fopen($finalPath, 'ab');

        for ($i = 0; $i < $this->totalChunks; $i++) {
            $partPath = Storage::disk('local')->path($tempDir . '/' . $i . '.part');

            if (file_exists($partPath)) {
                $chunkFile = fopen($partPath, 'rb');
                stream_copy_to_stream($chunkFile, $finalFile);
                fclose($chunkFile);
            }
        }

        fclose($finalFile);

        Storage::disk('local')->deleteDirectory($tempDir);

        $isVideo = in_array(strtolower($this->extension), ['mp4', 'mov', 'avi', 'webm', 'mkv', 'flv']);
        if ($isVideo) {
            $optimizedName = Str::random(40) . '.mp4';
            $optimizedPath = storage_path('app/public/patient_files/' . $optimizedName);

            $cmd = "ffmpeg -i " . escapeshellarg($finalPath) . " -vf \"scale=-2:480\" -vcodec libx264 -crf 30 -preset ultrafast -movflags +faststart -y " . escapeshellarg($optimizedPath) . " 2>&1";
            shell_exec($cmd);

            if (file_exists($optimizedPath) && filesize($optimizedPath) > 0) {
                unlink($finalPath); // Delete unoptimized raw merged file
                $finalName = $optimizedName;
            }
        }

        $patientFile = PatientFile::where('uuid', $this->uuid)->first();
        if ($patientFile) {
            $patientFile->update([
                'file_path' => '/storage/patient_files/' . $finalName,
                'upload_status' => 'completed',
            ]);
        }
    }
}
