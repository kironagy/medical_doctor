<?php

namespace App\Services\Mobile;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * ApiService — Compatibility Proxy delegating strictly to RemoteApiService
 * ───────────────────────────────────────────────────────────────────────────
 *
 * All remote HTTP requests are centralized inside RemoteApiService.
 * This class acts purely as a wrapper to delegate methods to RemoteApiService.
 * ───────────────────────────────────────────────────────────────────────────
 */
class ApiService
{
    public function __construct(
        private readonly RemoteApiService $remoteApi
    ) {}

    public function setToken(?string $token): void
    {
        $this->remoteApi->setToken($token);
    }

    public function getToken(): ?string
    {
        return $this->remoteApi->getToken();
    }

    public function get(string $endpoint, array $query = []): array
    {
        return $this->remoteApi->get($endpoint, $query);
    }

    public function post(string $endpoint, array $data = []): array
    {
        return $this->remoteApi->post($endpoint, $data);
    }

    public function put(string $endpoint, array $data = []): array
    {
        return $this->remoteApi->put($endpoint, $data);
    }

    public function delete(string $endpoint): array
    {
        return $this->remoteApi->delete($endpoint);
    }

    public function upload(string $endpoint, array $files, array $data = []): array
    {
        return $this->remoteApi->upload($endpoint, $files, $data);
    }

    public function download(string $endpoint, string $destinationPath): bool
    {
        return $this->remoteApi->download($endpoint, $destinationPath);
    }

    /**
     * Authenticate credentials against the remote production server to obtain a Sanctum Bearer token.
     *
     * Used by the SyncEngine to automatically refresh tokens after a 401 response,
     * and by the mobile app during the fallback authentication flow.
     *
     * @throws \RuntimeException
     */
    public static function loginToRemote(string $email, string $password): array
    {
        $baseUrl = rtrim(config('app.mobile_api_url', 'https://prof-hosam-fekry.online'), '/');
        $loginUrl = $baseUrl . '/api/v1/login';

        \Illuminate\Support\Facades\Log::info('[ApiService] loginToRemote: attempting remote login at: ' . $loginUrl);

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders(['Accept' => 'application/json'])
                ->post($loginUrl, [
                    'email'    => $email,
                    'password' => $password,
                ]);

            if ($response->failed()) {
                $body = $response->json();
                $message = is_array($body)
                    ? ($body['message'] ?? $body['errors']['email'][0] ?? 'Invalid credentials.')
                    : ($response->serverError() ? 'Server error. Please try again.' : 'Invalid credentials.');
                throw new \RuntimeException($message);
            }

            $body = $response->json();
            if (!is_array($body) || !isset($body['token'])) {
                throw new \RuntimeException('Invalid response format from remote server.');
            }

            return $body;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException('Unable to connect to remote server. Please check your internet connection.');
        }
    }
}

