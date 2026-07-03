<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiPatientNoteRepository implements PatientNoteRepositoryInterface
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
        $response = $this->api()->get($this->baseUrl() . '/patients/' . $patientUuid . '/notes');
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body;
    }

    public function create(string $patientUuid, array $data): array
    {
        $response = $this->api()->post($this->baseUrl() . '/patients/' . $patientUuid . '/notes', $data);
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body;
    }

    public function update(string $patientUuid, string $noteUuid, array $data): array
    {
        $response = $this->api()->put($this->baseUrl() . '/patients/' . $patientUuid . '/notes/' . $noteUuid, $data);
        $body = $this->handleResponse($response);
        return $body['data'] ?? $body;
    }

    public function delete(string $patientUuid, string $noteUuid): void
    {
        $this->api()->delete($this->baseUrl() . '/patients/' . $patientUuid . '/notes/' . $noteUuid);
    }
}
