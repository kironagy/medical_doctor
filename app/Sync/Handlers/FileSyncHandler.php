<?php

namespace App\Sync\Handlers;

use App\Models\PatientFile;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FileSyncHandler extends BaseSyncHandler
{
    protected string $modelClass = PatientFile::class;

    /**
     * Validate file payload.
     */
    public function validate(array $payload, string $operation, ?Model $model = null): array
    {
        $rules = [
            'uuid' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'desc' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'data' => ['nullable', 'string'],
            'client_updated_at' => ['nullable', 'string'],
        ];

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        // Verify patient exists
        $patientUuid = $payload['patient_uuid'] ?? null;
        if ($patientUuid) {
            $patientExists = Patient::where('uuid', $patientUuid)->exists();
            if (!$patientExists) {
                return ['patient_uuid' => ["The referenced patient with UUID [{$patientUuid}] does not exist."]];
            }
        } else {
            $patientId = $payload['patient_id'] ?? null;
            if (!$patientId) {
                return ['patient_uuid' => ["Referenced patient is required (missing patient_uuid or patient_id)."]];
            }
            $patientExists = Patient::where('id', $patientId)->exists();
            if (!$patientExists) {
                return ['patient_id' => ["The referenced patient ID [{$patientId}] does not exist."]];
            }
        }

        return [];
    }

    /**
     * Transform file payload, resolving references and handling base64 uploads safely.
     */
    public function transform(array $payload, string $operation): array
    {
        $cleaned = $this->cleanPayload($payload);

        // Resolve patient UUID
        $patientUuid = $payload['patient_uuid'] ?? null;
        if ($patientUuid) {
            $patient = Patient::where('uuid', $patientUuid)->first();
            if ($patient) {
                $cleaned['patient_id'] = $patient->id;
            } else {
                $cleaned['patient_id'] = null;
            }
        }
        unset($cleaned['patient_uuid']);

        // Default file_name if empty
        if (empty($cleaned['file_name'])) {
            if (!empty($cleaned['file_path'])) {
                $cleaned['file_name'] = basename($cleaned['file_path']);
            } else {
                $cleaned['file_name'] = 'file_' . Str::random(8) . '.bin';
            }
        }

        // Handle Base64 file decoding and storage safely
        if (!empty($payload['data'])) {
            try {
                $fileData = base64_decode($payload['data']);
                if ($fileData !== false) {
                    $extension = pathinfo($cleaned['file_name'], PATHINFO_EXTENSION) ?: 'bin';
                    $finalName = Str::random(40) . '.' . $extension;
                    $finalPath = storage_path('app/public/patient_files/' . $finalName);

                    if (!file_exists(dirname($finalPath))) {
                        mkdir(dirname($finalPath), 0777, true);
                    }

                    file_put_contents($finalPath, $fileData);
                    $cleaned['file_path'] = '/storage/patient_files/' . $finalName;
                }
            } catch (\Throwable $e) {
                Log::error("Failed to decode base64 file content during synchronization: " . $e->getMessage(), [
                    'uuid' => $payload['uuid'] ?? null,
                    'file_name' => $cleaned['file_name']
                ]);
            }
        }

        // Always clear data from database to save space
        $cleaned['data'] = null;

        return $cleaned;
    }
}
