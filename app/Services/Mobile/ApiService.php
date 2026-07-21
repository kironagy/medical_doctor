<?php

namespace App\Services\Mobile;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ApiService
{
    private const BASE_URL = 'https://prof-hosam-fekry.online/api/v1/mobile';

    private const MAX_RETRIES = 2;

    private const RETRY_DELAY_MS = 500;

    private ?string $token = null;

    public function __construct()
    {
        // 1. Try session first (fast, current request)
        // Uses plaintext session key to avoid APP_KEY dependency
        try {
            $plain = session('api_token_raw');
            if ($plain) {
                $this->token = $plain;
                return;
            }
        } catch (\Exception $e) {
            session()->forget('api_token_raw');
        }

        // 2. Fall back to persistent local DB storage (survives app restarts)
        $this->token = $this->loadTokenFromDb();

        // 3. If found in DB, restore to session for this request
        if ($this->token) {
            try {
                session(['api_token_raw' => $this->token]);
            } catch (\Exception $e) {
                // Session unavailable (e.g. CLI context) — token still usable in memory
            }
        }
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
        if ($token) {
            // Persist to session (stored in plaintext to avoid APP_KEY dependency)
            try {
                session(['api_token_raw' => $token]);
            } catch (\Exception $e) {
                // Session may not be available (CLI/startup sync context)
            }
            // Persist to DB so the token survives process restarts
            $this->saveTokenToDb($token);
        } else {
            try {
                session()->forget('api_token_raw');
                session()->forget('api_token');
            } catch (\Exception $e) {}
            $this->saveTokenToDb(null);
        }
    }

    /**
     * Load the API token from the local sync_states table.
     * Returns null if the table doesn't exist yet or has no token.
     *
     * IMPORTANT: The token is stored in PLAINTEXT (not encrypted) because:
     * 1. The local SQLite database is only accessible on the device itself.
     * 2. Encrypting with APP_KEY causes token loss when the key changes
     *    between app builds or when config cache is cleared.
     * 3. The token is a Sanctum bearer token — it's already designed to
     *    be transmitted and stored by the client.
     */
    private function loadTokenFromDb(): ?string
    {
        try {
            $row = \Illuminate\Support\Facades\DB::table('sync_states')
                ->where('key', 'api_token')
                ->first();
            if (!$row) return null;
            $value = is_string($row->value) ? json_decode($row->value, true) : $row->value;
            // Support both plaintext and legacy encrypted formats
            if (is_array($value) && isset($value['encrypted'])) {
                // Legacy format: try to decrypt, fall back to null
                try {
                    return decrypt($value['encrypted']);
                } catch (\Exception $e) {
                    Log::warning('[ApiService] Failed to decrypt legacy token format, clearing it');
                    return null;
                }
            }
            // New format: stored as plaintext string
            if (is_array($value) && isset($value['plain'])) {
                return $value['plain'];
            }
            // Direct string (oldest format or fallback)
            return is_string($row->value) ? $row->value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Persist (or clear) the API token in the local sync_states table.
     * Stores in plaintext to avoid APP_KEY dependency (see loadTokenFromDb).
     */
    private function saveTokenToDb(?string $token): void
    {
        try {
            $value = $token ? json_encode(['plain' => $token]) : json_encode(null);
            $exists = \Illuminate\Support\Facades\DB::table('sync_states')
                ->where('key', 'api_token')
                ->exists();
            if ($exists) {
                \Illuminate\Support\Facades\DB::table('sync_states')
                    ->where('key', 'api_token')
                    ->update(['value' => $value, 'updated_at' => now()]);
            } else {
                \Illuminate\Support\Facades\DB::table('sync_states')
                    ->insert(['key' => 'api_token', 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Exception $e) {
            // DB not ready yet (first boot before migrations) — ignore
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
        return config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
    }

    public function upload(string $path, array $files, array $data = []): array
    {
        $url = $this->baseUrl() . $path;
        $request = $this->client();

        foreach ($files as $key => $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $request->attach($key, file_get_contents($file->getRealPath()), $file->getClientOriginalName());
            } elseif (is_string($file) && file_exists($file)) {
                $request->attach($key, file_get_contents($file), basename($file));
            }
        }

        $response = $request->post($url, $data);

        if ($response->unauthorized()) {
            $this->setToken(null);
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
            $this->setToken(null);
            throw new RuntimeException('Session expired. Please login again.');
        }

        return $response->successful();
    }

    /**
     * Attempt to refresh the API token.
     * Currently this clears the token and signals re-authentication needed.
     * Future: implement proper refresh token flow if the remote API supports it.
     */
    public function refreshToken(): bool
    {
        try {
            // Check if we still have connectivity before clearing the token
            $testUrl = $this->baseUrl();
            $response = Http::timeout(5)->get($testUrl);
            if ($response->status() < 500) {
                // Server is reachable — token is truly expired
                $this->setToken(null);
                Log::warning('[ApiService] Token refresh failed — session expired. User must re-login.');
                return false;
            }
            // Server unreachable — keep the token for later retry
            return false;
        } catch (\Throwable $e) {
            // Network issue — keep token
            Log::warning('[ApiService] Cannot refresh token (network issue): ' . $e->getMessage());
            return false;
        }
    }

    private function send(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl() . $path;
        $attempts = 0;

        while ($attempts <= self::MAX_RETRIES) {
            try {
                $response = $this->client()->send($method, $url, $options);

                if ($response->unauthorized()) {
                    // Try to refresh token before giving up
                    // refreshToken() already clears the token via setToken(null)
                    $this->refreshToken();
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
            $loginUrl = str_replace('/mobile', '', config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile')) . '/login';
            // Short timeout (5s) so offline / unreachable networks fail fast and
            // don't make the login button spin forever.
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->post(
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
