<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Patients\Models\Patient;

class EloquentPatientFileRepository implements PatientFileRepositoryInterface
{
    public function forPatient(string $patientUuid): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        return $patient->files()->latest()->get()->toArray();
    }

    public function find(string $uuid): ?array
    {
        $file = PatientFile::where('uuid', $uuid)->first();
        return $file?->toArray();
    }

    public function upload(string $patientUuid, array $file, array $data = []): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        $file = $patient->files()->create($data);
        return $file->toArray();
    }

    public function delete(string $uuid): void
    {
        PatientFile::where('uuid', $uuid)->firstOrFail()->delete();
    }

    public function byCategory(string $patientUuid, string $categorySlug): array
    {
        $patient = Patient::where('uuid', $patientUuid)->firstOrFail();
        return $patient->files()
            ->where('category', $categorySlug)
            ->latest()
            ->get()
            ->toArray();
    }
}
