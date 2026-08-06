<?php

namespace App\Services\Upload;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves a writable temp-file location under the app's own storage root
 * instead of relying on sys_get_temp_dir()/tempnam(), which resolve to
 * /tmp — a path that does not exist inside the Android app sandbox.
 */
class UploadTempFileResolver
{
    private const RELATIVE_DIR = 'app/private/tmp/uploads';

    /**
     * Absolute path to the app-owned temp-upload directory. Created lazily on
     * every call (not just once at boot) because Android may evict cacheDir
     * and this directory must be able to recover mid-session.
     */
    public function directory(): string
    {
        $path = storage_path(self::RELATIVE_DIR);

        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            throw new HttpException(507, "Upload temporary storage unavailable: {$path}");
        }

        return $path;
    }

    /**
     * Open a new, uniquely-named temp file for exclusive writing. Returns the
     * absolute path and an open file handle. Never falls back to the system
     * temp directory.
     */
    public function open(string $prefix = 'nphp_upl_'): array
    {
        $dir = $this->directory();
        $path = $dir . DIRECTORY_SEPARATOR . uniqid($prefix, true);

        $fp = @fopen($path, 'xb');
        if ($fp === false) {
            throw new HttpException(507, "Upload temporary storage unavailable: {$path}");
        }

        return [$path, $fp];
    }
}
