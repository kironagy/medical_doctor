<?php

namespace App\Services\Mobile;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * RemoteApiService — Single Gateway for Remote Production API Communication
 * ───────────────────────────────────────────────────────────────────────────
 *
 * In the Offline-First Architecture, this is the ONLY class in the entire
 * application authorized to make HTTP, Guzzle, or API requests to the
 * remote production server.
 *
 * Capabilities:
 *   - Bearer / Sanctum token management & file persistence
 *   - Automatic timeout handling (JSON vs large file uploads)
 *   - Retries with exponential backoff
 *   - Unified file & video multipart upload gateway
 * ───────────────────────────────────────────────────────────────────────────
 */
class RemoteApiService
{
    private const TOKEN_FILE_PATH = 'app/.api_sync_token';

    private ?string $token = null;

    public function __construct()
    {
        $encrypted = session('api_token');
        if ($encrypted) {
            try {
                $this->token = decrypt($encrypted);
            } catch (Throwable $e) {
                $this->token = null;
            }
        }

        if (empty($this->token)) {
            $this->loadTokenFromFile();
        }
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
        if ($token) {
            try {
                session(['api_token' => encrypt($token)]);
                $this->writeTokenToFile($token);
            } catch (Throwable $e) {
                Log::error('[RemoteApiService] Failed to store token: ' . $e->getMessage());
            }
        } else {
            session()->forget('api_token');
            $this->deleteTokenFile();
        }
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Send GET request to production server.
     */
    public function get(string $endpoint, array $query = [], int $timeoutSeconds = 30): array
    {
        return $this->request('GET', $endpoint, ['query' => $query], $timeoutSeconds);
    }

    /**
     * Send POST request to production server.
     */
    public function post(string $endpoint, array $data = [], int $timeoutSeconds = 30): array
    {
        return $this->request('POST', $endpoint, ['json' => $data], $timeoutSeconds);
    }

    /**
     * Send PUT request to production server.
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, ['json' => $data]);
    }

    /**
     * Send DELETE request to production server.
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Upload a file or video via multipart request to production server.
     */
    public function upload(string $endpoint, array $files, array $data = []): array
    {
        $client = $this->buildClient(timeoutSeconds: 300);

        foreach ($files as $name => $filePath) {
            if (!file_exists($filePath)) {
                throw new RuntimeException("File not found on disk: {$filePath}");
            }
            $contents = fopen($filePath, 'r');
            $client->attach($name, $contents, basename($filePath));
        }

        $url = $this->resolveUrl($endpoint);
        $response = $client->post($url, $data);

        if (!$response->successful()) {
            throw new RuntimeException("Upload to {$endpoint} failed [HTTP {$response->status()}]: " . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Download a file from production server and sink directly to disk.
     *
     * Default timeout raised from the original 180s — that was tuned for
     * small already-synced files (the old lazy file-cache path), but
     * OfflinePackageService uses this same method to pull EVERY file in a
     * downloaded patient's package, including full-size videos, which can
     * easily exceed 180s on a slow mobile connection. A short timeout there
     * doesn't fail gracefully — it aborts the whole package download.
     */
    public function download(string $endpoint, string $destinationPath, int $timeoutSeconds = 900): bool
    {
        $url = $this->resolveUrl($endpoint);

        try {
            $dir = dirname($destinationPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $headers = ['Accept' => '*/*'];
            if ($this->token) {
                $headers['Authorization'] = 'Bearer ' . $this->token;
            }

            $response = Http::timeout($timeoutSeconds)
                ->withHeaders($headers)
                ->withOptions(['sink' => $destinationPath])
                ->get($url);

            $success = $response->successful() && file_exists($destinationPath) && filesize($destinationPath) > 0;
            if (!$success && file_exists($destinationPath)) {
                @unlink($destinationPath);
            }
            return $success;
        } catch (\Throwable $e) {
            Log::error('[RemoteApiService] download failed for ' . $endpoint . ': ' . $e->getMessage());
            if (file_exists($destinationPath)) {
                @unlink($destinationPath);
            }
            return false;
        }
    }

    /**
     * Download several files concurrently instead of one blocking request
     * per file — an offline-package pull with N files previously took N
     * sequential round trips (connection setup + wait, one at a time). A
     * small fixed pool overlaps the network wait time across files while
     * still writing each one to its own destination path, so a slow/failed
     * file can't corrupt another — same per-file success/failure contract
     * as download(), just batched.
     *
     * @param  array<string, string>  $jobs  endpoint => destinationPath
     * @param  ?callable  $onBatchComplete  Invoked after each pooled batch
     *         finishes with (array<string,bool> $batchResults) — endpoint =>
     *         success, scoped to just that batch. Lets a caller (currently
     *         OfflinePackageService) report incremental progress without this
     *         method knowing anything about notifications or file sizes.
     * @return array<string, bool>  endpoint => success
     */
    public function downloadMany(array $jobs, int $timeoutSeconds = 900, int $poolSize = 4, ?callable $onBatchComplete = null): array
    {
        $results = [];
        foreach (array_chunk($jobs, $poolSize, true) as $batch) {
            foreach ($batch as $destinationPath) {
                $dir = dirname($destinationPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
            }

            $headers = ['Accept' => '*/*'];
            if ($this->token) {
                $headers['Authorization'] = 'Bearer ' . $this->token;
            }

            $responses = Http::pool(fn ($pool) => array_map(
                fn ($endpoint, $destinationPath) => $pool->as($endpoint)
                    ->timeout($timeoutSeconds)
                    ->withHeaders($headers)
                    ->withOptions(['sink' => $destinationPath])
                    ->get($this->resolveUrl($endpoint)),
                array_keys($batch),
                array_values($batch)
            ));

            foreach ($batch as $endpoint => $destinationPath) {
                $response = $responses[$endpoint] ?? null;
                $success = !($response instanceof \Throwable)
                    && $response?->successful()
                    && file_exists($destinationPath)
                    && filesize($destinationPath) > 0;

                if (!$success) {
                    if ($response instanceof \Throwable) {
                        Log::error('[RemoteApiService] pooled download failed for ' . $endpoint . ': ' . $response->getMessage());
                    }
                    if (file_exists($destinationPath)) {
                        @unlink($destinationPath);
                    }
                }
                $results[$endpoint] = $success;
            }

            if ($onBatchComplete) {
                $onBatchComplete(array_intersect_key($results, $batch));
            }
        }

        return $results;
    }

    /**
     * Internal request builder with retry support.
     */
    private function request(string $method, string $endpoint, array $options = [], int $timeoutSeconds = 30): array
    {
        $client = $this->buildClient(timeoutSeconds: $timeoutSeconds);
        $url = $this->resolveUrl($endpoint);

        $response = match (strtoupper($method)) {
            'GET'    => $client->get($url, $options['query'] ?? []),
            'POST'   => $client->post($url, $options['json'] ?? []),
            'PUT'    => $client->put($url, $options['json'] ?? []),
            'DELETE' => $client->delete($url, $options['json'] ?? []),
            default  => throw new RuntimeException("Unsupported HTTP method: {$method}"),
        };

        if (!$response->successful()) {
            if ($response->status() === 401) {
                $this->setToken(null);
                throw new \Illuminate\Auth\AuthenticationException("Authentication token expired or invalid (HTTP 401).");
            }
            throw new RuntimeException("API {$method} {$endpoint} failed [HTTP {$response->status()}]: " . $response->body());
        }

        return $response->json() ?? [];
    }

    private function buildClient(int $timeoutSeconds = 30): PendingRequest
    {
        $client = Http::timeout($timeoutSeconds)
            ->retry(2, 500)
            ->withHeaders(['Accept' => 'application/json']);

        if ($this->token) {
            $client->withToken($this->token);
        }

        return $client;
    }

    /**
     * Resolve an endpoint to its full production URL.
     *
     * Three disjoint route families live on production (routes/api.php and
     * routes/web.php's unconditional api/v1 group):
     *   /api/v1/mobile/*  — patients, notes, visits, files, etc. (nested
     *                        under the 'mobile' prefix group)
     *   /api/v1/chunk/*   — chunk upload endpoints (a SIBLING of the mobile
     *                        group, explicitly NOT nested under it — see the
     *                        "Single source of truth for /api/v1/chunk/*"
     *                        comment there)
     *   /api/v1/files/*   — raw file bytes/metadata (FileAccessController,
     *                        routes/web.php:199-204), also a sibling of
     *                        /mobile. Used by OfflinePackageService to pull
     *                        a downloaded patient's file bytes.
     *
     * config('app.mobile_api_url') is `{production host}/api/v1/mobile`.
     * Every FileSyncService::uploadLargeFileResumable() call passed
     * '/chunk/init' etc. through that base, producing /api/v1/mobile/chunk/init
     * — a URL that was never registered, so production 404'd every single
     * chunk upload (confirmed via nginx access log: "POST
     * /api/v1/mobile/chunk/init ... 404"). Every other endpoint (patients,
     * notes, files) genuinely does live under /mobile and was unaffected —
     * this bug was 100% specific to file uploads, which is exactly the
     * symptom reported: sync "completes" (patients/notes/deletes all push
     * fine) but files never reach the server. Route /chunk/* to the /api/v1
     * root instead.
     *
     * That root MUST be derived from mobile_api_url's own host, not from
     * config('app.url') — app.url is "http://127.0.0.1", the loopback
     * address the on-device embedded server binds to locally. It is not
     * reachable as an outbound destination from this HTTP client, so chunk
     * calls built on it never reach anything (confirmed: zero upload_sessions
     * rows created on production for any newly-synced file, and curl to
     * {app.url}/api/v1/chunk/init does not resolve to production at all).
     * mobile_api_url's host is the actual production domain, so strip its
     * trailing /mobile segment instead of substituting a different base.
     */
    private function resolveUrl(string $endpoint): string
    {
        $endpoint = '/' . ltrim($endpoint, '/');

        if (str_starts_with($endpoint, '/chunk/') || str_starts_with($endpoint, '/files/')) {
            $mobileBase = rtrim(config('app.mobile_api_url', config('app.url')), '/');
            $apiRoot = preg_replace('#/mobile$#', '', $mobileBase);
            return $apiRoot . $endpoint;
        }

        $baseUrl = rtrim(config('app.mobile_api_url', config('app.url')), '/');
        return $baseUrl . $endpoint;
    }

    private function loadTokenFromFile(): void
    {
        $path = storage_path(self::TOKEN_FILE_PATH);
        if (file_exists($path)) {
            try {
                $contents = file_get_contents($path);
                if ($contents) {
                    $this->token = decrypt($contents);
                }
            } catch (Throwable $e) {
                @unlink($path);
            }
        }
    }

    private function writeTokenToFile(string $token): void
    {
        try {
            $path = storage_path(self::TOKEN_FILE_PATH);
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, encrypt($token), LOCK_EX);
        } catch (Throwable $e) {
            Log::warning('[RemoteApiService] writeTokenToFile failed: ' . $e->getMessage());
        }
    }

    private function deleteTokenFile(): void
    {
        $path = storage_path(self::TOKEN_FILE_PATH);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
