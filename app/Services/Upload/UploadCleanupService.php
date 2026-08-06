<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadCleanupService
{
    public function purgeExpired(int $hours = 6): int
    {
        $expired = UploadSession::where('expires_at', '<', now())
            ->whereIn('status', ['pending', 'uploading'])
            ->get();

        $count = 0;
        foreach ($expired as $session) {
            $disk = Storage::disk($session->disk);

            // ── FIX-REL-3 item 2 (legacy path): delete the chunk staging directory.
            $chunkDir = $session->chunkDir();
            if ($disk->exists($chunkDir)) {
                $disk->deleteDirectory($chunkDir);
            }

            // ── FIX-REL-3 item 2 (direct-write path): delete the partial file
            // at final_path. The direct-write path writes directly into the
            // patient's storage directory as each chunk arrives; when a session
            // expires (e.g., client abandoned after a network drop) the partial
            // file was left on disk indefinitely because only the chunk-directory
            // cleanup above ran. Guarded by existence check so sessions that
            // completed successfully (final_path already moved/committed) are
            // not affected.
            if ($session->final_path && $disk->exists($session->final_path)) {
                $disk->delete($session->final_path);
                Log::channel('upload')->info('cleanup:direct_write_partial_deleted', [
                    'session'    => $session->uuid,
                    'final_path' => $session->final_path,
                ]);
            }

            $session->update(['status' => 'expired']);
            $count++;
        }

        $count += $this->purgeOrphanedTempFiles($hours);

        return $count;
    }

    /**
     * Sweep app-owned upload temp files (UploadTempFileResolver) older than
     * $hours. These live under app storage rather than the OS temp dir, so
     * the OS will never sweep them — this command is their only cleanup path.
     */
    private function purgeOrphanedTempFiles(int $hours): int
    {
        $dir = storage_path('app/private/tmp/uploads');
        if (!is_dir($dir)) {
            return 0;
        }

        $cutoff = now()->subHours($hours)->getTimestamp();
        $count = 0;

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && (filemtime($path) ?: 0) < $cutoff) {
                @unlink($path);
                $count++;
            }
        }

        return $count;
    }

    public function purgeByUuid(string $uuid): bool
    {
        $session = UploadSession::where('uuid', $uuid)->first();
        if (!$session) return false;

        $disk = Storage::disk($session->disk);

        // Legacy chunk directory
        $chunkDir = $session->chunkDir();
        if ($disk->exists($chunkDir)) {
            $disk->deleteDirectory($chunkDir);
        }

        // ── FIX-REL-3 item 2 (direct-write path): also delete partial file
        // at final_path for the same reason as purgeExpired above.
        if ($session->final_path && $disk->exists($session->final_path)) {
            $disk->delete($session->final_path);
        }

        $session->delete();
        return true;
    }
}
