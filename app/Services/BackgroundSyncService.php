<?php

namespace App\Services;

use App\Services\Sync\IncrementalSyncService;
use Illuminate\Support\Facades\Log;

class BackgroundSyncService
{
    private bool $isRunning = false;
    private ?\Carbon\Carbon $lastRunAt = null;

    public function run(): void
    {
        if ($this->isRunning) {
            Log::info('[BackgroundSync] Sync already running, skipping.');
            return;
        }

        if ($this->lastRunAt && $this->lastRunAt->diffInSeconds(now()) < 10) {
            Log::info('[BackgroundSync] Debounced — last run was less than 10s ago.');
            return;
        }

        if (!NetworkStatusService::isOnline()) {
            Log::info('[BackgroundSync] Offline — skipping sync.');
            return;
        }

        $this->isRunning = true;
        $this->lastRunAt = now();

        Log::info('[BackgroundSync] Starting background sync cycle...');

        try {
            $fullSync = app(FullSyncService::class);
            $fullSync->syncMetadataOnly();

            Log::info('[BackgroundSync] Background sync cycle completed.');
        } catch (\Throwable $e) {
            Log::error('[BackgroundSync] Background sync failed: ' . $e->getMessage());
        } finally {
            $this->isRunning = false;
        }
    }



    public function onConnectivityRestored(): void
    {
        Log::info('[BackgroundSync] Connectivity restored — triggering background sync.');
        $this->run();
    }

    public function onAppForegrounded(): void
    {
        Log::info('[BackgroundSync] App foregrounded — triggering background sync.');
        $this->run();
    }

    public function getLastRunAt(): ?\Carbon\Carbon
    {
        return $this->lastRunAt;
    }

    public function isSyncRunning(): bool
    {
        return $this->isRunning;
    }
}
