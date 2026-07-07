<?php

namespace App\Services\Upload;

use App\Domains\Media\Models\UploadSession;
use Illuminate\Support\Facades\Storage;

class UploadCleanupService
{
    public function purgeExpired(): int
    {
        $expired = UploadSession::where('expires_at', '<', now())
            ->whereIn('status', ['pending', 'uploading'])
            ->get();

        $count = 0;
        foreach ($expired as $session) {
            $disk = Storage::disk($session->disk);
            $chunkDir = $session->chunkDir();
            if ($disk->exists($chunkDir)) {
                $disk->deleteDirectory($chunkDir);
            }
            $session->update(['status' => 'expired']);
            $count++;
        }
        return $count;
    }

    public function purgeByUuid(string $uuid): bool
    {
        $session = UploadSession::where('uuid', $uuid)->first();
        if (!$session) return false;

        $disk = Storage::disk($session->disk);
        $chunkDir = $session->chunkDir();
        if ($disk->exists($chunkDir)) {
            $disk->deleteDirectory($chunkDir);
        }
        $session->delete();
        return true;
    }
}
