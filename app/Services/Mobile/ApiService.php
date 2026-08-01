<?php

namespace App\Services\Mobile;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * ApiService — Compatibility Proxy delegating strictly to RemoteApiService
 * ───────────────────────────────────────────────────────────────────────────
 *
 * All remote HTTP requests are centralized inside RemoteApiService.
 * This class acts purely as a wrapper to delegate methods to RemoteApiService.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ApiService
{
    public function __construct(
        private readonly RemoteApiService $remoteApi
    ) {}

    public function setToken(?string $token): void
    {
        $this->remoteApi->setToken($token);
    }

    public function getToken(): ?string
    {
        return $this->remoteApi->getToken();
    }

    public function get(string $endpoint, array $query = []): array
    {
        return $this->remoteApi->get($endpoint, $query);
    }

    public function post(string $endpoint, array $data = []): array
    {
        return $this->remoteApi->post($endpoint, $data);
    }

    public function put(string $endpoint, array $data = []): array
    {
        return $this->remoteApi->put($endpoint, $data);
    }

    public function delete(string $endpoint): array
    {
        return $this->remoteApi->delete($endpoint);
    }

    public function upload(string $endpoint, array $files, array $data = []): array
    {
        return $this->remoteApi->upload($endpoint, $files, $data);
    }
}
