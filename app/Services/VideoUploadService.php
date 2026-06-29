<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientFile;
use Illuminate\Support\Str;

class VideoUploadService
{
    public function initialize(Patient $patient, array $data): PatientFile
    {
        $data['patient_id'] = $patient->id;
        $data['uuid'] = $data['uuid'] ?? Str::uuid()->toString();
        $data['upload_status'] = 'uploading';
        $data['processing_stage'] = 'uploading';
        $data['processing_progress'] = 0;

        if (empty($data['file_name'])) {
            $data['file_name'] = 'uploading...' . ($data['type'] === 'video' ? '.mp4' : '');
        }

        return PatientFile::create($data);
    }
}
