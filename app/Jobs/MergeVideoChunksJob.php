<?php

namespace App\Jobs;

use App\Models\PatientFile;
use App\Services\VideoProcessingService;
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

        $patientFile = PatientFile::where('uuid', $this->uuid)->first();
        if (!$patientFile) {
            if (file_exists($finalPath)) unlink($finalPath);
            return;
        }

        $isVideo = in_array(strtolower($this->extension), ['mp4', 'mov', 'avi', 'webm', 'mkv', 'flv']);
        if ($isVideo) {
            $hlsFolder = storage_path('app/public/patient_files/' . $this->uuid);
            if (!file_exists($hlsFolder)) {
                mkdir($hlsFolder, 0777, true);
            }

            $processor = new VideoProcessingService();

            // 1. Generate Thumbnail
            $thumbnailPath = $hlsFolder . '/thumbnail.jpg';
            $processor->generateThumbnail($finalPath, $thumbnailPath);

            // 2. Generate Preview GIF
            $previewPath = $hlsFolder . '/preview.gif';
            $processor->generatePreviewGif($finalPath, $previewPath);

            // 3. Generate HLS stream playlist (master.m3u8)
            $hlsSuccess = $processor->generateHls($finalPath, $hlsFolder);

            if ($hlsSuccess) {
                unlink($finalPath); // delete raw video
                $patientFile->update([
                    'file_path' => '/storage/patient_files/' . $this->uuid . '/master.m3u8',
                    'thumbnail_path' => '/storage/patient_files/' . $this->uuid . '/thumbnail.jpg',
                    'upload_status' => 'completed',
                ]);
            } else {
                // Failback to original MP4 if HLS fails
                $patientFile->update([
                    'file_path' => '/storage/patient_files/' . $finalName,
                    'thumbnail_path' => '/storage/patient_files/' . $this->uuid . '/thumbnail.jpg',
                    'upload_status' => 'completed',
                ]);
            }
        } else {
            $patientFile->update([
                'file_path' => '/storage/patient_files/' . $finalName,
                'upload_status' => 'completed',
            ]);
        }
    }
}
