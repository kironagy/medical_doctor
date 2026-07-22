<?php

namespace App\Contracts\Repositories;

interface PatientVisitRepositoryInterface
{
    public function forPatient(string $patientUuid): array;
    public function create(string $patientUuid, array $data): array;
    public function update(string $visitUuid, array $data): array;
    public function delete(string $visitUuid): void;
}
