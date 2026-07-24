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
        //  AUTH SOURCE: Use ApiService (singleton) as primary token source.
        // ═══════════════════════════════════════════════════════════════════
        // There are TWO independent auth paths in this codebase:
        //   Path A: ApiService — reads token from session in constructor,
        //            stores in $this->token.
        //   Path B: MakesApiRequests (this trait) — reads session('api_token')
        //            directly every call.
        //
        // These paths MUST draw from the SAME token source, otherwise they
        // can desync — one path has a valid token while the other doesn't —
        // causing the INTERMITTENT 401 pattern (POST fails, GET succeeds).
        //
        // Priority:
        //   1. ApiService::getToken() — singleton, set by session restore
        //   2. session('api_token') — fallback if ApiService hasn't loaded
        //
        // ApiService is now registered as a singleton in AppServiceProvider,
        // so app(ApiService::class) always returns the same instance.
        // ═══════════════════════════════════════════════════════════════════
        
        $token = null;
        $tokenSource = 'unknown';
        $rawTokenPrefix = 'NONE';
        $tokenHash = 'NONE';
        $decryptError = null;

        /** @var string|null Extracted Sanctum token ID (from 'ID|hash' format) */
        $sanctumTokenId = null;

        // Source 1: ApiService singleton (preferred)
        try {
            $apiServiceToken = app(\App\Services\Mobile\ApiService::class)->getToken();
            if (!empty($apiServiceToken)) {
                $token = $apiServiceToken;
                $tokenSource = 'ApiService';
                $rawTokenPrefix = substr($token, 0, 20) . '...' . substr($token, -4);
                $tokenHash = md5($token);
                $parts = explode('|', $token, 2);
                $sanctumTokenId = $parts[0] ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning('[ApiAuth] ApiService lookup failed: ' . $e->getMessage());
        }

        // Source 2: Session fallback (if ApiService didn't have one)
        $sessionEncryptedToken = null;
        if (empty($token)) {
            $sessionEncryptedToken = session('api_token');
            if ($sessionEncryptedToken) {
                try {
                    $token = decrypt($sessionEncryptedToken);
                    $tokenSource = 'session';
                    $rawTokenPrefix = substr($token, 0, 20) . '...' . substr($token, -4);
                    $tokenHash = md5($token);
                    $parts = explode('|', $token, 2);
                    $sanctumTokenId = $parts[0] ?? null;
                } catch (\Exception $e) {
                    $decryptError = $e->getMessage();
                    $rawTokenPrefix = 'DECRYPT_FAILED:' . $e->getMessage();
                    // Don't clear the session — might be a transient read error;
                    // the next read will retry. Clearing would cascade the failure.
                }
            } else {
                $tokenSource = 'session_empty';
            }
        }
        
        Log::info('[ApiAuth] Token status for ' . $method . ' ' . $path, [
            'request_id' => $requestId,
            'token_source' => $tokenSource,
            'token_prefix' => $rawTokenPrefix,
            'token_hash' => $tokenHash,
            'sanctum_token_id' => $sanctumTokenId,
            'decrypt_error' => $decryptError,
            'method' => $method,
            'path' => $path,
            'url' => $url,
            'session_id' => session()->getId(),
            'auth_check' => auth()->check() ? 'YES' : 'NO',
            'auth_user_id' => auth()->id(),
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
