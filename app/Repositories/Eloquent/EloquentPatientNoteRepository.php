<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;

class EloquentPatientNoteRepository implements PatientNoteRepositoryInterface
{
    public function forPatient(string $patientUuid): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        return $patient->notes()->with('author')->latest()->get()->toArray();
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
