<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;

class EloquentPatientNoteRepository implements PatientNoteRepositoryInterface
{
    public function forPatient(string $patientUuid): array
    {
        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) {
            return [];
        }
        return $patient->notes()->with('author')->latest()->get()->toArray();
    }

    public function create(string $patientUuid, array $data): array
    {
        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) {
            throw new \RuntimeException('Patient not found locally: ' . $patientUuid);
        }
        $note = $patient->notes()->create($data);
        return $note->load('author')->toArray();
    }

    public function update(string $patientUuid, string $noteUuid, array $data): array
    {
        $note = PatientNote::where('uuid', $noteUuid)->first();
        if (!$note) {
            throw new \RuntimeException('Note not found: ' . $noteUuid);
        }
        $note->update($data);
        return $note->fresh()->load('author')->toArray();
    }

    public function delete(string $patientUuid, string $noteUuid): void
    {
        $note = PatientNote::where('uuid', $noteUuid)->first();
        if ($note) {
            $note->delete();
        }
    }
}
