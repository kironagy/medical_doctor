<?php

namespace App\Observers;

use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Jobs\OptimizeVideoForStreaming;

class PatientFileObserver
{
    public function created(PatientFile $file)
    {
        if (str_starts_with($file->mime_type, 'video/')) {
            OptimizeVideoForStreaming::dispatch($file->id);
        }
    }
}
