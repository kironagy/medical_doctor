<?php

namespace App\Services\Mobile;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
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
            // Attempt token refresh and retry the upload once
            Log::info('[ApiService] Upload received 401, attempting token refresh...');
            if ($this->refreshToken()) {
                // Rebuild request with new token and retry
                $retryRequest = $this->client();
                foreach ($files as $k => $f) {
                    if ($f instanceof \Illuminate\Http\UploadedFile) {
                        $retryRequest->attach($k, file_get_contents($f->getRealPath()), $f->getClientOriginalName());
                    } elseif (is_string($f) && file_exists($f)) {
                        $retryRequest->attach($k, file_get_contents($f), basename($f));
                    }
                }
                $response = $retryRequest->post($url, $data);

                if ($response->successful()) {
                    Log::info('[ApiService] Upload retry succeeded after token refresh.');
                    return $response->json() ?? [];
                }

                if (!$response->unauthorized()) {
                    $body = $response->json();
                    $message = is_array($body) ? ($body['message'] ?? 'Upload failed.') : 'Upload failed.';
                    throw new RuntimeException($message);
                }
            }

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
            // Attempt token refresh and retry the download once
            Log::info('[ApiService] Download received 401, attempting token refresh...');
            if ($this->refreshToken()) {
                $response = $this->client()->sink($destination)->get($url);

                if ($response->successful()) {
                    Log::info('[ApiService] Download retry succeeded after token refresh.');
                    return true;
                }

                if (!$response->unauthorized()) {
                    return false;
                }
            }

            $this->setToken(null);
            throw new RuntimeException('Session expired. Please login again.');
        }

        return $response->successful();
    }

    /**
     * Store login credentials securely for automatic token refresh.
     * Credentials are encrypted before storage.
     */
    public function storeCredentials(string $email, string $password): void
    {
        try {
            $value = json_encode([
                'email'    => Crypt::encryptString($email),
                'password' => Crypt::encryptString($password),
            ]);

            $exists = \Illuminate\Support\Facades\DB::table('sync_states')
                ->where('key', 'stored_login_credentials')
                ->exists();

            if ($exists) {
                \Illuminate\Support\Facades\DB::table('sync_states')
                    ->where('key', 'stored_login_credentials')
                    ->update(['value' => $value, 'updated_at' => now()]);
            } else {
                \Illuminate\Support\Facades\DB::table('sync_states')
                    ->insert(['key' => 'stored_login_credentials', 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }

            Log::info('[ApiService] Login credentials stored securely for auto-refresh.');
        } catch (\Throwable $e) {
            Log::warning('[ApiService] Failed to store credentials: ' . $e->getMessage());
        }
    }

    /**
     * Load stored login credentials for automatic token refresh.
     * Returns ['email' => string, 'password' => string] or null.
     */
    public function loadCredentials(): ?array
    {
        try {
            $row = \Illuminate\Support\Facades\DB::table('sync_states')
                ->where('key', 'stored_login_credentials')
                ->first();

            if (!$row || empty($row->value)) {
                return null;
            }

            $data = json_decode($row->value, true);
            if (!is_array($data) || empty($data['email']) || empty($data['password'])) {
                return null;
            }

            return [
                'email'    => Crypt::decryptString($data['email']),
                'password' => Crypt::decryptString($data['password']),
            ];
        } catch (\Throwable $e) {
            Log::warning('[ApiService] Failed to load stored credentials: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clear stored login credentials (e.g., on explicit logout).
     */
    public function clearCredentials(): void
    {
        try {
            \Illuminate\Support\Facades\DB::table('sync_states')
                ->where('key', 'stored_login_credentials')
                ->delete();
            Log::info('[ApiService] Stored login credentials cleared.');
        } catch (\Throwable $e) {
            // Ignore
        }
    }

    /**
     * Attempt to refresh the API token by re-authenticating with stored credentials.
     * Steps:
     *  1. Load encrypted email/password from local DB.
     *  2. Re-authenticate against the remote /login endpoint.
     *  3. On success, update the token in session + DB.
     *  4. On failure, clear the expired token and log a warning.
     */
    public function refreshToken(): bool
    {
        $credentials = $this->loadCredentials();

        if (!$credentials) {
            Log::warning('[ApiService] Token refresh failed — no stored credentials available. User must re-login.');
            $this->setToken(null);
            return false;
        }

        try {
            Log::info('[ApiService] Attempting token refresh via re-authentication...');
            $response = self::loginToRemote($credentials['email'], $credentials['password']);

            if (isset($response['token'])) {
                $this->setToken($response['token']);
                Log::info('[ApiService] Token refreshed successfully via re-authentication.');
                return true;
            }

            Log::warning('[ApiService] Token refresh failed — re-authentication returned no token.');
        } catch (\Throwable $e) {
            Log::warning('[ApiService] Token refresh failed: ' . $e->getMessage());
        }

        // If we get here, re-authentication failed — clear token
        $this->setToken(null);
        return false;
    }

    private function send(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl() . $path;
        $attempts = 0;
        $refreshAttempted = false;

        while ($attempts <= self::MAX_RETRIES) {
            try {
                $response = $this->client()->send($method, $url, $options);

                if ($response->unauthorized()) {
                    if (!$refreshAttempted) {
                        $refreshAttempted = true;
                        Log::info('[ApiService] Received 401, attempting token refresh and retry...');
                        if ($this->refreshToken()) {
                            // Token refreshed successfully — retry the request with new token
                            // Don't increment $attempts, give it a fresh try
                            continue;
                        }
                    }
                    // Refresh failed or already attempted
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
