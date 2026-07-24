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

    private ?string $token = null;

    public function __construct()
    {
        try {
            $encrypted = session('api_token');
            $this->token = $encrypted ? decrypt($encrypted) : null;
        } catch (\Exception $e) {
            $this->token = null;
            session()->forget('api_token');
        }
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
        if ($token) {
            try {
                session(['api_token' => encrypt($token)]);
            } catch (\Exception $e) {
                $this->token = null;
                throw new RuntimeException('Failed to store authentication token.');
            }
        } else {
            session()->forget('api_token');
        }
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    private function client(): PendingRequest
    {
        $client = Http::timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
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

        if ($response->unauthorized()) {
            // ── CRITICAL: Do NOT clear the token on 401 ─────────
            // See send() for details. Token is preserved across retries.
            throw new RuntimeException('Session expired. Please login again.');
        }

        if ($response->failed()) {
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? 'Upload failed.') : 'Upload failed.';
            throw new RuntimeException($message);
        }

        return $response->json() ?? [];
    }

    public function download(string $path, string $destination): bool
    {
        $url = $this->baseUrl() . $path;

        $response = $this->client()->sink($destination)->get($url);

        if ($response->unauthorized()) {
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

                if ($response->unauthorized()) {
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
                    throw new RuntimeException($message);
                }

                return $response->json() ?? [];
            } catch (RequestException $e) {
                $attempts++;
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
        try {
            $loginUrl = str_replace('/mobile', '', config('app.mobile_api_url')) . '/login';
            $response = Http::timeout(30)->post(
                $loginUrl,
                ['email' => $email, 'password' => $password]
            );

            if ($response->failed()) {
                $body = $response->json();
                $message = is_array($body)
                    ? ($body['message'] ?? $body['errors']['email'][0] ?? 'Invalid credentials.')
                    : ($response->serverError() ? 'Server error. Please try again.' : 'Invalid credentials.');
                throw new RuntimeException($message);
            }

            $body = $response->json();
            if (!is_array($body) || !isset($body['token'])) {
                Log::error('Login response missing token', ['response' => $body]);
                throw new RuntimeException('Invalid response from server.');
            }

            return $body;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException('Unable to connect. Please check your internet connection.');
        }
    }
}
