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
        $response = Http::timeout($this->timeout)
            ->post("{$this->baseUrl}/auth/login", [
                'email' => $email,
                'password' => $password,
                'device_name' => $deviceName ?? 'nativephp-android',
            ]);

        if (!$response->successful()) {
            Log::error('Mobile sync login failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        return $response->json();
    }

    public function pull(?string $lastSyncAt, array $entities = ['patients', 'files', 'visits', 'notes', 'categories', 'shares', 'doctors']): ?array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->post("{$this->baseUrl}/sync/pull", [
                'last_sync_at' => $lastSyncAt,
                'entities' => $entities,
            ]);

        if (!$response->successful()) {
            Log::error('Mobile sync pull failed', ['status' => $response->status()]);
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

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->post("{$this->baseUrl}/sync/push", $payload);

        if (!$response->successful()) {
            Log::error('Mobile sync push failed', ['status' => $response->status()]);
            return null;
        }

        return $response->json();
    }

    public function syncStatus(): ?array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->get("{$this->baseUrl}/sync/status");

        if (!$response->successful()) return null;

        return $response->json();
    }

    public function getFileMetadata(string $fileUuid): ?array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->get("{$this->baseUrl}/media/{$fileUuid}/metadata");

        if (!$response->successful()) return null;

        return $response->json();
    }

    public function downloadFile(string $fileUuid, ?callable $onProgress = null): ?string
    {
        $response = Http::timeout(0)
            ->withToken($this->token)
            ->get("{$this->baseUrl}/media/{$fileUuid}/download");

        if (!$response->successful()) return null;

        return $response->body();
    }

    public function downloadFileRange(string $fileUuid, int $start, int $end): ?string
    {
        $response = Http::timeout(30)
            ->withToken($this->token)
            ->withHeaders(['Range' => "bytes={$start}-{$end}"])
            ->get("{$this->baseUrl}/media/{$fileUuid}/download");

        if (!$response->successful()) return null;

        return $response->body();
    }

    public function getThumbnail(string $fileUuid): ?string
    {
        $response = Http::timeout(15)
            ->withToken($this->token)
            ->get("{$this->baseUrl}/media/{$fileUuid}/thumbnail");

        if (!$response->successful()) return null;

        return $response->body();
    }

    public function initChunkUpload(array $data): ?array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->post("{$this->baseUrl}/chunk/init", $data);

        if (!$response->successful()) return null;

        return $response->json();
    }

    public function uploadChunk(string $sessionUuid, int $chunkIndex, $chunkData): bool
    {
        $response = Http::timeout(60)
            ->withToken($this->token)
            ->attach('chunk', $chunkData, "chunk_{$chunkIndex}")
            ->post("{$this->baseUrl}/chunk/{$sessionUuid}/upload", [
                'chunk_index' => $chunkIndex,
            ]);

        return $response->successful();
    }

    public function completeChunkUpload(string $sessionUuid): ?array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->post("{$this->baseUrl}/chunk/{$sessionUuid}/complete");

        if (!$response->successful()) return null;

        return $response->json();
    }

    public function checkChunkStatus(string $sessionUuid): ?array
    {
        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->get("{$this->baseUrl}/chunk/{$sessionUuid}/status");

        if (!$response->successful()) return null;

        return $response->json();
    }

    public function isOnline(): bool
    {
        try {
            $response = Http::timeout(5)->head($this->baseUrl);
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
