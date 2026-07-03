<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\Api\Traits\MakesApiRequests;

class ApiUserRepository implements UserRepositoryInterface
{
    use MakesApiRequests;

    public function find(int $id): ?array
    {
        return $this->apiCall('GET', '/profile')->json() ?? [];
    }

    public function update(int $id, array $data): array
    {
        return $this->apiCall('PUT', '/profile', $data)->json() ?? [];
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->apiCall('PUT', '/profile/password', ['password' => $password]);
    }

    public function updatePreferences(int $id, array $preferences): void
    {
        $this->apiCall('PUT', '/profile/preferences', $preferences);
    }

    public function doctors(): array
    {
        return $this->apiCall('GET', '/doctors')->json()['doctors'] ?? [];
    }

    public function searchDoctors(string $term): array
    {
        return $this->apiCall('GET', '/doctors/search', ['q' => $term])->json()['doctors'] ?? [];
    }
}
