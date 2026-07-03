<?php

namespace App\Contracts\Repositories;

interface PatientNoteRepositoryInterface
{
    public function forPatient(string $patientUuid): array;
    public function create(string $patientUuid, array $data): array;
    public function update(string $patientUuid, string $noteUuid, array $data): array;
    public function delete(string $patientUuid, string $noteUuid): void;
}
