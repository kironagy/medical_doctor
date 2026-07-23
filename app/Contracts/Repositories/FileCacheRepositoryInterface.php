<?php

namespace App\Contracts\Repositories;

use Symfony\Component\HttpFoundation\StreamedResponse;

interface FileCacheRepositoryInterface
{
    /**
     * Stream a cached file to the WebView with Range support.
     *
     * @param  string      $fileUuid    File UUID to stream
     * @param  string|null $rangeHeader Value of the HTTP Range header (for video seeking)
     * @param  bool        $isHead      True if this is a HEAD request
     */
    public function stream(string $fileUuid, ?string $rangeHeader = null, bool $isHead = false): StreamedResponse;

    /**
     * Download a file from the remote API and cache it locally.
     */
    public function cache(string $fileUuid): array;

    /**
     * Check if a file is cached and return its status.
     */
    public function status(string $fileUuid): array;

    /**
     * Remove a single file from the local cache.
     */
    public function remove(string $fileUuid): void;

    /**
     * Remove all cached files for a given patient.
     */
    public function removePatient(string $patientUuid): void;

    /**
     * Clear the entire file cache.
     */
    public function clear(): void;
}
