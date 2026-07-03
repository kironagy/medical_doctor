<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;

class EloquentPatientVisitRepository implements PatientVisitRepositoryInterface
{
    public function forPatient(string $patientUuid): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        return $patient->visits()->latest()->get()->toArray();
    }

    public function create(string $patientUuid, array $data): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        $visit = $patient->visits()->create($data);
        return $visit->toArray();
    }

    public function update(int $visitId, array $data): array
    {
        $visit = PatientVisit::findOrFail($visitId);
        $visit->update($data);
        return $visit->fresh()->toArray();
    }

    public function delete(int $visitId): void
    {
        PatientVisit::findOrFail($visitId)->delete();
    }
}
