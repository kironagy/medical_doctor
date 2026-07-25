<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Domains\Auth\Scopes\DoctorIsolationScope;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;

class EloquentPatientNoteRepository implements PatientNoteRepositoryInterface
{
    public function forPatient(string $patientUuid): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        // ── FIX: Bypass DoctorIsolationScope on PatientNote ────────────────
        // The patient was already validated as visible to the current user
        // (the workspace endpoint found it). ALL notes for this patient
        // should be returned, regardless of fine-grained note-level scoping.
        // Without this, the scope's whereHas('patient', ...) can filter out
        // notes when the patient has null primary_doctor_id/created_by_id
        // (common for offline-created patients), making them invisible.
        return $patient->notes()
            ->withoutGlobalScope(DoctorIsolationScope::class)
            ->with('author')
            ->latest()
            ->get()
            ->toArray();
    }

    public function create(string $patientUuid, array $data): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        $note = $patient->notes()->create($data);
        return $note->load('author')->toArray();
    }

    public function update(string $patientUuid, string $noteUuid, array $data): array
    {
        $note = PatientNote::where('uuid', $noteUuid)->firstOrFail();
        $note->update($data);
        return $note->fresh()->load('author')->toArray();
    }

    public function delete(string $patientUuid, string $noteUuid): void
    {
        PatientNote::where('uuid', $noteUuid)->firstOrFail()->delete();
    }
}
