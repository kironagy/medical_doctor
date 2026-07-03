<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientRepositoryInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ApiPatientRepository implements PatientRepositoryInterface
{
    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        $token = session('api_token');
        return Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));
    }

    private function baseUrl(): string
    {
        return config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
    }

    private function handleResponse($response): array
    {
        if ($response->unauthorized()) {
            session()->forget('api_token');
            throw new RuntimeException('Session expired. Please login again.');
        }
        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? 'Request failed.') : 'Request failed.';
            throw new RuntimeException($message);
        }
        return $response->json() ?? [];
    }

    public function all(): array
    {
        $response = $this->api()->get($this->baseUrl() . '/patients', ['per_page' => 1000]);
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body['patients'] ?? $body ?? [];
    }

    public function find(string $uuid): ?array
    {
        $response = $this->api()->get($this->baseUrl() . '/patients/' . $uuid);
        if ($response->notFound()) return null;
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body;
    }

    public function findByUuid(string $uuid): array
    {
        $result = $this->find($uuid);
        if (!$result) throw new RuntimeException('Patient not found.');
        return $result;
    }

    public function create(array $data): array
    {
        $response = $this->api()->post($this->baseUrl() . '/patients', $data);
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body;
    }

    public function update(string $uuid, array $data): array
    {
        $response = $this->api()->put($this->baseUrl() . '/patients/' . $uuid, $data);
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body;
    }

    public function delete(string $uuid): void
    {
        $this->api()->delete($this->baseUrl() . '/patients/' . $uuid);
    }

    public function search(string $term): array
    {
        $response = $this->api()->get($this->baseUrl() . '/patients', ['search' => $term, 'per_page' => 1000]);
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body['patients'] ?? [];
    }

    public function shared(int $userId): array
    {
        $all = $this->all();
        return array_values(array_filter($all, fn($p) => ($p['primary_doctor_id'] ?? null) !== $userId));
    }

    public function stats(): array
    {
        $response = $this->api()->get($this->baseUrl() . '/dashboard/stats');
        return $this->handleResponse($response);
    }

    public function recent(int $limit): array
    {
        $response = $this->api()->get($this->baseUrl() . '/patients', ['per_page' => $limit]);
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body['patients'] ?? [];
    }

    public function withTrashed(): array
    {
        return $this->all();
    }

    public function restore(string $uuid): void
    {
    }

    public function forceDelete(string $uuid): void
    {
        $this->delete($uuid);
    }
}
