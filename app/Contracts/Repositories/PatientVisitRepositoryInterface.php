<?php

namespace App\Contracts\Repositories;

interface PatientVisitRepositoryInterface
{
    public function forPatient(string $patientUuid): array;
    public function create(string $patientUuid, array $data): array;
    public function update(int $visitId, array $data): array;
    public function delete(int $visitId): void;
}
