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
use Illuminate\Support\Facades\Storage;

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
        Log::info("VideoProcessingJob: starting processing", [
            'uuid' => $this->uuid,
            'original_mp4_path' => $this->rawPath
        ]);

        $patientFile = PatientFile::where('uuid', $this->uuid)->first();
        if (!$patientFile) {
            Log::warning("VideoProcessingJob: patient file record not found for uuid", ['uuid' => $this->uuid]);
            if (file_exists($this->rawPath)) {
                unlink($this->rawPath);
            }
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
                
                $thumbOutputPath = $hlsFolder . '/' . $thumbnailName;
                $thumbSuccess = $thumbnailGenerator->generate($this->rawPath, $thumbOutputPath);
                
                $previewOutputPath = $hlsFolder . '/' . $previewName;
                $thumbnailGenerator->generatePreview($this->rawPath, $previewOutputPath);

                $relativeThumbDiskPath = 'patient_files/' . $this->uuid . '/' . $thumbnailName;
                
                // Validate thumbnail exists on disk before updating the database
                if ($thumbSuccess && Storage::disk('public')->exists($relativeThumbDiskPath)) {
                    Log::info("VideoProcessingJob: Thumbnail generated successfully on disk", ['path' => $relativeThumbDiskPath]);
                    $patientFile->update([
                        'thumbnail_path' => '/storage/' . $relativeThumbDiskPath,
                        'processing_stage' => 'transcoding_hls',
                        'processing_progress' => 60,
                    ]);
                } else {
                    Log::warning("VideoProcessingJob: Thumbnail file was not generated or missing on disk", ['path' => $relativeThumbDiskPath]);
                    $patientFile->update([
                        'processing_stage' => 'transcoding_hls',
                        'processing_progress' => 60,
                    ]);
                }

                // 3. Generate HLS optimized stream
                Log::info("VideoProcessingJob: generating HLS", ['uuid' => $this->uuid]);
                $hlsGenerator = new HLSGenerator();
                $hlsSuccess = $hlsGenerator->generate($this->rawPath, $hlsFolder);

                $relativeHlsDiskPath = 'patient_files/' . $this->uuid . '/video.m3u8';

                // Validate HLS playlist exists on disk before finalizing
                if ($hlsSuccess && Storage::disk('public')->exists($relativeHlsDiskPath)) {
                    Log::info("VideoProcessingJob: HLS playlist video.m3u8 generated successfully", ['path' => $relativeHlsDiskPath]);
                    
                    // We DO NOT unlink the raw merged video.mp4, keeping it in the same directory as the master playlist, chunks, and cover images.
                    $patientFile->update([
                        'file_path' => '/storage/' . $relativeHlsDiskPath,
                        'upload_status' => 'ready',
                        'processing_stage' => 'completed',
                        'processing_progress' => 100,
                    ]);
                    Log::info("VideoProcessingJob: processing finished successfully", ['uuid' => $this->uuid]);
                } else {
                    throw new \Exception("HLS generation failed to compile video.m3u8.");
                }

            } catch (\Throwable $e) {
                Log::error("VideoProcessingJob: exception occurred during video compilation", [
                    'uuid' => $this->uuid,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Fallback to serving raw MP4 if HLS fails
                $relativeRawDiskPath = 'patient_files/' . $this->uuid . '/video.' . $this->extension;
                if (Storage::disk('public')->exists($relativeRawDiskPath)) {
                    $patientFile->update([
                        'file_path' => '/storage/' . $relativeRawDiskPath,
                        'upload_status' => 'ready',
                        'processing_stage' => 'ready_fallback_mp4',
                        'processing_progress' => 100,
                    ]);
                } else {
                    $patientFile->update([
                        'upload_status' => 'failed',
                        'processing_stage' => 'failed',
                        'processing_progress' => 100,
                    ]);
                }
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
