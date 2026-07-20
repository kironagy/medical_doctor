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

        // Load token via ApiService singleton (reads from session, falls back to local DB)
        $token = null;
        try {
            $token = app(\App\Services\Mobile\ApiService::class)->getToken();
        } catch (\Exception $e) {
            // If ApiService not bound yet, fall back to raw session
            try {
                $encrypted = session('api_token');
                if ($encrypted) {
                    $token = decrypt($encrypted);
                }
            } catch (\Exception $se) {
                session()->forget('api_token');
            }
        }

        $http = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));

    $start = microtime(true);

    $response = match (strtoupper($method)) {
        'GET' => $http->get($url, $data),
        'POST' => $http->post($url, $data),
        'PUT' => $http->put($url, $data),
        'DELETE' => $http->delete($url, $data),
        default => throw new RuntimeException("Unsupported HTTP method: $method"),
    };

    $timeMs = (microtime(true) - $start) * 1000;

    Log::debug(sprintf(
        '[API] %s %s | Status: %d | Time: %.0fms | Token: %s',
        strtoupper($method),
        $url,
        $response->status(),
        $timeMs,
        $token ? 'YES' : 'NO'
    ));

    // Log full response body for patient-related GET calls (all, search, paginated)
    if (str_contains($path, '/patients') && strtoupper($method) === 'GET' && $response->successful()) {
        $responseBody = $response->json();
        if (is_array($responseBody)) {
            Log::channel('single')->info('[PATIENT_DEBUG] API raw response for ' . $callLabel, [
                'path' => $path,
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

        if ($response->unauthorized()) {
            session()->forget('api_token');
            throw new \Illuminate\Auth\AuthenticationException('Session expired. Please login again.');
        }

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
}
