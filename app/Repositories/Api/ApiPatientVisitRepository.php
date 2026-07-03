<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiPatientVisitRepository implements PatientVisitRepositoryInterface
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

    public function forPatient(string $patientUuid): array
    {
        $response = $this->api()->get($this->baseUrl() . '/patients/' . $patientUuid . '/visits');
        return $this->handleResponse($response)['visits'] ?? [];
    }

    public function create(string $patientUuid, array $data): array
    {
        $response = $this->api()->post($this->baseUrl() . '/patients/' . $patientUuid . '/visits', $data);
        return $this->handleResponse($response);
    }

    public function update(int $visitId, array $data): array
    {
        $response = $this->api()->put($this->baseUrl() . '/visits/' . $visitId, $data);
        return $this->handleResponse($response);
    }

    public function delete(int $visitId): void
    {
        $this->api()->delete($this->baseUrl() . '/visits/' . $visitId);
    }
}
