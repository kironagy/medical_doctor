<?php

namespace App\Domains\Mobile\Services;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Patients\Models\PatientNote;
use Illuminate\Support\Facades\DB;

class DeltaSyncService
{
    public function upsertPatient(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            $patient = Patient::withTrashed()->where('uuid', $data['uuid'])->first();

            if ($patient) {
                if (!empty($data['_deleted'])) {
                    if (!$patient->trashed()) $patient->delete();
                    return ['action' => 'updated', 'uuid' => $data['uuid'], 'status' => 'deleted'];
                }

                $serverUpdated = $patient->client_updated_at ?? $patient->updated_at;
                $clientUpdated = $data['client_updated_at'] ?? null;

                if ($serverUpdated && $clientUpdated && $serverUpdated->gt($clientUpdated)) {
                    return ['action' => 'conflict', 'uuid' => $data['uuid'], 'server_version' => $patient->toArray()];
                }

                $patient->update(array_filter([
                    'name' => $data['name'] ?? $patient->name,
                    'phone' => $data['phone'] ?? $patient->phone,
                    'email' => $data['email'] ?? $patient->email,
                    'address' => $data['address'] ?? $patient->address,
                    'date_of_birth' => $data['date_of_birth'] ?? $patient->date_of_birth,
                    'gender' => $data['gender'] ?? $patient->gender,
                    'blood_group' => $data['blood_group'] ?? $patient->blood_group,
                    'weight' => $data['weight'] ?? $patient->weight,
                    'height' => $data['height'] ?? $patient->height,
                    'allergies' => $data['allergies'] ?? $patient->allergies,
                    'chronic_diseases' => $data['chronic_diseases'] ?? $patient->chronic_diseases,
                    'medical_status' => $data['medical_status'] ?? $patient->medical_status,
                    'diagnosis' => $data['diagnosis'] ?? $patient->diagnosis,
                    'client_updated_at' => $clientUpdated ?? now(),
                ]));

                return ['action' => 'updated', 'uuid' => $data['uuid']];
            }

            Patient::create([
                'uuid' => $data['uuid'],
                'primary_doctor_id' => $userId,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'blood_group' => $data['blood_group'] ?? null,
                'weight' => $data['weight'] ?? null,
                'height' => $data['height'] ?? null,
                'allergies' => $data['allergies'] ?? null,
                'chronic_diseases' => $data['chronic_diseases'] ?? null,
                'medical_status' => $data['medical_status'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'client_updated_at' => $data['client_updated_at'] ?? now(),
            ]);

            return ['action' => 'created', 'uuid' => $data['uuid']];
        });
    }

    public function upsertVisit(int $userId, array $data): array
    {
        return DB::transaction(function () use ($data) {
            $patient = Patient::where('uuid', $data['patient_uuid'])->first();
            if (!$patient) {
                return ['action' => 'error', 'error' => 'Patient not found', 'patient_uuid' => $data['patient_uuid']];
            }

            $visit = PatientVisit::withTrashed()->where('uuid', $data['uuid'])->first();

            if ($visit) {
                if (!empty($data['_deleted'])) {
                    if (!$visit->trashed()) $visit->delete();
                    return ['action' => 'updated', 'uuid' => $data['uuid'], 'status' => 'deleted'];
                }

                $visit->update(array_filter([
                    'visit_type' => $data['visit_type'] ?? $visit->visit_type,
                    'reason' => $data['reason'] ?? $visit->reason,
                    'visit_date' => $data['visit_date'] ?? $visit->visit_date,
                    'diagnosis' => $data['diagnosis'] ?? $visit->diagnosis,
                    'prescription' => $data['prescription'] ?? $visit->prescription,
                    'cost' => $data['cost'] ?? $visit->cost,
                    'client_updated_at' => $data['client_updated_at'] ?? now(),
                ]));

                return ['action' => 'updated', 'uuid' => $data['uuid']];
            }

            PatientVisit::create([
                'uuid' => $data['uuid'],
                'patient_id' => $patient->id,
                'visit_type' => $data['visit_type'] ?? null,
                'reason' => $data['reason'] ?? null,
                'visit_date' => $data['visit_date'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'prescription' => $data['prescription'] ?? null,
                'cost' => $data['cost'] ?? null,
                'client_updated_at' => $data['client_updated_at'] ?? now(),
            ]);

            return ['action' => 'created', 'uuid' => $data['uuid']];
        });
    }

    public function upsertNote(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $patient = Patient::where('uuid', $data['patient_uuid'])->first();
            if (!$patient) {
                return ['action' => 'error', 'error' => 'Patient not found', 'patient_uuid' => $data['patient_uuid']];
            }

            $note = PatientNote::where('uuid', $data['uuid'])->first();

            if ($note) {
                $note->update([
                    'content' => $data['content'] ?? $note->content,
                    'client_updated_at' => $data['client_updated_at'] ?? now(),
                ]);
                return ['action' => 'updated', 'uuid' => $data['uuid']];
            }

            PatientNote::create([
                'uuid' => $data['uuid'],
                'patient_id' => $patient->id,
                'content' => $data['content'] ?? '',
                'client_updated_at' => $data['client_updated_at'] ?? now(),
            ]);

            return ['action' => 'created', 'uuid' => $data['uuid']];
        });
    }
}
