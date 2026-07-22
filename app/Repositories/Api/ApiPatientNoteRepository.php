<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Repositories\Api\Traits\MakesApiRequests;

class ApiPatientNoteRepository implements PatientNoteRepositoryInterface
{
    use MakesApiRequests;

    public function forPatient(string $patientUuid, ?string $updatedSince = null): array
    {
        $params = [];
        if ($updatedSince) {
            $params['updated_since'] = $updatedSince;
        }
        $body = $this->apiCall('GET', '/patients/' . $patientUuid . '/notes', $params)->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function create(string $patientUuid, array $data): array
    {
        $body = $this->apiCall('POST', '/patients/' . $patientUuid . '/notes', $data)->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function update(string $patientUuid, string $noteUuid, array $data): array
    {
        $body = $this->apiCall('PUT', '/patients/' . $patientUuid . '/notes/' . $noteUuid, $data)->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function delete(string $patientUuid, string $noteUuid): void
    {
        $this->apiCall('DELETE', '/patients/' . $patientUuid . '/notes/' . $noteUuid);
    }
}
