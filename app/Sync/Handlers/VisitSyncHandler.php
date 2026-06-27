<?php

namespace App\Sync\Handlers;

use App\Models\PatientVisit;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class VisitSyncHandler extends BaseSyncHandler
{
    protected string $modelClass = PatientVisit::class;

    /**
     * Validate visit payload, verifying foreign key existence.
     */
    public function validate(array $payload, string $operation, ?Model $model = null): array
    {
        if (strtolower($operation) === 'delete') {
            $validator = Validator::make($payload, [
                'uuid' => ['required', 'string'],
            ]);
            return $validator->fails() ? $validator->errors()->toArray() : [];
        }

        $rules = [
            'uuid' => ['required', 'string'],
            'visit_type' => ['required', 'string', 'max:255'],
            'visit_type_custom' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'reason_custom' => ['nullable', 'string', 'max:255'],
            'visit_date' => ['required', 'date'],
            'visit_time' => ['nullable', 'string'],
            'session_details' => ['nullable'],
            'diagnosis' => ['nullable', 'string'],
            'prescription' => ['nullable', 'string'],
            'next_visit_date' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'client_updated_at' => ['nullable', 'string'],
        ];

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        // Verify foreign key: Patient must exist
        $patientUuid = $payload['patient_uuid'] ?? null;
        if ($patientUuid) {
            $patientExists = Patient::where('uuid', $patientUuid)->exists();
            if (!$patientExists) {
                return ['patient_uuid' => ["The referenced patient with UUID [{$patientUuid}] does not exist in the database."]];
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
     * Transform visit payload, mapping patient_uuid to local patient_id.
     */
    public function transform(array $payload, string $operation): array
    {
        $cleaned = $this->cleanPayload($payload);

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

        // Cast session_details if it is string
        if (isset($cleaned['session_details']) && is_string($cleaned['session_details'])) {
            $decoded = json_decode($cleaned['session_details'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $cleaned['session_details'] = $decoded;
            }
        }

        return $cleaned;
    }
}
