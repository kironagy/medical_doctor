<?php

namespace App\Jobs;

use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Jobs\GenerateThumbnailJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessUploadedFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(private readonly int $patientFileId) {}

    public function handle(): void
    {
        $file = PatientFile::find($this->patientFileId);
        if (!$file) return;

        if ($file->type === 'video') {
            GenerateThumbnailJob::dispatch($file->id);
        }
    }
}
