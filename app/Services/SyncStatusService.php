<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncStatusService
{
    private const STATUS_KEY = 'patients_sync_status';
    private const LAST_SYNC_KEY = 'patients_last_sync';

    public function setStatus(string $status): void
    {
        $allowed = ['idle', 'syncing', 'success', 'failed'];

        if (!in_array($status, $allowed, true)) {
            Log::warning('[SyncStatusService] Invalid status: ' . $status);
            return;
        }

        DB::table('sync_meta')->updateOrInsert(
            ['key' => self::STATUS_KEY],
            ['value' => $status, 'updated_at' => now()]
        );

        if ($status === 'success') {
            $this->setLastSync();
        }
    }

    public function getStatus(): string
    {
        $row = DB::table('sync_meta')->where('key', self::STATUS_KEY)->first();

        return $row ? $row->value : 'idle';
    }

    public function getLastSync(): ?string
    {
        $row = DB::table('sync_meta')->where('key', self::LAST_SYNC_KEY)->first();

        return $row ? $row->value : null;
    }

    private function setLastSync(): void
    {
        DB::table('sync_meta')->updateOrInsert(
            ['key' => self::LAST_SYNC_KEY],
            ['value' => now()->toDateTimeString(), 'updated_at' => now()]
        );
    }
}
