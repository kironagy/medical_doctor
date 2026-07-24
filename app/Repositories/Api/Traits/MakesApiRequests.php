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
        $encryptedToken = session('api_token');

        // ═══════════════════════════════════════════════════════════════════
        //  AUTH INSTRUMENTATION: Log every step of token resolution
        // ═══════════════════════════════════════════════════════════════════
        // We need to trace WHY the production server returns 401. The token
        // chain is: session('api_token') → decrypt → Bearer token → Server.
        // Any link in this chain could break.
        // ═══════════════════════════════════════════════════════════════════
        
        $token = null;
        $tokenPrefix = 'NONE';
        $decryptError = null;
        
        if ($encryptedToken) {
            try {
                $token = decrypt($encryptedToken);
                // Log first 20 chars so we can correlate with production DB
                $tokenPrefix = 'OK:' . substr($token, 0, 20) . '...';
            } catch (\Exception $e) {
                $decryptError = $e->getMessage();
                $tokenPrefix = 'DECRYPT_FAILED:' . $e->getMessage();
                session()->forget('api_token');
            }
        } else {
            $tokenPrefix = 'NULL'; // session('api_token') does not exist
        }
        
        Log::info('[ApiAuth] Token status for ' . $method . ' ' . $path, [
            'session_has_api_token' => $encryptedToken ? 'YES' : 'NO',
            'token_prefix' => $tokenPrefix,
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
            'status' => $response->status(),
            'time_ms' => round($timeMs, 1),
            'token_was_sent' => $token ? 'YES' : 'NO',
            'token_prefix' => $tokenPrefix,
            'response_message' => $responseMessage,
        ]);

        if ($response->unauthorized()) {
            Log::warning('[ApiAuth] ❌ 401 UNAUTHORIZED for ' . $method . ' ' . $path, [
                'token_was_sent' => $token ? 'YES' : 'NO',
                'token_prefix' => $tokenPrefix,
                'response_body' => $responseBody,
                'session_has_api_token' => $encryptedToken ? 'YES' : 'NO',
                'auth_check' => auth()->check() ? 'YES' : 'NO',
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
