<?php

namespace App\Jobs;

use App\Models\PatientFile;
use App\Services\HLSGenerator;
use App\Services\MetadataExtractor;
use App\Services\ThumbnailGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VideoProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900;

    protected string $uuid;
    protected string $rawPath;
    protected string $extension;

    public function __construct(string $uuid, string $rawPath, string $extension)
    {
        $this->uuid = $uuid;
        $this->rawPath = $rawPath;
        $this->extension = $extension;
    }

    public function handle(): void
    {
        Log::info("VideoProcessingJob: starting processing for uuid", ['uuid' => $this->uuid]);

        $patientFile = PatientFile::where('uuid', $this->uuid)->first();
        if (!$patientFile) {
            if (file_exists($this->rawPath)) unlink($this->rawPath);
            Log::warning("VideoProcessingJob: patient file record not found for uuid", ['uuid' => $this->uuid]);
            return;
        }

        $patientFile->update([
            'upload_status' => 'processing',
            'processing_stage' => 'extracting_metadata',
            'processing_progress' => 30,
        ]);

        $isVideo = in_array(strtolower($this->extension), ['mp4', 'mov', 'avi', 'webm', 'mkv', 'flv']);
        if ($isVideo) {
            $hlsFolder = storage_path('app/public/patient_files/' . $this->uuid);
            if (!file_exists($hlsFolder)) {
                mkdir($hlsFolder, 0777, true);
            }

            try {
                // 1. Metadata extraction
                Log::info("VideoProcessingJob: extracting metadata", ['uuid' => $this->uuid]);
                $metadataService = new MetadataExtractor();
                $meta = $metadataService->extract($this->rawPath);

                $patientFile->update([
                    'duration' => $meta['duration'],
                    'resolution' => $meta['resolution'],
                    'processing_stage' => 'generating_thumbnail',
                    'processing_progress' => 45,
                ]);

                // 2. Generate Thumbnail & Preview GIF
                Log::info("VideoProcessingJob: generating thumbnail and preview gif", ['uuid' => $this->uuid]);
                $thumbnailGenerator = new ThumbnailGenerator();
                $thumbnailName = 'thumbnail.jpg';
                $previewName = 'preview.gif';
                
                $thumbnailGenerator->generate($this->rawPath, $hlsFolder . '/' . $thumbnailName);
                $thumbnailGenerator->generatePreview($this->rawPath, $hlsFolder . '/' . $previewName);

                $patientFile->update([
                    'thumbnail_path' => '/storage/patient_files/' . $this->uuid . '/' . $thumbnailName,
                    'processing_stage' => 'transcoding_hls',
                    'processing_progress' => 60,
                ]);

                // 3. Generate HLS Multi-resolutions
                Log::info("VideoProcessingJob: generating HLS", ['uuid' => $this->uuid]);
                $hlsGenerator = new HLSGenerator();
                $hlsSuccess = $hlsGenerator->generate($this->rawPath, $hlsFolder);

                if ($hlsSuccess) {
                    unlink($this->rawPath); // Remove original raw merged file
                    $patientFile->update([
                        'file_path' => '/storage/patient_files/' . $this->uuid . '/video.m3u8',
                        'upload_status' => 'ready',
                        'processing_stage' => 'completed',
                        'processing_progress' => 100,
                    ]);
                    Log::info("VideoProcessingJob: processing succeeded and HLS master playlist saved", ['uuid' => $this->uuid]);
                } else {
                    throw new \Exception("HLS generation failed to compile master playlist.");
                }

            } catch (\Throwable $e) {
                Log::error("VideoProcessingJob: error during video compilation", [
                    'uuid' => $this->uuid,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Fallback to serving raw MP4
                $patientFile->update([
                    'upload_status' => 'ready',
                    'processing_stage' => 'ready_fallback_mp4',
                    'processing_progress' => 100,
                ]);
            }
        } else {
            // Non-video files
            $patientFile->update([
                'upload_status' => 'ready',
                'processing_stage' => 'completed',
                'processing_progress' => 100,
            ]);
        }
    }
}
