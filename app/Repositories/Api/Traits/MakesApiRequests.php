<?php

namespace App\Repositories\Api\Traits;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

trait MakesApiRequests
{
    private function baseUrl(): string
    {
        return config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
    }

    private function apiCall(string $method, string $path, array $data = []): Response
    {
        $url = $this->baseUrl() . $path;
        $callLabel = strtoupper($method) . ' ' . $path;

        $response = $this->executeWithRetry($method, $url, $data, $callLabel);

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? 'Request failed.') : 'Request failed.';

            if ($response->status() === 422) {
                $errors = is_array($body) ? ($body['errors'] ?? []) : [];
                throw \Illuminate\Validation\ValidationException::withMessages($errors);
            }

            throw new RuntimeException($message);
        }

        return $response;
    }

    /**
     * Execute an HTTP request with basic retry on network failure.
     * No token refresh — the API service handles session management.
     * On 401, throws AuthenticationException so the frontend can prompt re-login.
     */
    private function executeWithRetry(string $method, string $url, array $data, string $callLabel): Response
    {
        $token = null;
        try {
            $token = app(\App\Services\Mobile\ApiService::class)->getToken();
        } catch (\Exception $e) {
            try {
                $token = session('api_token_raw');
            } catch (\Exception $se) {
                $token = null;
            }
        }

        $http = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));

        $response = $this->makeHttpCall($http, $method, $url, $data);
        $this->logApiCall($callLabel, $url, $method, $response, $token);

        if ($response->unauthorized()) {
            Log::warning('[MakesApiRequests] Received 401 — session expired.');
            session()->forget('api_token_raw');
            throw new \Illuminate\Auth\AuthenticationException('Session expired. Please login again.');
        }

        return $response;
    }

    private function makeHttpCall($http, string $method, string $url, array $data): Response
    {
        return match (strtoupper($method)) {
            'GET'    => $http->get($url, $data),
            'POST'   => $http->post($url, $data),
            'PUT'    => $http->put($url, $data),
            'DELETE' => $http->delete($url, $data),
            default  => throw new RuntimeException("Unsupported HTTP method: $method"),
        };
    }

    private function logApiCall(string $label, string $url, string $method, Response $response, ?string $token, bool $isRetry = false): void
    {
        if (str_contains($url, '/patients') && strtoupper($method) === 'GET' && $response->successful()) {
            $responseBody = $response->json();
            if (is_array($responseBody)) {
                Log::channel('single')->info('[PATIENT_DEBUG] API raw response for ' . $label, [
                    'path' => $url,
                    'body_keys' => array_keys($responseBody),
                    'total_in_data' => count($responseBody['data'] ?? []),
                    'total_in_patients' => count($responseBody['patients'] ?? []),
                    'meta' => $responseBody['meta'] ?? null,
                    'uuids_sample' => collect($responseBody['data'] ?? $responseBody['patients'] ?? [])
                        ->take(5)
                        ->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))
                        ->toArray(),
                ]);
            }
        }

        $context = $isRetry ? ' (retry)' : '';
        Log::debug(sprintf(
            '[API] %s%s | Status: %d | Token: %s',
            $label,
            $context,
            $response->status(),
            $token ? 'YES' : 'NO'
        ));
    }
}
