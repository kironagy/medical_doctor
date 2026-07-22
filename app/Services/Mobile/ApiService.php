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
        // Load token from session on construct
        try {
            $this->token = session('api_token_raw');
        } catch (\Exception $e) {
            $this->token = null;
        }
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;

        // Store token in session only (no SQLite persistence)
        if ($token) {
            try {
                session(['api_token_raw' => $token]);
            } catch (\Exception $e) {
                // Session may not be available
            }
        } else {
            try {
                session()->forget('api_token_raw');
            } catch (\Exception $e) {}
        }
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function storeCredentials(string $email, string $password): void
    {
        // No-op: credentials are only needed for automatic token refresh,
        // which was part of the removed SQLite/sync architecture.
        // The API service now relies on session-based tokens only.
    }

    public function loadCredentials(): ?array
    {
        return null;
    }

    public function clearCredentials(): void
    {
        // No-op: credentials storage was removed.
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
            Log::info('[ApiService] Upload received 401, session expired.');
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
            Log::info('[ApiService] Download received 401, session expired.');
            $this->setToken(null);
            throw new RuntimeException('Session expired. Please login again.');
        }

        return $response->successful();
    }

    public function refreshToken(): bool
    {
        // Token refresh via stored credentials has been removed.
        // The app is API-only now — if the token expires, the user
        // will be prompted to login again via 401 handling.
        return false;
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
