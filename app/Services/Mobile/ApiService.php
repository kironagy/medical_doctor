<?php

namespace App\Services\Mobile;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ApiService
{
    private const MAX_RETRIES = 2;

    private const RETRY_DELAY_MS = 500;

    private const TOKEN_FILE_PATH = 'app/.api_sync_token';

    private ?string $token = null;

    /** @var string Unique identifier for this instance (for singleton debugging) */
    private readonly string $instanceId;

    public function __construct()
    {
        $this->instanceId = substr(uniqid('api', true), -8);

        $sessionId = session()->getId();
        $encrypted = session('api_token');

        if ($encrypted) {
            try {
                $this->token = decrypt($encrypted);
                Log::info('[DIAG.ApiService] Constructor — token loaded from session', [
                    'instance' => $this->instanceId,
                    'session_id' => $sessionId,
                    'token_present' => 'YES',
                    'token_prefix' => substr($this->token, 0, 20) . '...' . substr($this->token, -4),
                    'token_length' => strlen($this->token),
                    'auth_check' => auth()->check() ? 'YES' : 'NO',
                    'auth_user_id' => auth()->id(),
                ]);
            } catch (\Exception $e) {
                Log::warning('[DIAG.ApiService] Constructor — decrypt FAILED', [
                    'instance' => $this->instanceId,
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                    'encrypted_present' => 'YES',
                ]);
                $this->token = null;
                session()->forget('api_token');
            }
        } else {
            Log::info('[DIAG.ApiService] Constructor — NO token in session', [
                'instance' => $this->instanceId,
                'session_id' => $sessionId,
                'token_present' => 'NO',
                'auth_check' => auth()->check() ? 'YES' : 'NO',
                'auth_user_id' => auth()->id(),
            ]);
        }

        if (empty($this->token)) {
            $this->loadTokenFromFile();
        }
    }

    public function setToken(?string $token): void
    {
        $oldPrefix = $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE';
        $newPrefix = $token ? substr($token, 0, 20) . '...' . substr($token, -4) : 'NULL/CLEAR';

        $this->token = $token;
        if ($token) {
            try {
                session(['api_token' => encrypt($token)]);
                $this->writeTokenToFile($token);
                Log::info('[DIAG.ApiService] setToken — stored new token', [
                    'instance' => $this->instanceId ?? 'unknown',
                    'session_id' => session()->getId(),
                    'old_token_prefix' => $oldPrefix,
                    'new_token_prefix' => $newPrefix,
                    'token_length' => strlen($token),
                ]);
            } catch (\Exception $e) {
                $this->token = null;
                Log::error('[DIAG.ApiService] setToken — encrypt FAILED', [
                    'instance' => $this->instanceId ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                throw new RuntimeException('Failed to store authentication token.');
            }
        } else {
            session()->forget('api_token');
            $this->deleteTokenFile();
            Log::warning('[DIAG.ApiService] setToken — token CLEARED', [
                'instance' => $this->instanceId ?? 'unknown',
                'old_token_prefix' => $oldPrefix,
            ]);
        }
    }

    private function loadTokenFromFile(): void
    {
        $path = storage_path(self::TOKEN_FILE_PATH);
        if (!file_exists($path)) {
            return;
        }
        $contents = file_get_contents($path);
        if (empty($contents)) {
            return;
        }
        // ── TOKEN-004 FIX: Use decrypt() instead of base64_decode()
        try {
            $this->token = decrypt($contents);
            Log::info('[DIAG.ApiService] loadTokenFromFile — token loaded from file', [
                'instance' => $this->instanceId,
                'token_length' => strlen($this->token),
            ]);
        } catch (\Exception $e) {
            Log::warning('[DIAG.ApiService] loadTokenFromFile — decrypt failed, clearing file: ' . $e->getMessage());
            @unlink($path);
            $this->token = null;
        }
    }

    private function writeTokenToFile(string $token): void
    {
        try {
            $path = storage_path(self::TOKEN_FILE_PATH);
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            // ── TOKEN-004 FIX: Use encrypt() instead of base64_encode()
            // Previous code used base64_encode() which is encoding, NOT
            // encryption. This exposed the token to any process with
            // filesystem access. Now using Laravel's encrypt() which
            // uses AES-256-CBC with the APP_KEY.
            file_put_contents($path, encrypt($token), LOCK_EX);
        } catch (\Throwable $e) {
            Log::warning('[DIAG.ApiService] writeTokenToFile — failed: ' . $e->getMessage());
        }
    }

    private function deleteTokenFile(): void
    {
        try {
            $path = storage_path(self::TOKEN_FILE_PATH);
            if (file_exists($path)) {
                @unlink($path);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Extract the Sanctum token ID from a plainTextToken (format: "ID|hash").
     */
    private static function extractTokenId(string $token): ?string
    {
        $parts = explode('|', $token, 2);
        return $parts[0] ?? null;
    }

    /**
     * Build a diagnostics payload for the current token.
     */
    private function tokenDiag(): array
    {
        $tokenId = $this->token ? self::extractTokenId($this->token) : null;
        return [
            'present' => $this->token ? 'YES' : 'NO',
            'prefix' => $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE',
            'hash' => $this->token ? md5($this->token) : 'NONE',
            'sanctum_id' => $tokenId,
            'length' => $this->token ? strlen($this->token) : 0,
        ];
    }

    public function getToken(): ?string
    {
        $tokenId = $this->token ? self::extractTokenId($this->token) : null;
        Log::info('[DIAG.ApiService] getToken called', [
            'instance' => $this->instanceId ?? 'unknown',
            'token' => $this->tokenDiag(),
            'session_id' => session()->getId(),
        ]);
        return $this->token;
    }

    private function client(): PendingRequest
    {
        $client = Http::timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
            ]);

        if ($this->token) {
            $client->withToken($this->token);
        }

        return $client;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, ['json' => $data]);
    }

    public function put(string $path, array $data = []): array
    {
        return $this->send('PUT', $path, ['json' => $data]);
    }

    public function delete(string $path): array
    {
        return $this->send('DELETE', $path);
    }

    private function baseUrl(): string
    {
        return config('app.mobile_api_url');
    }

    public function upload(string $path, array $files, array $data = []): array
    {
        $url = $this->baseUrl() . $path;
        $request = $this->client();
        $requestId = substr(uniqid('upl', true), -8);

        Log::info('[DIAG.ApiService] upload — starting', [
            'request_id' => $requestId,
            'path' => $path,
            'url' => $url,
            'instance' => $this->instanceId ?? 'unknown',
            'token_present' => $this->token ? 'YES' : 'NO',
            'token_prefix' => $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE',
            'token_hash' => $this->token ? md5($this->token) : 'NONE',
            'file_count' => count($files),
            'data_keys' => array_keys($data),
            'session_id' => session()->getId(),
        ]);

        foreach ($files as $key => $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                // Use a resource stream instead of reading the entire file into memory
                $stream = fopen($file->getRealPath(), 'rb');
                if ($stream) {
                    $request->attach($key, $stream, $file->getClientOriginalName());
                }
            } elseif (is_string($file) && file_exists($file)) {
                $stream = fopen($file, 'rb');
                if ($stream) {
                    $request->attach($key, $stream, basename($file));
                }
            }
        }

        $response = $request->post($url, $data);

        Log::info('[DIAG.ApiService] upload — response', [
            'request_id' => $requestId,
            'status' => $response->status(),
            'token_still_present' => $this->token ? 'YES' : 'NO',
            'token_hash' => $this->token ? md5($this->token) : 'NONE',
            'response_body' => $response->json(),
        ]);

        if ($response->unauthorized()) {
            Log::warning('[DIAG.ApiService] ❌ 401 in upload()', [
                'request_id' => $requestId,
                'path' => $path,
                'token_still_present' => $this->token ? 'YES' : 'NO',
                'token_prefix' => $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE',
                'token_hash' => $this->token ? md5($this->token) : 'NONE',
                'response_body' => $response->json(),
                'response_headers' => $response->headers(),
                'session_id' => session()->getId(),
            ]);
            // ── CRITICAL: Do NOT clear the token on 401 ─────────
            // See send() for details. Token is preserved across retries.
            throw new RuntimeException('Session expired. Please login again.');
        }

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? 'Upload failed.') : 'Upload failed.';
            Log::warning('[DIAG.ApiService] upload — non-401 failure', [
                'request_id' => $requestId,
                'status' => $response->status(),
                'body' => $body,
            ]);
            throw new RuntimeException($message);
        }

        return $response->json() ?? [];
    }

    public function download(string $path, string $destination): bool
    {
        $url = $this->baseUrl() . $path;
        $requestId = substr(uniqid('dwn', true), -8);

        Log::info('[DIAG.ApiService] download — starting', [
            'request_id' => $requestId,
            'path' => $path,
            'url' => $url,
            'instance' => $this->instanceId ?? 'unknown',
            'token_present' => $this->token ? 'YES' : 'NO',
            'token_prefix' => $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE',
            'token_hash' => $this->token ? md5($this->token) : 'NONE',
        ]);

        $response = $this->client()->sink($destination)->get($url);

        Log::info('[DIAG.ApiService] download — response', [
            'request_id' => $requestId,
            'status' => $response->status(),
            'token_still_present' => $this->token ? 'YES' : 'NO',
        ]);

        if ($response->unauthorized()) {
            Log::warning('[DIAG.ApiService] ❌ 401 in download()', [
                'request_id' => $requestId,
                'path' => $path,
                'token_still_present' => $this->token ? 'YES' : 'NO',
                'token_prefix' => $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE',
                'token_hash' => $this->token ? md5($this->token) : 'NONE',
                'response_body' => $response->json(),
                'response_headers' => $response->headers(),
            ]);
            // ── CRITICAL: Do NOT clear the token on 401 ─────────
            // See send() for details. Token is preserved across retries.
            throw new RuntimeException('Session expired. Please login again.');
        }

        return $response->successful();
    }

    private function send(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl() . $path;
        $attempts = 0;
        $requestId = substr(uniqid('req', true), -8);

        Log::info('[DIAG.ApiService] send — starting', [
            'request_id' => $requestId,
            'method' => $method,
            'path' => $path,
            'url' => $url,
            'instance' => $this->instanceId ?? 'unknown',
            'token_present' => $this->token ? 'YES' : 'NO',
            'token_prefix' => $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE',
            'token_hash' => $this->token ? md5($this->token) : 'NONE',
            'session_id' => session()->getId(),
        ]);

        // ── GUARD: No token available — skip immediately ───────────────
        // Without this check, a request sent without a Bearer token will
        // receive a 401 from the production server. ApiService then clears
        // the (already null) token, creating a permanent failure state that
        // prevents ALL future sync attempts from authenticating.
        if (empty($this->token)) {
            Log::warning('[ApiService] No token available — skipping API call to ' . $url);
            throw new RuntimeException('API token is not available. Please login again.');
        }

        while ($attempts <= self::MAX_RETRIES) {
            try {
                $response = $this->client()->send($method, $url, $options);

                Log::info('[DIAG.ApiService] send — response received', [
                    'request_id' => $requestId,
                    'attempt' => $attempts,
                    'status' => $response->status(),
                    'token_still_present' => $this->token ? 'YES' : 'NO',
                    'token_prefix_after' => $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE',
                    'response_body' => $response->json(),
                ]);

                if ($response->unauthorized()) {
                    Log::warning('[DIAG.ApiService] ❌ 401 in send()', [
                        'request_id' => $requestId,
                        'method' => $method,
                        'path' => $path,
                        'url' => $url,
                        'token_still_present' => $this->token ? 'YES' : 'NO',
                        'token_prefix' => $this->token ? substr($this->token, 0, 20) . '...' . substr($this->token, -4) : 'NONE',
                        'token_hash' => $this->token ? md5($this->token) : 'NONE',
                        'response_body' => $response->json(),
                        'response_status' => $response->status(),
                        'response_headers' => $response->headers(),
                        'session_id' => session()->getId(),
                    ]);
                    // ── CRITICAL: Do NOT clear the token on 401 ─────────
                    // The sync engine has its own retry logic. Clearing the
                    // token creates a cascade where all subsequent requests
                    // fail with 401 because no Bearer header is sent.
                    // A single transient 401 should NOT destroy the token.
                    // This must be kept in sync with MakesApiRequests which
                    // also now preserves the token on 401.
                    throw new RuntimeException('Session expired. Please login again.');
                }

                if ($response->failed()) {
                    $body = $response->json();
                    $message = is_array($body) ? ($body['message'] ?? 'API request failed.') : 'API request failed.';
                    Log::warning('[DIAG.ApiService] send — non-401 failure', [
                        'request_id' => $requestId,
                        'status' => $response->status(),
                        'body' => $body,
                    ]);
                    throw new RuntimeException($message);
                }

                Log::info('[DIAG.ApiService] send — success', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                ]);

                return $response->json() ?? [];
            } catch (RequestException $e) {
                $attempts++;
                Log::warning('[DIAG.ApiService] send — connection error', [
                    'request_id' => $requestId,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                ]);
                if ($attempts > self::MAX_RETRIES) {
                    throw new RuntimeException('API connection failed: ' . $e->getMessage());
                }
                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        throw new RuntimeException('Request failed after retries.');
    }

    public static function loginToRemote(string $email, string $password): array
    {
        $requestId = substr(uniqid('lgn', true), -8);

        try {
            $loginUrl = str_replace('/mobile', '', config('app.mobile_api_url')) . '/login';
            Log::info('[DIAG.ApiService] loginToRemote — attempting login', [
                'request_id' => $requestId,
                'email' => $email,
                'login_url' => $loginUrl,
            ]);

            $response = Http::timeout(30)->post(
                $loginUrl,
                ['email' => $email, 'password' => $password]
            );

            Log::info('[DIAG.ApiService] loginToRemote — response', [
                'request_id' => $requestId,
                'status' => $response->status(),
                'body_keys' => $response->json() ? array_keys($response->json()) : 'non-json',
            ]);

            if ($response->failed()) {
                $body = $response->json();
                $message = is_array($body)
                    ? ($body['message'] ?? $body['errors']['email'][0] ?? 'Invalid credentials.')
                    : ($response->serverError() ? 'Server error. Please try again.' : 'Invalid credentials.');
                Log::warning('[DIAG.ApiService] loginToRemote — failed', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                    'body' => $body,
                    'message' => $message,
                ]);
                throw new RuntimeException($message);
            }

            $body = $response->json();
            if (!is_array($body) || !isset($body['token'])) {
                Log::error('[DIAG.ApiService] loginToRemote — missing token in response', [
                    'request_id' => $requestId,
                    'response' => $body,
                ]);
                throw new RuntimeException('Invalid response from server.');
            }

            Log::info('[DIAG.ApiService] loginToRemote — success', [
                'request_id' => $requestId,
                'token_prefix' => substr($body['token'], 0, 20) . '...' . substr($body['token'], -4),
                'token_length' => strlen($body['token']),
                'has_user' => isset($body['user']) ? 'YES' : 'NO',
            ]);

            return $body;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('[DIAG.ApiService] loginToRemote — connection error', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to connect. Please check your internet connection.');
        }
    }
}
