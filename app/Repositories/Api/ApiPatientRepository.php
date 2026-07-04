<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\Api\Traits\MakesApiRequests;

class ApiPatientRepository implements PatientRepositoryInterface
{
    use MakesApiRequests;

    public function all(): array
    {
        $body = $this->apiCall('GET', '/patients', ['per_page' => 1000])->json() ?? [];
        return $body['data'] ?? $body['patients'] ?? $body ?? [];
    }

    public function find(string $uuid): ?array
    {
        $response = $this->apiCall('GET', '/patients/' . $uuid);
        if ($response->notFound()) return null;
        $body = $response->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function findByUuid(string $uuid): array
    {
        $result = $this->find($uuid);
        if (!$result) throw new \RuntimeException('Patient not found.');
        return $result;
    }

    public function create(array $data): array
    {
        $body = $this->apiCall('POST', '/patients', $data)->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function update(string $uuid, array $data): array
    {
        $body = $this->apiCall('PUT', '/patients/' . $uuid, $data)->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function delete(string $uuid): void
    {
        $this->apiCall('DELETE', '/patients/' . $uuid);
    }

    public function search(string $term): array
    {
        $body = $this->apiCall('GET', '/patients', ['search' => $term, 'per_page' => 1000])->json() ?? [];
        return $body['data'] ?? $body['patients'] ?? [];
    }

    public function shared(int $userId): array
    {
        $all = $this->all();
        return array_values(array_filter($all, fn($p) => ($p['primary_doctor_id'] ?? null) !== $userId));
    }

    public function stats(): array
    {
        return $this->apiCall('GET', '/dashboard/stats')->json() ?? [];
    }

    public function recent(int $limit): array
    {
        $body = $this->apiCall('GET', '/patients', ['per_page' => $limit])->json() ?? [];
        return $body['data'] ?? $body['patients'] ?? [];
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null): array
    {
        $params = ['per_page' => $perPage, 'page' => $page];
        if ($status) {
            $params['status'] = $status;
        }
        $body = $this->apiCall('GET', '/patients', $params)->json() ?? [];
        return $body;
    }

    public function withTrashed(): array
    {
        return $this->all();
    }

    public function restore(string $uuid): void {}

    public function forceDelete(string $uuid): void
    {
        $this->delete($uuid);
    }
}
