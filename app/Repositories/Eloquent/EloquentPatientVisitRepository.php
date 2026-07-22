<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientVisit;

class EloquentPatientVisitRepository implements PatientVisitRepositoryInterface
{
    public function forPatient(string $patientUuid): array
    {
        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) {
            return [];
        }
        return $patient->visits()->latest()->get()->toArray();
    }

    public function create(string $patientUuid, array $data): array
    {
        $patient = Patient::where('uuid', $patientUuid)->first();
        if (!$patient) {
            throw new \RuntimeException('Patient not found locally: ' . $patientUuid);
        }
        $visit = $patient->visits()->create($data);
        return $visit->toArray();
    }

    public function update(string $visitUuid, array $data): array
    {
        $visit = PatientVisit::where('uuid', $visitUuid)->first();
        if (!$visit) {
            throw new \RuntimeException('Visit not found: ' . $visitUuid);
        }
        $visit->update($data);
        return $visit->fresh()->toArray();
    }

    public function delete(string $visitUuid): void
    {
        $visit = PatientVisit::where('uuid', $visitUuid)->first();
        if ($visit) {
            $visit->delete();
        }
    }
}
