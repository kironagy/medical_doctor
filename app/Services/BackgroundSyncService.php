<?php

namespace App\Services;

use App\Services\Sync\IncrementalSyncService;
use App\Services\Sync\SyncManager;
use Illuminate\Support\Facades\Log;

class BackgroundSyncService
{
    private bool $isRunning = false;
    private ?\Carbon\Carbon $lastRunAt = null;

    /**
     * Run a background sync cycle.
     * Safe to call frequently — has built-in debouncing and dedup.
     */
    public function run(): void
    {
        if ($this->isRunning) {
            Log::info('[BackgroundSync] Sync already running, skipping.');
            return;
        }

        // Debounce: don't run more than once every 10 seconds
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
            // Quick push of pending operations first
            $syncManager = app(SyncManager::class);
            $syncManager->pushPending();

            // Then incremental pull
            $incrementalSync = app(IncrementalSyncService::class);
            $incrementalSync->incrementalPull();

            Log::info('[BackgroundSync] Background sync cycle completed.');
        } catch (\Throwable $e) {
            Log::error('[BackgroundSync] Background sync failed: ' . $e->getMessage());
        } finally {
            $this->isRunning = false;
        }
    }

    /**
     * Run a full sync (push + full metadata pull).
     * Use sparingly — this is more expensive than incremental sync.
     */
    public function runFull(): void
    {
        if ($this->isRunning) {
            Log::info('[BackgroundSync] Full sync already running, skipping.');
            return;
        }

        if (!NetworkStatusService::isOnline()) {
            Log::info('[BackgroundSync] Full sync: offline — skipping.');
            return;
        }

        $this->isRunning = true;
        $this->lastRunAt = now();

        Log::info('[BackgroundSync] Starting full sync...');

        try {
            $syncManager = app(SyncManager::class);
            $syncManager->pullMetadata();
            Log::info('[BackgroundSync] Full sync completed.');
        } catch (\Throwable $e) {
            Log::error('[BackgroundSync] Full sync failed: ' . $e->getMessage());
        } finally {
            $this->isRunning = false;
        }
    }

    /**
     * Notify the service that connectivity has been restored.
     * Triggers a sync cycle with smart debouncing.
     */
    public function onConnectivityRestored(): void
    {
        Log::info('[BackgroundSync] Connectivity restored — triggering background sync.');
        $this->run();
    }

    /**
     * Notify the service that app has come to foreground.
     */
    public function onAppForegrounded(): void
    {
        Log::info('[BackgroundSync] App foregrounded — triggering background sync.');
        $this->run();
    }

    /**
     * Get the last run time.
     */
    public function getLastRunAt(): ?\Carbon\Carbon
    {
        return $this->lastRunAt;
    }

    /**
     * Check if sync is currently running.
     */
    public function isSyncRunning(): bool
    {
        return $this->isRunning;
    }
}
