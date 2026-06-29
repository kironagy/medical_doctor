<?php

namespace App\Jobs;

use App\Models\PatientFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MergeChunksJob implements ShouldQueue
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
        Log::info("MergeChunksJob: started merging process", ['uuid' => $this->uuid, 'total_chunks' => $this->totalChunks]);

        $tempDir = 'chunks/' . $this->uuid;
        $hlsFolder = storage_path('app/public/patient_files/' . $this->uuid);
        $finalName = 'video.' . $this->extension;
        $finalPath = $hlsFolder . '/' . $finalName;

        if (!file_exists($hlsFolder)) {
            mkdir($hlsFolder, 0777, true);
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

        Log::info("MergeChunksJob: chunks merged to raw file", ['path' => $finalPath]);

        $patientFile = PatientFile::where('uuid', $this->uuid)->first();
        if ($patientFile) {
            $patientFile->update([
                'file_path' => '/storage/patient_files/' . $this->uuid . '/' . $finalName,
                'upload_status' => 'uploaded',
                'processing_stage' => 'uploaded',
                'processing_progress' => 20,
            ]);

            Log::info("MergeChunksJob: status updated to 'uploaded', dispatching VideoProcessingJob", ['uuid' => $this->uuid]);
            VideoProcessingJob::dispatch($this->uuid, $finalPath, $this->extension);
        } else {
            if (file_exists($finalPath)) unlink($finalPath);
            Log::warning("MergeChunksJob: patient file record not found for uuid", ['uuid' => $this->uuid]);
        }
    }
}
