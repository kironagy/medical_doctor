<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Log;

class ConflictResolverService
{
    /**
     * Determine conflict status based on versioning.
     *
     * @return 'upload'|'download'|'conflict'
     */
    public function resolve(int $localVersion, int $serverVersion, bool $localModified = false, bool $serverModified = false): string
    {
        if ($localModified && $serverModified && $localVersion === $serverVersion) {
            Log::warning('[ConflictResolverService] Conflict detected: both local and server modified at same version', [
                'local_version'  => $localVersion,
                'server_version' => $serverVersion,
            ]);
            return 'conflict';
        }

        if ($serverVersion > $localVersion) {
            return 'download';
        }

        if ($localVersion > $serverVersion) {
            return 'upload';
        }

        return $localModified ? 'upload' : 'download';
    }
}
