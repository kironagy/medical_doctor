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
        return config('app.mobile_api_url');
    }

    private function apiCall(string $method, string $path, array $data = []): Response
    {
        $url = $this->baseUrl() . $path;
        $requestId = substr(uniqid('mac', true), -8);

        // ═══════════════════════════════════════════════════════════════════
        //  AUTH SOURCE: Single source of truth — ApiService singleton
        // ═══════════════════════════════════════════════════════════════════
        // AUTH-001 FIX: Previous code had TWO independent auth paths:
        //   Path A: ApiService — reads token from session in constructor
        //   Path B: Direct session('api_token') read
        // These paths could desync, causing intermittent 401 errors.
        //
        // Now we use a SINGLE path: ApiService::getToken().
        // ApiService is registered as a singleton in AppServiceProvider,
        // so app(ApiService::class) always returns the same instance.
        // ApiService's constructor already loads from session('api_token')
        // and from the disk file as fallback — so no dual-source needed here.
        // ═══════════════════════════════════════════════════════════════════
        
        $token = null;
        try {
            $token = app(\App\Services\Mobile\ApiService::class)->getToken();
        } catch (\Throwable $e) {
            Log::warning('[ApiAuth] ApiService lookup failed: ' . $e->getMessage());
        }
        
        $tokenPrefix = $token ? substr($token, 0, 20) . '...' : 'NONE';
        
        Log::info('[ApiAuth] Token status for ' . $method . ' ' . $path, [
            'request_id' => $requestId,
            'token_present' => $token ? 'YES' : 'NO',
            'token_prefix' => $tokenPrefix,
            'method' => $method,
            'path' => $path,
            'url' => $url,
            'session_id' => session()->getId(),
            'auth_check' => auth()->check() ? 'YES' : 'NO',
        ]);

        $http = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));

        $start = microtime(true);

        $response = match (strtoupper($method)) {
            'GET'    => $http->get($url, $data),
            'POST'   => $http->post($url, $data),
            'PUT'    => $http->put($url, $data),
            'DELETE' => $http->delete($url, $data),
            default  => throw new RuntimeException("Unsupported HTTP method: $method"),
        };

        $timeMs = (microtime(true) - $start) * 1000;

        // ═══════════════════════════════════════════════════════════════════
        //  RESPONSE INSTRUMENTATION: Log full response details for 401
        // ═══════════════════════════════════════════════════════════════════
        $responseBody = $response->json();
        $responseMessage = is_array($responseBody) ? ($responseBody['message'] ?? 'no message') : 'non-json';
        
        Log::info('[ApiAuth] Response for ' . $method . ' ' . $path, [
            'request_id' => $requestId,
            'status' => $response->status(),
            'time_ms' => round($timeMs, 1),
            'token_was_sent' => $token ? 'YES' : 'NO',
            'token_prefix' => $rawTokenPrefix,
            'token_hash' => $tokenHash,
            'response_message' => $responseMessage,
            'response_body' => $responseBody,
        ]);

        if ($response->unauthorized()) {
            Log::warning('[ApiAuth] ❌ 401 UNAUTHORIZED for ' . $method . ' ' . $path, [
                'request_id' => $requestId,
                'token_was_sent' => $token ? 'YES' : 'NO',
                'token_prefix' => $rawTokenPrefix,
                'token_hash' => $tokenHash,
                'sanctum_token_id' => $sanctumTokenId,
                'response_body' => $responseBody,
                'response_status' => $response->status(),
                'response_headers' => $response->headers(),
                'session_has_api_token' => $sessionEncryptedToken ? 'YES' : 'NO',
                'auth_check' => auth()->check() ? 'YES' : 'NO',
                'auth_user_id' => auth()->id(),
                'session_id' => session()->getId(),
            ]);
            // ── CRITICAL: Do NOT clear the token or session on 401 ─────
            // The sync engine has its own retry logic and will re-attempt.
            // Clearing the session token creates a cascade where ALL
            // subsequent requests also fail with 401 (no Bearer header).
            // A single transient 401 should NOT destroy the token.
            // If the token is permanently invalid, the sync engine will
            // keep retrying and eventually surface the error to the user.
            // Session-level token management is handled by ApiService.
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
