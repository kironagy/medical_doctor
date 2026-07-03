<?php

namespace App\Services\Mobile;

use RuntimeException;

class PatientRepository
{
    public function __construct(
        private readonly ApiService $api
    ) {}

    public function all(int $page = 1, int $perPage = 20, ?string $search = null): array
    {
        $params = ['page' => $page, 'per_page' => $perPage];
        if ($search) {
            $params['search'] = $search;
        }
        return $this->api->get('/patients', $params);
    }

    public function find(string $uuid): array
    {
        return $this->api->get("/patients/{$uuid}");
    }

    public function create(array $data): array
    {
        return $this->api->post('/patients', $data);
    }

    public function update(string $uuid, array $data): array
    {
        return $this->api->put("/patients/{$uuid}", $data);
    }

    public function delete(string $uuid): array
    {
        return $this->api->delete("/patients/{$uuid}");
    }

    public function recent(int $limit = 10): array
    {
        return $this->all(1, $limit);
    }
}
