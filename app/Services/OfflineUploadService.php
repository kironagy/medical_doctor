<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OfflineUploadService
{
    /**
     * Directory under storage/ where pending upload files are kept.
     * Never inside public/ — always storage/app/uploads/pending/
     */
    private const PENDING_DIR = 'uploads/pending';

    /**
     * Save an uploaded file to the pending uploads directory.
     *
     * Steps:
     * 1. Generate a UUID for the local file
     * 2. Determine extension from the original file
     * 3. Store the file in storage/app/uploads/pending/{uuid}.{ext}
     * 4. Calculate SHA-256 hash for integrity verification
     * 5. Return metadata array suitable for OfflineFileRepository::create()
     *
     * @param  UploadedFile  $file         The file to persist locally
     * @param  string        $patientUuid  The patient UUID this file belongs to
     * @return array  Metadata for the persisted file
     */
    public function saveLocally(UploadedFile $file, string $patientUuid): array
    {
        $uuid = (string) Str::uuid();
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $size = $file->getSize();

        // Store in pending directory using storeAs — never load into memory
        $relativePath = $file->storeAs(
            self::PENDING_DIR,
            "{$uuid}.{$extension}",
            'local'
        );

        if ($relativePath === false) {
            throw new \RuntimeException('Failed to store file in pending uploads directory.');
        }

        // Calculate SHA-256 hash via streaming (no full memory load)
        $hash = $this->calculateHash(
            Storage::disk('local')->path($relativePath)
        );

        Log::info('[OfflineUpload] File saved locally', [
            'uuid'         => $uuid,
            'patient_uuid' => $patientUuid,
            'original'     => $originalName,
            'path'         => $relativePath,
            'size'         => $size,
            'mime'         => $mimeType,
        ]);

        // Determine file type category
        $type = match (true) {
            str_starts_with($mimeType, 'image/')           => 'image',
            str_starts_with($mimeType, 'video/')           => 'video',
            str_starts_with($mimeType, 'audio/')           => 'audio',
            str_starts_with($mimeType, 'application/pdf')  => 'pdf',
            default                                        => 'document',
        };

        return [
            'uuid'          => $uuid,
            'patient_uuid'  => $patientUuid,
            'local_path'    => $relativePath,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'extension'     => $extension,
            'size'          => $size,
            'hash'          => $hash,
            'type'          => $type,
        ];
    }

    /**
     * Get the absolute filesystem path for a relative pending path.
     */
    public function absolutePath(string $relativePath): string
    {
        return Storage::disk('local')->path($relativePath);
    }

    /**
     * Check if a local pending file still exists on disk.
     */
    public function fileExists(string $relativePath): bool
    {
        return Storage::disk('local')->exists($relativePath);
    }

    /**
     * Delete a pending file from disk.
     */
    public function deleteLocal(string $relativePath): bool
    {
        if ($this->fileExists($relativePath)) {
            return Storage::disk('local')->delete($relativePath);
        }
        return true;
    }

    /**
     * Delete a pending file and its parent directory if empty.
     */
    public function deleteLocalWithCleanup(string $relativePath): void
    {
        $this->deleteLocal($relativePath);

        // Attempt to clean up empty parent directory
        $dir = dirname(Storage::disk('local')->path($relativePath));
        if (is_dir($dir) && count(scandir($dir)) <= 2) {
            @rmdir($dir);
        }
    }

    /**
     * Calculate SHA-256 hash of a file using streaming reads.
     * Never loads the entire file into memory.
     */
    private function calculateHash(string $absolutePath): string
    {
        $ctx = hash_init('sha256');
        $fp = fopen($absolutePath, 'rb');

        if (!$fp) {
            Log::warning('[OfflineUpload] Cannot open file for hashing', ['path' => $absolutePath]);
            return '';
        }

        $buf = 1024 * 1024; // 1 MB buffer
        while (!feof($fp)) {
            hash_update($ctx, fread($fp, $buf));
        }
        fclose($fp);

        return hash_final($ctx);
    }
}
