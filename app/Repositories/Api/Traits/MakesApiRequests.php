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

        // Attempt the API call, with automatic token refresh + retry on 401
        $response = $this->executeWithRefresh($method, $url, $data, $callLabel);

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
     * Execute an HTTP request with automatic token refresh on 401.
     *
     * 1. Makes the initial request.
     * 2. On 401, attempts to refresh the token via stored credentials.
     * 3. On successful refresh, retries the request with the new token.
     * 4. If refresh fails or retry still returns 401, throws AuthenticationException.
     */
    private function executeWithRefresh(string $method, string $url, array $data, string $callLabel): Response
    {
        // Load token via ApiService singleton (reads from session, falls back to local DB)
        $token = null;
        try {
            $token = app(\App\Services\Mobile\ApiService::class)->getToken();
        } catch (\Exception $e) {
            // If ApiService not bound yet, fall back to raw session
            try {
                $token = session('api_token_raw');
            } catch (\Exception $se) {
                session()->forget('api_token_raw');
            }
        }

        $http = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));

        // Make the initial request
        $response = $this->makeHttpCall($http, $method, $url, $data);
        $this->logApiCall($callLabel, $url, $method, $response, $token);

        // If not 401, return the response as-is
        if (!$response->unauthorized()) {
            return $response;
        }

        // Received 401 — attempt token refresh and retry once
        Log::info('[MakesApiRequests] Received 401, attempting token refresh...');

        try {
            $apiService = app(\App\Services\Mobile\ApiService::class);
            if ($apiService->refreshToken()) {
                $newToken = $apiService->getToken();
                if ($newToken) {
                    $retryHttp = Http::timeout(30)
                        ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
                        ->withToken($newToken);

                    $retryResponse = $this->makeHttpCall($retryHttp, $method, $url, $data);
                    $this->logApiCall($callLabel . ' (retry)', $url, $method, $retryResponse, $newToken, true);

                    if ($retryResponse->successful()) {
                        Log::info('[MakesApiRequests] Retry succeeded after token refresh.');
                        return $retryResponse;
                    }

                    if (!$retryResponse->unauthorized()) {
                        // Retry returned a non-auth error (e.g. 404, 422, 500) — pass it through
                        return $retryResponse;
                    }

                    // Retry also returned 401 — token refresh didn't help
                    Log::warning('[MakesApiRequests] Retry also returned 401 after token refresh.');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[MakesApiRequests] Token refresh attempt failed: ' . $e->getMessage());
        }

        session()->forget('api_token');
        throw new \Illuminate\Auth\AuthenticationException('Session expired. Please login again.');
    }

    /**
     * Make the actual HTTP request and return the response.
     */
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

    /**
     * Log the API call details (status, timing, token presence).
     */
    private function logApiCall(string $label, string $url, string $method, Response $response, ?string $token, bool $isRetry = false): void
    {
        // Patient-debug logging for successful patient GET calls
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

        $context = $isRetry ? ' (retry after token refresh)' : '';
        Log::debug(sprintf(
            '[API] %s%s | Status: %d | Token: %s',
            $label,
            $context,
            $response->status(),
            $token ? 'YES' : 'NO'
        ));
    }
}
