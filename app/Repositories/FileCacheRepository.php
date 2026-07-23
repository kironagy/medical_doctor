<?php

namespace App\Repositories;

use App\Contracts\Repositories\FileCacheRepositoryInterface;
use App\Domains\Media\Models\PatientFile;
use App\Services\Mobile\ApiService;
use App\Services\Mobile\FileCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileCacheRepository implements FileCacheRepositoryInterface
{
    /**
     * Maximum cache size in bytes (500 MB).
     */
    private const MAX_CACHE_SIZE = 500 * 1024 * 1024;

    public function __construct(
        private readonly FileCacheService $fileService,
        private readonly ApiService $api,
    ) {}

    /**
     * Stream a cached file to the WebView.
     *
     * Resolves the local path, updates last access time,
     * and delegates to FileCacheService for the StreamedResponse.
     */
    public function stream(string $fileUuid, ?string $rangeHeader = null, bool $isHead = false): StreamedResponse
    {
        $entry = $this->getEntry($fileUuid);

        if (!$entry) {
            abort(404, 'File not found in cache.');
        }

        $absolutePath = $this->fileService->resolvePath($entry->local_path);

        if (!$this->fileService->fileExists($absolutePath)) {
            $this->deleteEntry($fileUuid);
            abort(404, 'Cached file not found on disk.');
        }

        // Update last access time for LRU tracking
        $this->updateAccessedAt($fileUuid);

        return $this->fileService->streamFile(
            $absolutePath,
            $entry->mime_type,
            $entry->file_name,
            $rangeHeader,
            $isHead
        );
    }

    /**
     * Download a file from the remote API and cache it locally.
     *
     * 1. Checks if already cached (no-op if so).
     * 2. Verifies the file exists and user has access via PatientFile model.
     * 3. Ensures cache quota is not exceeded (LRU eviction if needed).
     * 4. Downloads via ApiService (Http::sink — streaming to disk).
     * 5. Records the cache entry in SQLite.
     */
    public function cache(string $fileUuid): array
    {
        // Already cached?
        if ($this->isCached($fileUuid)) {
            return $this->status($fileUuid);
        }

        // Verify file exists and user has access
        $file = PatientFile::where('uuid', $fileUuid)->firstOrFail();
        Gate::authorize('view', $file->patient);

        $extension = pathinfo($file->file_name, PATHINFO_EXTENSION) ?: 'bin';
        $destination = $this->fileService->buildDestination($fileUuid, $extension);

        // Ensure quota before downloading
        $this->ensureQuota($file->size);

        // Download via ApiService (streams to disk, no memory load)
        // Uses the stream endpoint to get raw file bytes, not the show endpoint (JSON).
        $success = $this->api->download('/files/' . $fileUuid . '/stream', $destination);

        if (!$success) {
            Log::warning('[FileCache] Failed to download file for caching', ['uuid' => $fileUuid]);
            throw new \RuntimeException('Failed to download file for caching.');
        }

        // Record in SQLite
        $relativePath = 'files/' . $fileUuid . '.' . $extension;
        $this->putEntry(
            $fileUuid,
            $file->patient->uuid,
            $file->file_name,
            $file->mime_type,
            $file->size,
            $relativePath
        );

        return $this->status($fileUuid);
    }

    /**
     * Return cache status for a file.
     */
    public function status(string $fileUuid): array
    {
        $entry = $this->getEntry($fileUuid);

        if (!$entry) {
            return [
                'cached'    => false,
                'file_uuid' => $fileUuid,
            ];
        }

        $absolutePath = $this->fileService->resolvePath($entry->local_path);
        $fileOnDisk = $this->fileService->fileExists($absolutePath);

        // Clean up orphaned metadata
        if (!$fileOnDisk) {
            $this->deleteEntry($fileUuid);
            return [
                'cached'    => false,
                'file_uuid' => $fileUuid,
            ];
        }

        return [
            'cached'       => true,
            'file_uuid'    => $entry->file_uuid,
            'file_name'    => $entry->file_name,
            'mime_type'    => $entry->mime_type,
            'size'         => (int) $entry->size,
            'cached_at'    => $entry->cached_at,
            'last_accessed' => $entry->last_accessed_at,
        ];
    }

    /**
     * Remove a single file from the local cache.
     */
    public function remove(string $fileUuid): void
    {
        $entry = $this->getEntry($fileUuid);

        if ($entry) {
            $absolutePath = $this->fileService->resolvePath($entry->local_path);
            $this->fileService->deleteFile($absolutePath);
            $this->deleteEntry($fileUuid);
        }
    }

    /**
     * Remove all cached files for a given patient.
     */
    public function removePatient(string $patientUuid): void
    {
        $entries = DB::table('file_cache')
            ->where('patient_uuid', $patientUuid)
            ->get();

        foreach ($entries as $entry) {
            $absolutePath = $this->fileService->resolvePath($entry->local_path);
            $this->fileService->deleteFile($absolutePath);
        }

        DB::table('file_cache')
            ->where('patient_uuid', $patientUuid)
            ->delete();
    }

    /**
     * Clear the entire file cache.
     */
    public function clear(): void
    {
        $this->fileService->clearDirectory();
        DB::table('file_cache')->truncate();
    }

    // ---------------------------------------------------------------
    //  Internal persistence methods
    // ---------------------------------------------------------------

    private function isCached(string $fileUuid): bool
    {
        return DB::table('file_cache')
            ->where('file_uuid', $fileUuid)
            ->exists();
    }

    private function getEntry(string $fileUuid): ?object
    {
        $entry = DB::table('file_cache')
            ->where('file_uuid', $fileUuid)
            ->first();

        return $entry ?: null;
    }

    private function putEntry(
        string $fileUuid,
        string $patientUuid,
        string $fileName,
        string $mimeType,
        int $size,
        string $localPath
    ): void {
        DB::table('file_cache')->insert([
            'file_uuid'        => $fileUuid,
            'patient_uuid'     => $patientUuid,
            'file_name'        => $fileName,
            'mime_type'        => $mimeType,
            'size'             => $size,
            'local_path'       => $localPath,
            'checksum'         => null,
            'cached_at'        => now(),
            'last_accessed_at' => now(),
        ]);
    }

    private function deleteEntry(string $fileUuid): void
    {
        DB::table('file_cache')
            ->where('file_uuid', $fileUuid)
            ->delete();
    }

    private function updateAccessedAt(string $fileUuid): void
    {
        DB::table('file_cache')
            ->where('file_uuid', $fileUuid)
            ->update(['last_accessed_at' => now()]);
    }

    /**
     * Ensure the total cache size does not exceed MAX_CACHE_SIZE.
     * Evicts LRU entries (oldest last_accessed_at first) until
     * there is enough room for the additional bytes.
     */
    private function ensureQuota(int $additionalBytes): void
    {
        $currentTotal = (int) DB::table('file_cache')
            ->sum('size');

        $needed = $currentTotal + $additionalBytes;

        if ($needed <= self::MAX_CACHE_SIZE) {
            return;
        }

        $bytesToFree = $needed - self::MAX_CACHE_SIZE;

        // Fetch LRU entries ordered by oldest last_accessed_at
        $lruEntries = DB::table('file_cache')
            ->orderBy('last_accessed_at', 'asc')
            ->get();

        $freed = 0;

        foreach ($lruEntries as $entry) {
            if ($freed >= $bytesToFree) {
                break;
            }

            $absolutePath = $this->fileService->resolvePath($entry->local_path);
            $this->fileService->deleteFile($absolutePath);
            $this->deleteEntry($entry->file_uuid);

            $freed += $entry->size;
        }
    }
}
