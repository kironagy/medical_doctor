<?php

namespace App\Services\Sync;

use App\Domains\Media\Models\PatientFile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CacheCleanupService
{
    /**
     * Clean up old local binary files that are already uploaded and synced to production.
     *
     * Rules:
     * 1. Only delete local binary files if sync_status = 'synced' AND remote_uuid is NOT NULL.
     * 2. NEVER delete SQLite metadata records.
     * 3. Retention period is configurable (e.g. 30, 60, 90 days).
     *
     * @param int $retentionDays Days after which local binary cache is pruned
     * @return int Count of cleaned files
     */
    public function cleanupBinaryCache(int $retentionDays = 30): int
    {
        $cleanedCount = 0;
        $cutoff = Carbon::now()->subDays($retentionDays);

        $syncedFiles = PatientFile::withoutGlobalScope(\App\Domains\Auth\Scopes\DoctorIsolationScope::class)
            ->where('sync_status', 'synced')
            ->whereNotNull('remote_uuid')
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($syncedFiles as $file) {
            if ($file->file_path && Storage::disk('local')->exists($file->file_path)) {
                try {
                    Storage::disk('local')->delete($file->file_path);
                    $cleanedCount++;
                    Log::info("[CacheCleanupService] Pruned local binary cache for file {$file->uuid} (older than {$retentionDays} days)");
                } catch (Throwable $e) {
                    Log::warning("[CacheCleanupService] Failed to prune binary cache for {$file->uuid}: " . $e->getMessage());
                }
            }
        }

        return $cleanedCount;
    }
}
