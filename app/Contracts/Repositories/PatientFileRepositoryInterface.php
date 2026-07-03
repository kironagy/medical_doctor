<?php

namespace App\Contracts\Repositories;

interface PatientFileRepositoryInterface
{
    public function forPatient(string $patientUuid): array;
    public function find(string $uuid): ?array;
    public function upload(string $patientUuid, array $file, array $data = []): array;
    public function delete(string $uuid): void;
    public function byCategory(string $patientUuid, string $categorySlug): array;
}
