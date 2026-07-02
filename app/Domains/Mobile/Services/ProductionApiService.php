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
        Log::channel('mobile-api')->info('=== PRODUCTION API SERVICE INITIALIZED ===');
        $this->baseUrl = config('nativephp.production_api_url', env('PRODUCTION_API_URL', 'https://prof-hosam-fekry.online/api/mobile/v1'));
        Log::channel('mobile-api')->info('Base URL configured', ['base_url' => $this->baseUrl]);
        Log::channel('mobile-api')->info('Environment variables checked', [
            'PRODUCTION_API_URL' => env('PRODUCTION_API_URL'),
            'config_nativephp_production_api_url' => config('nativephp.production_api_url'),
        ]);
    }

    public function setToken(string $token): self
    {
        Log::channel('mobile-api')->info('Setting authentication token', ['token_length' => strlen($token)]);
        $this->token = $token;
        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        Log::channel('mobile-api')->info('Setting timeout', ['timeout_seconds' => $seconds]);
        $this->timeout = $seconds;
        return $this;
    }

    public function login(string $email, string $password, ?string $deviceName = null): ?array
    {
        Log::channel('mobile-api')->info('=== LOGIN START ===');
        $url = "{$this->baseUrl}/auth/login";
        $payload = [
            'email' => $email,
            'password' => $password,
            'device_name' => $deviceName ?? 'nativephp-android',
        ];
        Log::channel('mobile-api')->info('Creating login request', [
            'url' => $url,
            'method' => 'POST',
            'email' => $email,
            'device_name' => $payload['device_name'],
        ]);

        try {
            Log::channel('mobile-api')->info('HTTP CLIENT CREATED, sending request...');
            $response = Http::timeout($this->timeout)
                ->retry(3, 1000)
                ->acceptJson()
                ->post($url, $payload);
            Log::channel('mobile-api')->info('=== RESPONSE RECEIVED ===', [
                'url' => $url,
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Login FAILED', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $data = $response->json();
            Log::channel('mobile-api')->info('Login SUCCESS', ['has_token' => isset($data['token'])]);
            return $data;
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Login EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    public function pull(?string $lastSyncAt, array $entities = ['patients', 'files', 'visits', 'notes', 'categories', 'shares', 'doctors']): ?array
    {
        Log::channel('mobile-api')->info('=== PULL START ===');
        $url = "{$this->baseUrl}/sync/pull";
        $payload = [
            'last_sync_at' => $lastSyncAt,
            'entities' => $entities,
        ];
        Log::channel('mobile-api')->info('Creating pull request', [
            'url' => $url,
            'method' => 'POST',
            'last_sync_at' => $lastSyncAt,
            'entities' => $entities,
        ]);

        try {
            Log::channel('mobile-api')->info('Sending pull request with token...');
            $response = Http::timeout($this->timeout)
                ->retry(3, 1000)
                ->acceptJson()
                ->withToken($this->token)
                ->post($url, $payload);

            Log::channel('mobile-api')->info('=== PULL RESPONSE RECEIVED ===', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Pull FAILED', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Pull EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    public function push(array $patients = [], array $visits = [], array $notes = [], array $shares = [], array $files = []): ?array
    {
        Log::channel('mobile-api')->info('=== PUSH START ===');
        $payload = [];
        if (!empty($patients)) $payload['patients'] = $patients;
        if (!empty($visits)) $payload['visits'] = $visits;
        if (!empty($notes)) $payload['notes'] = $notes;
        if (!empty($shares)) $payload['shares'] = $shares;
        if (!empty($files)) $payload['files'] = $files;

        if (empty($payload)) {
            Log::channel('mobile-api')->info('Push cancelled - empty payload');
            return null;
        }

        $url = "{$this->baseUrl}/sync/push";
        Log::channel('mobile-api')->info('Creating push request', [
            'url' => $url,
            'method' => 'POST',
            'payload_keys' => array_keys($payload),
        ]);

        try {
            Log::channel('mobile-api')->info('Sending push request...');
            $response = Http::timeout($this->timeout)
                ->retry(3, 1000)
                ->acceptJson()
                ->withToken($this->token)
                ->post($url, $payload);

            Log::channel('mobile-api')->info('=== PUSH RESPONSE RECEIVED ===', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Push FAILED', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Push EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    public function getMe(): ?array
    {
        Log::channel('mobile-api')->info('=== GET ME START ===');
        $url = "{$this->baseUrl}/auth/me";
        Log::channel('mobile-api')->info('Creating get me request', [
            'url' => $url,
            'method' => 'GET',
        ]);

        try {
            Log::channel('mobile-api')->info('Sending get me request...');
            $response = Http::timeout($this->timeout)
                ->withToken($this->token)
                ->get($url);

            Log::channel('mobile-api')->info('=== GET ME RESPONSE RECEIVED ===', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Get me FAILED', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Get me EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    public function syncStatus(): ?array
    {
        Log::channel('mobile-api')->info('=== SYNC STATUS START ===');
        $url = "{$this->baseUrl}/sync/status";
        Log::channel('mobile-api')->info('Creating sync status request', [
            'url' => $url,
            'method' => 'GET',
        ]);

        try {
            Log::channel('mobile-api')->info('Sending sync status request...');
            $response = Http::timeout($this->timeout)
                ->withToken($this->token)
                ->get($url);

            Log::channel('mobile-api')->info('=== SYNC STATUS RESPONSE RECEIVED ===', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Sync status FAILED', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Sync status EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    public function getFileMetadata(string $fileUuid): ?array
    {
        Log::channel('mobile-api')->info('=== GET FILE METADATA START ===', ['file_uuid' => $fileUuid]);
        $url = "{$this->baseUrl}/media/{$fileUuid}/metadata";

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->token)
                ->get($url);

            Log::channel('mobile-api')->info('=== GET FILE METADATA RESPONSE ===', [
                'status' => $response->status(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Get file metadata FAILED', ['status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Get file metadata EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    public function downloadFile(string $fileUuid, ?callable $onProgress = null): ?string
    {
        Log::channel('mobile-api')->info('=== DOWNLOAD FILE START ===', ['file_uuid' => $fileUuid]);
        $url = "{$this->baseUrl}/media/{$fileUuid}/download";

        try {
            $response = Http::timeout(0)
                ->withToken($this->token)
                ->get($url);

            Log::channel('mobile-api')->info('=== DOWNLOAD FILE RESPONSE ===', [
                'status' => $response->status(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Download file FAILED', ['status' => $response->status()]);
                return null;
            }

            return $response->body();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Download file EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    public function downloadFileRange(string $fileUuid, int $start, int $end): ?string
    {
        Log::channel('mobile-api')->info('=== DOWNLOAD FILE RANGE START ===', ['file_uuid' => $fileUuid, 'start' => $start, 'end' => $end]);
        $url = "{$this->baseUrl}/media/{$fileUuid}/download";
        $headers = ['Range' => "bytes={$start}-{$end}"];

        try {
            $response = Http::timeout(30)
                ->withToken($this->token)
                ->withHeaders($headers)
                ->get($url);

            Log::channel('mobile-api')->info('=== DOWNLOAD FILE RANGE RESPONSE ===', [
                'status' => $response->status(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Download file range FAILED', ['status' => $response->status()]);
                return null;
            }

            return $response->body();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Download file range EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    public function getThumbnail(string $fileUuid): ?string
    {
        Log::channel('mobile-api')->info('=== GET THUMBNAIL START ===', ['file_uuid' => $fileUuid]);
        $url = "{$this->baseUrl}/media/{$fileUuid}/thumbnail";

        try {
            $response = Http::timeout(15)
                ->withToken($this->token)
                ->get($url);

            Log::channel('mobile-api')->info('=== GET THUMBNAIL RESPONSE ===', [
                'status' => $response->status(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Get thumbnail FAILED', ['status' => $response->status()]);
                return null;
            }

            return $response->body();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Get thumbnail EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    public function initChunkUpload(array $data): ?array
    {
        Log::channel('mobile-api')->info('=== INIT CHUNK UPLOAD START ===');
        $url = "{$this->baseUrl}/chunk/init";

        try {
            Log::channel('mobile-api')->info('Sending init chunk upload request...');
            $response = Http::timeout($this->timeout)
                ->withToken($this->token)
                ->post($url, $data);

            Log::channel('mobile-api')->info('=== INIT CHUNK UPLOAD RESPONSE ===', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Init chunk upload FAILED', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Init chunk upload EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    public function uploadChunk(string $sessionUuid, int $chunkIndex, $chunkData): bool
    {
        Log::channel('mobile-api')->info('=== UPLOAD CHUNK START ===', ['session_uuid' => $sessionUuid, 'chunk_index' => $chunkIndex]);
        $url = "{$this->baseUrl}/chunk/{$sessionUuid}/upload";

        try {
            $response = Http::timeout(60)
                ->withToken($this->token)
                ->attach('chunk', $chunkData, "chunk_{$chunkIndex}")
                ->post($url, [
                    'chunk_index' => $chunkIndex,
                ]);

            Log::channel('mobile-api')->info('=== UPLOAD CHUNK RESPONSE ===', [
                'status' => $response->status(),
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Upload chunk EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    public function completeChunkUpload(string $sessionUuid): ?array
    {
        Log::channel('mobile-api')->info('=== COMPLETE CHUNK UPLOAD START ===', ['session_uuid' => $sessionUuid]);
        $url = "{$this->baseUrl}/chunk/{$sessionUuid}/complete";

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->token)
                ->post($url);

            Log::channel('mobile-api')->info('=== COMPLETE CHUNK UPLOAD RESPONSE ===', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Complete chunk upload FAILED', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Complete chunk upload EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    public function checkChunkStatus(string $sessionUuid): ?array
    {
        Log::channel('mobile-api')->info('=== CHECK CHUNK STATUS START ===', ['session_uuid' => $sessionUuid]);
        $url = "{$this->baseUrl}/chunk/{$sessionUuid}/status";

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->token)
                ->get($url);

            Log::channel('mobile-api')->info('=== CHECK CHUNK STATUS RESPONSE ===', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::channel('mobile-api')->error('Check chunk status FAILED', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Check chunk status EXCEPTION', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    public function isOnline(): bool
    {
        Log::channel('mobile-api')->info('=== IS ONLINE CHECK START ===');
        try {
            $url = "{$this->baseUrl}/sync/status";
            Log::channel('mobile-api')->info('Checking server online status', ['url' => $url]);

            Log::channel('mobile-api')->info('Creating HTTP client...');
            $response = Http::timeout(5)->get($url);
            Log::channel('mobile-api')->info('=== IS ONLINE RESPONSE RECEIVED ===', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            // Any response means server is online (DNS, TLS, socket all worked)
            return true;
        } catch (\Exception $e) {
            Log::channel('mobile-api')->error('Is online check EXCEPTION (OFFLINE)', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}
