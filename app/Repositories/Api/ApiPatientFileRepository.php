<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiPatientFileRepository implements PatientFileRepositoryInterface
{
    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        $token = session('api_token');
        return Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
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
        $response = $this->api()->get($this->baseUrl() . '/patients/' . $patientUuid . '/files');
        return $this->handleResponse($response);
    }

    public function find(string $uuid): ?array
    {
        $response = $this->api()->get($this->baseUrl() . '/files/' . $uuid);
        if ($response->notFound()) return null;
        return $this->handleResponse($response);
    }

    public function upload(string $patientUuid, array $file, array $data = []): array
    {
        $response = $this->api()->post($this->baseUrl() . '/patients/' . $patientUuid . '/files', array_merge($data, [
            'file' => $file,
        ]));
        return $this->handleResponse($response);
    }

    public function delete(string $uuid): void
    {
        $this->api()->delete($this->baseUrl() . '/files/' . $uuid);
    }

    public function byCategory(string $patientUuid, string $categorySlug): array
    {
        $all = $this->forPatient($patientUuid);
        return array_values(array_filter($all, fn($f) => ($f['category'] ?? '') === $categorySlug));
    }
}
