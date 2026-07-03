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
        $this->token = session('api_token');
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
        if ($token) {
            session(['api_token' => encrypt($token)]);
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

    public function upload(string $path, array $files, array $data = []): array
    {
        $url = self::BASE_URL . $path;
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
            throw new RuntimeException($response->json()['message'] ?? 'Upload failed.');
        }

        return $response->json() ?? [];
    }

    public function download(string $path, string $destination): bool
    {
        $url = self::BASE_URL . $path;

        $response = $this->client()->sink($destination)->get($url);

        if ($response->unauthorized()) {
            $this->setToken(null);
            throw new RuntimeException('Session expired. Please login again.');
        }

        return $response->successful();
    }

    private function send(string $method, string $path, array $options = []): array
    {
        $url = self::BASE_URL . $path;
        $attempts = 0;

        while ($attempts <= self::MAX_RETRIES) {
            try {
                $response = $this->client()->send($method, $url, $options);

                if ($response->unauthorized()) {
                    $this->setToken(null);
                    throw new RuntimeException('Session expired. Please login again.');
                }

                if ($response->failed()) {
                    throw new RequestException($response);
                }

                return $response->json() ?? [];
            } catch (RequestException $e) {
                $attempts++;
                if ($attempts > self::MAX_RETRIES) {
                    throw new RuntimeException(
                        $e->response->json()['message'] ?? 'Server error. Please try again.',
                        $e->response->status()
                    );
                }
                usleep(self::RETRY_DELAY_MS * 1000);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $attempts++;
                if ($attempts > self::MAX_RETRIES) {
                    throw new RuntimeException(
                        'Unable to connect. Please check your internet connection.'
                    );
                }
                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        throw new RuntimeException('Request failed after retries.');
    }

    public static function loginToRemote(string $email, string $password): array
    {
        $response = Http::timeout(30)->post(
            'https://prof-hosam-fekry.online/api/v1/login',
            ['email' => $email, 'password' => $password]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json()['message'] ?? $response->json()['errors']['email'][0] ?? 'Invalid credentials.'
            );
        }

        return $response->json();
    }
}
