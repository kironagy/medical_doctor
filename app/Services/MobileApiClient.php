<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MobileApiClient
{
    public function login(string $email, string $password, string $deviceName = 'nativephp-mobile'): array
    {
        return $this->post('/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => $deviceName,
        ]);
    }

    public function seed(string $token, int $page = 1, int $limit = 100): array
    {
        return $this->get('/sync/seed', ['page' => $page, 'limit' => $limit], $token);
    }

    public function changes(string $token, ?string $since, int $page = 1, int $limit = 100): array
    {
        return $this->get('/sync/changes', ['since' => $since, 'page' => $page, 'limit' => $limit], $token);
    }

    public function push(string $token, array $operations): array
    {
        return $this->post('/sync/push', ['operations' => $operations], $token);
    }

    private function get(string $path, array $query = [], ?string $token = null): array
    {
        return $this->send('GET', $path, $query, $token);
    }

    private function post(string $path, array $payload = [], ?string $token = null): array
    {
        return $this->send('POST', $path, $payload, $token);
    }

    private function send(string $method, string $path, array $payload = [], ?string $token = null): array
    {
        $url = config('mobile.api_url').'/v1'.$path;
        $startedAt = microtime(true);

        $this->logRequest($method, $url, $payload, $token);

        try {
            $request = \Illuminate\Support\Facades\Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'Connection' => 'keep-alive',
                ])
                ->timeout((int) config('mobile.timeout', 20))
                ->retry(3, 250);

            if ($token) {
                $request = $request->withToken($token);
            }

            /** @var Response $response */
            $response = $method === 'GET'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);

            Log::info('mobile_api.response', [
                'method' => $method,
                'url' => $url,
                'status' => $response->status(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'body' => $this->sanitize($response->json() ?? ['raw' => $response->body()]),
            ]);

            $response->throw();

            return $response->json() ?? [];
        } catch (ConnectionException|RequestException $exception) {
            Log::error('mobile_api.exception', [
                'method' => $method,
                'url' => $url,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'status' => $exception instanceof RequestException ? $exception->response->status() : null,
                'response' => $exception instanceof RequestException ? $this->sanitize($exception->response->json() ?? ['raw' => $exception->response->body()]) : null,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'timeout_seconds' => (int) config('mobile.timeout', 20),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('mobile_api.unexpected_exception', [
                'method' => $method,
                'url' => $url,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $exception;
        }
    }

    private function logRequest(string $method, string $url, array $payload, ?string $token): void
    {
        Log::info('mobile_api.request', [
            'method' => $method,
            'url' => $url,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $token ? 'Bearer [present]' : null,
            ],
            'body' => $this->sanitize($payload),
            'timeout_seconds' => (int) config('mobile.timeout', 20),
            'connectivity' => [
                'php_sapi' => PHP_SAPI,
                'app_url' => config('app.url'),
                'api_base' => config('mobile.api_url'),
            ],
        ]);
    }

    private function sanitize(array $data): array
    {
        foreach (['password', 'access_token', 'token', 'remember_token'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '[redacted]';
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}
