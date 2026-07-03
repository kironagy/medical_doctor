<?php

namespace App\Services\Mobile;

class VisitRepository
{
    public function __construct(
        private readonly ApiService $api
    ) {}

    public function all(string $patientUuid): array
    {
        return $this->api->get("/patients/{$patientUuid}/visits");
    }

    public function create(string $patientUuid, array $data): array
    {
        return $this->api->post("/patients/{$patientUuid}/visits", $data);
    }

    public function update(string $patientUuid, string $visitId, array $data): array
    {
        return $this->api->put("/patients/{$patientUuid}/visits/{$visitId}", $data);
    }

    public function delete(string $patientUuid, string $visitId): array
    {
        return $this->api->delete("/patients/{$patientUuid}/visits/{$visitId}");
    }
}
