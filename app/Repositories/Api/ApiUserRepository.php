<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\UserRepositoryInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiUserRepository implements UserRepositoryInterface
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

    public function find(int $id): ?array
    {
        return $this->handleResponse($this->api()->get($this->baseUrl() . '/profile'));
    }

    public function update(int $id, array $data): array
    {
        return $this->handleResponse($this->api()->put($this->baseUrl() . '/profile', $data));
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->api()->put($this->baseUrl() . '/profile/password', ['password' => $password]);
    }

    public function updatePreferences(int $id, array $preferences): void
    {
        $this->api()->put($this->baseUrl() . '/profile/preferences', $preferences);
    }

    public function doctors(): array
    {
        $response = $this->api()->get($this->baseUrl() . '/doctors');
        return $this->handleResponse($response)['doctors'] ?? [];
    }

    public function searchDoctors(string $term): array
    {
        $response = $this->api()->get($this->baseUrl() . '/doctors/search', ['q' => $term]);
        return $this->handleResponse($response)['doctors'] ?? [];
    }
}
