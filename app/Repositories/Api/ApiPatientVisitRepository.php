<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Repositories\Api\Traits\MakesApiRequests;

class ApiPatientVisitRepository implements PatientVisitRepositoryInterface
{
    use MakesApiRequests;

    public function forPatient(string $patientUuid, ?string $updatedSince = null): array
    {
        $params = [];
        if ($updatedSince) {
            $params['updated_since'] = $updatedSince;
        }
        $body = $this->apiCall('GET', '/patients/' . $patientUuid . '/visits', $params)->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function create(string $patientUuid, array $data): array
    {
        return $this->apiCall('POST', '/patients/' . $patientUuid . '/visits', $data)->json() ?? [];
    }

    public function update(string $visitUuid, array $data): array
    {
        return $this->apiCall('PUT', '/visits/' . $visitUuid, $data)->json() ?? [];
    }

    public function delete(string $visitUuid): void
    {
        $this->apiCall('DELETE', '/visits/' . $visitUuid);
    }
}
