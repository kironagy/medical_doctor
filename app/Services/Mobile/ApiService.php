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
        try {
            $encrypted = session('api_token');
            if ($encrypted) {
                $this->token = decrypt($encrypted);
                return;
            }
        } catch (\Exception $e) {
            session()->forget('api_token');
        }

        // 2. Fall back to persistent local DB storage (survives app restarts)
        $this->token = $this->loadTokenFromDb();

        // 3. If found in DB, restore to session for this request
        if ($this->token) {
            try {
                session(['api_token' => encrypt($this->token)]);
            } catch (\Exception $e) {
                // Session unavailable (e.g. CLI context) — token still usable in memory
            }
        }
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
        if ($token) {
            // Persist to session
            try {
                session(['api_token' => encrypt($token)]);
            } catch (\Exception $e) {
                // Session may not be available (CLI/startup sync context)
            }
            // Persist to DB so the token survives process restarts
            $this->saveTokenToDb($token);
        } else {
            try {
                session()->forget('api_token');
            } catch (\Exception $e) {}
            $this->saveTokenToDb(null);
        }
    }

    /**
     * Load the API token from the local sync_states table.
     * Returns null if the table doesn't exist yet or has no token.
     */
    private function loadTokenFromDb(): ?string
    {
        try {
            $row = \Illuminate\Support\Facades\DB::table('sync_states')
                ->where('key', 'api_token')
                ->first();
            if (!$row) return null;
            $value = is_string($row->value) ? json_decode($row->value, true) : $row->value;
            $encrypted = is_array($value) ? ($value['encrypted'] ?? null) : $value;
            return $encrypted ? decrypt($encrypted) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Persist (or clear) the API token in the local sync_states table.
     */
    private function saveTokenToDb(?string $token): void
    {
        try {
            $value = $token ? json_encode(['encrypted' => encrypt($token)]) : json_encode(null);
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

    private function send(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl() . $path;
        $attempts = 0;

        while ($attempts <= self::MAX_RETRIES) {
            try {
                $response = $this->client()->send($method, $url, $options);

                if ($response->unauthorized()) {
                    $this->setToken(null);
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
