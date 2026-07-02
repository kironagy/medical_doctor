<?php

namespace App\Domains\Mobile\Services;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientShare;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Models\FileCategory;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\DB;

class DeltaSyncService
{
    public function upsertPatient(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            $patient = Patient::withTrashed()->where('uuid', $data['uuid'])->first();

            if ($patient) {
                if (!empty($data['_deleted']) || !empty($data['_sync_action']) && $data['_sync_action'] === 'deleted') {
                    if (!$patient->trashed()) $patient->delete();
                    $patient->touch();
                    return ['action' => 'updated', 'uuid' => $data['uuid'], 'status' => 'deleted'];
                }

                if (!empty($data['_sync_action']) && $data['_sync_action'] === 'restored' && $patient->trashed()) {
                    $patient->restore();
                }

                $serverUpdated = $patient->client_updated_at ?? $patient->updated_at;
                $clientUpdated = !empty($data['client_updated_at']) ? $data['client_updated_at'] : null;

                if ($serverUpdated && $clientUpdated && $serverUpdated->gt($clientUpdated)) {
                    return ['action' => 'conflict', 'uuid' => $data['uuid'], 'server_version' => $patient->toArray()];
                }

                $patient->update(array_filter([
                    'code' => $data['code'] ?? $patient->code,
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
                    'medical_record_number' => $data['medical_record_number'] ?? $patient->medical_record_number,
                    'client_updated_at' => $clientUpdated ?? now(),
                ]));

                return ['action' => 'updated', 'uuid' => $data['uuid']];
            }

            Patient::create([
                'uuid' => $data['uuid'],
                'primary_doctor_id' => $userId,
                'code' => $data['code'] ?? null,
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
                'medical_record_number' => $data['medical_record_number'] ?? null,
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
                if (!empty($data['_deleted']) || !empty($data['_sync_action']) && $data['_sync_action'] === 'deleted') {
                    if (!$visit->trashed()) $visit->delete();
                    return ['action' => 'updated', 'uuid' => $data['uuid'], 'status' => 'deleted'];
                }

                if (!empty($data['_sync_action']) && $data['_sync_action'] === 'restored' && $visit->trashed()) {
                    $visit->restore();
                }

                $visit->update(array_filter([
                    'visit_type' => $data['visit_type'] ?? $visit->visit_type,
                    'visit_type_custom' => $data['visit_type_custom'] ?? $visit->visit_type_custom,
                    'reason' => $data['reason'] ?? $visit->reason,
                    'reason_custom' => $data['reason_custom'] ?? $visit->reason_custom,
                    'visit_date' => $data['visit_date'] ?? $visit->visit_date,
                    'visit_time' => $data['visit_time'] ?? $visit->visit_time,
                    'session_details' => $data['session_details'] ?? $visit->session_details,
                    'diagnosis' => $data['diagnosis'] ?? $visit->diagnosis,
                    'prescription' => $data['prescription'] ?? $visit->prescription,
                    'next_visit_date' => $data['next_visit_date'] ?? $visit->next_visit_date,
                    'cost' => $data['cost'] ?? $visit->cost,
                    'client_updated_at' => $data['client_updated_at'] ?? now(),
                ]));

                return ['action' => 'updated', 'uuid' => $data['uuid']];
            }

            PatientVisit::create([
                'uuid' => $data['uuid'],
                'patient_id' => $patient->id,
                'visit_type' => $data['visit_type'] ?? null,
                'visit_type_custom' => $data['visit_type_custom'] ?? null,
                'reason' => $data['reason'] ?? null,
                'reason_custom' => $data['reason_custom'] ?? null,
                'visit_date' => $data['visit_date'] ?? null,
                'visit_time' => $data['visit_time'] ?? null,
                'session_details' => $data['session_details'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'prescription' => $data['prescription'] ?? null,
                'next_visit_date' => $data['next_visit_date'] ?? null,
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

            $note = PatientNote::withTrashed()->where('uuid', $data['uuid'])->first();

            if ($note) {
                if (!empty($data['_deleted']) || !empty($data['_sync_action']) && $data['_sync_action'] === 'deleted') {
                    if (!$note->trashed()) $note->delete();
                    return ['action' => 'updated', 'uuid' => $data['uuid'], 'status' => 'deleted'];
                }

                if (!empty($data['_sync_action']) && $data['_sync_action'] === 'restored' && $note->trashed()) {
                    $note->restore();
                }

                $note->update([
                    'category' => $data['category'] ?? $note->category,
                    'content' => $data['content'] ?? $note->content,
                    'client_updated_at' => $data['client_updated_at'] ?? now(),
                ]);

                return ['action' => 'updated', 'uuid' => $data['uuid']];
            }

            PatientNote::create([
                'uuid' => $data['uuid'],
                'patient_id' => $patient->id,
                'author_id' => $data['author_id'] ?? null,
                'category' => $data['category'] ?? 'general',
                'content' => $data['content'] ?? '',
                'client_updated_at' => $data['client_updated_at'] ?? now(),
            ]);

            return ['action' => 'created', 'uuid' => $data['uuid']];
        });
    }

    public function upsertShare(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $patient = Patient::where('uuid', $data['patient_uuid'])->first();
            if (!$patient) {
                return ['action' => 'error', 'error' => 'Patient not found', 'patient_uuid' => $data['patient_uuid']];
            }

            $doctor = User::where('uuid', $data['doctor_uuid'])->first();
            if (!$doctor) {
                return ['action' => 'error', 'error' => 'Doctor not found', 'doctor_uuid' => $data['doctor_uuid']];
            }

            $share = PatientShare::withTrashed()->where('uuid', $data['uuid'])->first();

            if ($share) {
                if (!empty($data['_deleted']) || !empty($data['_sync_action']) && $data['_sync_action'] === 'deleted') {
                    if (!$share->trashed()) $share->delete();
                    return ['action' => 'updated', 'uuid' => $data['uuid'], 'status' => 'deleted'];
                }

                $share->update([
                    'access_level' => $data['access_level'] ?? $share->access_level,
                    'expires_at' => $data['expires_at'] ?? $share->expires_at,
                    'client_updated_at' => $data['client_updated_at'] ?? now(),
                ]);

                return ['action' => 'updated', 'uuid' => $data['uuid']];
            }

            PatientShare::create([
                'uuid' => $data['uuid'],
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'shared_by_id' => !empty($data['shared_by_uuid']) ? User::where('uuid', $data['shared_by_uuid'])->value('id') : null,
                'access_level' => $data['access_level'] ?? 'read',
                'expires_at' => $data['expires_at'] ?? null,
                'client_updated_at' => $data['client_updated_at'] ?? now(),
            ]);

            return ['action' => 'created', 'uuid' => $data['uuid']];
        });
    }
}
