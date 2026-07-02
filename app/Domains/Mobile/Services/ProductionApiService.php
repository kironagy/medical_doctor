<?php

namespace App\Domains\Mobile\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductionApiService
{
    protected string $baseUrl;
    protected ?string $token = null;
    protected int $timeout = 30;

    public function __construct()
    {
        $this->baseUrl = config('nativephp.production_api_url', env('PRODUCTION_API_URL', 'https://prof-hosam-fekry.online/api/mobile/v1'));
    }

    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function login(string $email, string $password, ?string $deviceName = null): ?array
    {
        $url = "{$this->baseUrl}/auth/login";
        $payload = [
            'email' => $email,
            'password' => $password,
            'device_name' => $deviceName ?? 'nativephp-android',
        ];
        Log::info('Production API login request', ['url' => $url, 'payload' => ['email' => $email, 'device_name' => $payload['device_name']]]);

        $response = Http::timeout($this->timeout)->post($url, $payload);

        Log::info('Production API login response', ['url' => $url, 'status' => $response->status(), 'body' => $response->body()]);

        if (!$response->successful()) {
            Log::error('Mobile sync login failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    public function pull(?string $lastSyncAt, array $entities = ['patients', 'files', 'visits', 'notes', 'categories', 'shares', 'doctors']): ?array
    {
        $url = "{$this->baseUrl}/sync/pull";
        $payload = [
            'last_sync_at' => $lastSyncAt,
            'entities' => $entities,
        ];
        Log::info('Production API pull request', ['url' => $url, 'payload' => $payload]);

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->post($url, $payload);

        Log::info('Production API pull response', ['url' => $url, 'status' => $response->status(), 'body' => $response->body()]);

        if (!$response->successful()) {
            Log::error('Mobile sync pull failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    public function push(array $patients = [], array $visits = [], array $notes = [], array $shares = [], array $files = []): ?array
    {
        $payload = [];
        if (!empty($patients)) $payload['patients'] = $patients;
        if (!empty($visits)) $payload['visits'] = $visits;
        if (!empty($notes)) $payload['notes'] = $notes;
        if (!empty($shares)) $payload['shares'] = $shares;
        if (!empty($files)) $payload['files'] = $files;

        if (empty($payload)) return null;

        $url = "{$this->baseUrl}/sync/push";
        Log::info('Production API push request', ['url' => $url, 'payload_keys' => array_keys($payload)]);

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->post($url, $payload);

        Log::info('Production API push response', ['url' => $url, 'status' => $response->status(), 'body' => $response->body()]);

        if (!$response->successful()) {
            Log::error('Mobile sync push failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    public function syncStatus(): ?array
    {
        $url = "{$this->baseUrl}/sync/status";
        Log::info('Production API syncStatus request', ['url' => $url]);

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->get($url);

        Log::info('Production API syncStatus response', ['url' => $url, 'status' => $response->status(), 'body' => $response->body()]);

        if (!$response->successful()) {
            Log::error('Mobile sync status failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    public function getFileMetadata(string $fileUuid): ?array
    {
        $url = "{$this->baseUrl}/media/{$fileUuid}/metadata";
        Log::info('Production API getFileMetadata request', ['url' => $url, 'file_uuid' => $fileUuid]);

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->get($url);

        Log::info('Production API getFileMetadata response', ['url' => $url, 'status' => $response->status()]);

        if (!$response->successful()) {
            Log::error('Mobile get file metadata failed', ['status' => $response->status()]);
            return null;
        }

        return $response->json();
    }

    public function downloadFile(string $fileUuid, ?callable $onProgress = null): ?string
    {
        $url = "{$this->baseUrl}/media/{$fileUuid}/download";
        Log::info('Production API downloadFile request', ['url' => $url, 'file_uuid' => $fileUuid]);

        $response = Http::timeout(0)
            ->withToken($this->token)
            ->get($url);

        Log::info('Production API downloadFile response', ['url' => $url, 'status' => $response->status()]);

        if (!$response->successful()) {
            Log::error('Mobile download file failed', ['status' => $response->status()]);
            return null;
        }

        return $response->body();
    }

    public function downloadFileRange(string $fileUuid, int $start, int $end): ?string
    {
        $url = "{$this->baseUrl}/media/{$fileUuid}/download";
        $headers = ['Range' => "bytes={$start}-{$end}"];
        Log::info('Production API downloadFileRange request', ['url' => $url, 'file_uuid' => $fileUuid, 'headers' => $headers]);

        $response = Http::timeout(30)
            ->withToken($this->token)
            ->withHeaders($headers)
            ->get($url);

        Log::info('Production API downloadFileRange response', ['url' => $url, 'status' => $response->status()]);

        if (!$response->successful()) {
            Log::error('Mobile download file range failed', ['status' => $response->status()]);
            return null;
        }

        return $response->body();
    }

    public function getThumbnail(string $fileUuid): ?string
    {
        $url = "{$this->baseUrl}/media/{$fileUuid}/thumbnail";
        Log::info('Production API getThumbnail request', ['url' => $url, 'file_uuid' => $fileUuid]);

        $response = Http::timeout(15)
            ->withToken($this->token)
            ->get($url);

        Log::info('Production API getThumbnail response', ['url' => $url, 'status' => $response->status()]);

        if (!$response->successful()) {
            Log::error('Mobile get thumbnail failed', ['status' => $response->status()]);
            return null;
        }

        return $response->body();
    }

    public function initChunkUpload(array $data): ?array
    {
        $url = "{$this->baseUrl}/chunk/init";
        Log::info('Production API initChunkUpload request', ['url' => $url, 'data_keys' => array_keys($data)]);

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->post($url, $data);

        Log::info('Production API initChunkUpload response', ['url' => $url, 'status' => $response->status(), 'body' => $response->body()]);

        if (!$response->successful()) {
            Log::error('Mobile init chunk upload failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    public function uploadChunk(string $sessionUuid, int $chunkIndex, $chunkData): bool
    {
        $url = "{$this->baseUrl}/chunk/{$sessionUuid}/upload";
        Log::info('Production API uploadChunk request', ['url' => $url, 'session_uuid' => $sessionUuid, 'chunk_index' => $chunkIndex]);

        $response = Http::timeout(60)
            ->withToken($this->token)
            ->attach('chunk', $chunkData, "chunk_{$chunkIndex}")
            ->post($url, [
                'chunk_index' => $chunkIndex,
            ]);

        Log::info('Production API uploadChunk response', ['url' => $url, 'status' => $response->status()]);

        return $response->successful();
    }

    public function completeChunkUpload(string $sessionUuid): ?array
    {
        $url = "{$this->baseUrl}/chunk/{$sessionUuid}/complete";
        Log::info('Production API completeChunkUpload request', ['url' => $url, 'session_uuid' => $sessionUuid]);

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->post($url);

        Log::info('Production API completeChunkUpload response', ['url' => $url, 'status' => $response->status(), 'body' => $response->body()]);

        if (!$response->successful()) {
            Log::error('Mobile complete chunk upload failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    public function checkChunkStatus(string $sessionUuid): ?array
    {
        $url = "{$this->baseUrl}/chunk/{$sessionUuid}/status";
        Log::info('Production API checkChunkStatus request', ['url' => $url, 'session_uuid' => $sessionUuid]);

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->get($url);

        Log::info('Production API checkChunkStatus response', ['url' => $url, 'status' => $response->status(), 'body' => $response->body()]);

        if (!$response->successful()) {
            Log::error('Mobile check chunk status failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    public function isOnline(): bool
    {
        try {
            Log::info('Checking if production server is online...', ['url' => "{$this->baseUrl}/sync/status"]);
            $response = Http::timeout(5)->get("{$this->baseUrl}/sync/status");
            Log::info('Production server check result', ['status' => $response->status(), 'success' => $response->successful()]);
            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Production server check failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return false;
        }
    }
}
