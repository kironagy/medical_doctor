<?php

namespace App\Contracts\Repositories;

interface PatientFileRepositoryInterface
{
    public function forPatient(string $patientUuid, ?int $limit = null): array;
    public function countForPatient(string $patientUuid): int;
    public function find(string $uuid): ?array;
    public function upload(string $patientUuid, array $file, array $data = []): array;
    public function delete(string $uuid): void;
    public function byCategory(string $patientUuid, string $categorySlug): array;
}
