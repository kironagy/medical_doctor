<?php

namespace App\Services\Mobile;

class NoteRepository
{
    public function __construct(
        private readonly ApiService $api
    ) {}

    public function all(string $patientUuid): array
    {
        return $this->api->get("/patients/{$patientUuid}/notes");
    }

    public function create(string $patientUuid, array $data): array
    {
        return $this->api->post("/patients/{$patientUuid}/notes", $data);
    }

    public function update(string $patientUuid, string $noteUuid, array $data): array
    {
        return $this->api->put("/patients/{$patientUuid}/notes/{$noteUuid}", $data);
    }

    public function delete(string $patientUuid, string $noteUuid): array
    {
        return $this->api->delete("/patients/{$patientUuid}/notes/{$noteUuid}");
    }
}
