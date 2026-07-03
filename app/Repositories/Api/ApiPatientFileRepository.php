<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Repositories\Api\Traits\MakesApiRequests;

class ApiPatientFileRepository implements PatientFileRepositoryInterface
{
    use MakesApiRequests;

    public function forPatient(string $patientUuid): array
    {
        return $this->apiCall('GET', '/patients/' . $patientUuid . '/files')->json() ?? [];
    }

    public function find(string $uuid): ?array
    {
        $response = $this->apiCall('GET', '/files/' . $uuid);
        if ($response->notFound()) return null;
        return $response->json() ?? [];
    }

    public function upload(string $patientUuid, array $file, array $data = []): array
    {
        return $this->apiCall('POST', '/patients/' . $patientUuid . '/files', array_merge($data, [
            'file' => $file,
        ]))->json() ?? [];
    }

    public function delete(string $uuid): void
    {
        $this->apiCall('DELETE', '/files/' . $uuid);
    }

    public function byCategory(string $patientUuid, string $categorySlug): array
    {
        $all = $this->forPatient($patientUuid);
        return array_values(array_filter($all, fn($f) => ($f['category'] ?? '') === $categorySlug));
    }
}
