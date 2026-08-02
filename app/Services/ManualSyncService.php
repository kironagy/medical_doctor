<?php

namespace App\Services;

use App\Services\Mobile\RemoteApiService;
use App\Domains\Sync\Services\SyncQueueService;
use App\Domains\Sync\Models\SyncQueue;
use App\Services\Sync\PatientSyncService;
use App\Services\Sync\NoteSyncService;
use App\Services\Sync\VisitSyncService;
use App\Services\Sync\FileSyncService;
use App\Services\Sync\CategorySyncService;
use App\Services\Sync\DownloadSyncService;
use App\Services\Sync\CacheCleanupService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ───────────────────────────────────────────────────────────────────────────
 * ManualSyncService — Pure Manual Sync Pipeline Orchestrator & Controls
 * ───────────────────────────────────────────────────────────────────────────
 *
 * Orchestrates modular entity sync services based strictly on items in the
 * `sync_queue` table with batch processing, live dashboard metrics, and control
 * flags (Pause / Resume / Cancel).
 * ───────────────────────────────────────────────────────────────────────────
 */
class ManualSyncService
{
    private const ENTITY_ORDER = [
        'patient'  => 1,
        'note'     => 2,
        'visit'    => 3,
        'file'     => 4,
        'video'    => 4,
        'image'    => 4,
        'document' => 4,
        'category' => 5,
    ];

    private const STATE_KEY = 'sync_engine_state'; // 'running', 'paused', 'cancelled', 'idle'

    public function __construct(
        private readonly RemoteApiService $api,
        private readonly SyncQueueService $queueService,
        private readonly PatientSyncService $patientSync,
        private readonly NoteSyncService $noteSync,
        private readonly VisitSyncService $visitSync,
        private readonly FileSyncService $fileSync,
        private readonly CategorySyncService $categorySync,
        private readonly DownloadSyncService $downloadSync,
        private readonly CacheCleanupService $cacheCleanup,
    ) {}

    public function pause(): void
    {
        Cache::put(self::STATE_KEY, 'paused', 3600);
        Log::info('[ManualSyncService] ⏸ Sync pipeline PAUSED by user');
    }

    public function resume(): void
    {
        Cache::put(self::STATE_KEY, 'running', 3600);
        Log::info('[ManualSyncService] ▶ Sync pipeline RESUMED by user');
    }

    public function cancel(): void
    {
        Cache::put(self::STATE_KEY, 'cancelled', 3600);
        Log::info('[ManualSyncService] ⏹ Sync pipeline CANCELLED by user');
    }

    public function getEngineState(): string
    {
        return Cache::get(self::STATE_KEY, 'idle');
    }

    /**
     * Execute the full manual sync orchestrator pipeline with batch processing and live controls.
     */
    public function syncAll(): array
    {
        Cache::put(self::STATE_KEY, 'running', 3600);

        $stats = [
            'processed_queue_items' => 0,
            'successful_items'      => 0,
            'failed_items'          => 0,
            'downloads'             => ['patients' => 0, 'files' => 0],
            'cache_cleaned'         => 0,
            'start_time'            => microtime(true),
        ];

        $token = $this->api->getToken();
        if (empty($token)) {
            // Attempt to refresh/acquire token using stored encrypted credentials
            if ($this->refreshToken()) {
                $token = $this->api->getToken();
            }
        }

        if (empty($token)) {
            Cache::put(self::STATE_KEY, 'idle', 3600);
            Log::warning('[ManualSyncService] ⏭ Sync aborted — No API token present');
            return [
                'success' => false,
                'message' => 'API token missing. Please authenticate to sync.',
                'stats'   => $stats,
            ];
        }

        Log::info('[ManualSyncService] 🚀 Orchestrating manual sync pipeline...');

        try {
            // ── Fetch & Sort queue by Entity Order ─────────────────────────
            $rawItems = $this->queueService->getPending(100);

            $queueItems = $rawItems->sort(function ($a, $b) {
                $orderA = self::ENTITY_ORDER[strtolower($a->entity_type)] ?? 99;
                $orderB = self::ENTITY_ORDER[strtolower($b->entity_type)] ?? 99;
                return $orderA === $orderB ? ($a->created_at <=> $b->created_at) : ($orderA <=> $orderB);
            });

            foreach ($queueItems as $item) {
                // Check Pause / Cancel Flags
                $state = $this->getEngineState();
                if ($state === 'paused') {
                    Log::info('[ManualSyncService] ⏸ Sync loop paused mid-queue execution');
                    return [
                        'success' => true,
                        'message' => 'Sync process paused safely.',
                        'state'   => 'paused',
                        'stats'   => $stats,
                    ];
                }
                if ($state === 'cancelled') {
                    Log::info('[ManualSyncService] ⏹ Sync loop cancelled mid-queue execution');
                    Cache::put(self::STATE_KEY, 'idle', 3600);
                    return [
                        'success' => false,
                        'message' => 'Sync process cancelled by user.',
                        'state'   => 'cancelled',
                        'stats'   => $stats,
                    ];
                }

                $stats['processed_queue_items']++;

                try {
                    $success = match (strtolower($item->entity_type)) {
                        'patient'  => $this->patientSync->processItem($item),
                        'note'     => $this->noteSync->processItem($item),
                        'visit'    => $this->visitSync->processItem($item),
                        'category' => $this->categorySync->processItem($item),
                        'file', 'video', 'image', 'document' => $this->fileSync->processItem($item),
                        default   => false,
                    };

                    if ($success) {
                        $stats['successful_items']++;
                    } else {
                        $stats['failed_items']++;
                    }
                } catch (AuthenticationException $e) {
                    Cache::put(self::STATE_KEY, 'idle', 3600);
                    Log::error('[ManualSyncService] 🛑 HTTP 401 Unauthorized encountered! Aborting sync pipeline immediately.');
                    return [
                        'success' => false,
                        'message' => 'Authentication session expired (401). Please log in again to resume sync.',
                        'stats'   => $stats,
                    ];
                }
            }

            // ── Step 2: Incremental Download ──────────────────────────────
            $stats['downloads'] = $this->downloadSync->downloadChanges();

            // ── Step 3: Binary Cache Cleanup ──────────────────────────────
            $stats['cache_cleaned'] = $this->cacheCleanup->cleanupBinaryCache(30);

            Cache::put(self::STATE_KEY, 'idle', 3600);
            Log::info('[ManualSyncService] ✅ Manual sync pipeline finished', $stats);

            return [
                'success' => true,
                'message' => 'Manual synchronization completed successfully.',
                'stats'   => $stats,
                'queue'   => $this->getSyncDashboardStats(),
            ];
        } catch (Throwable $e) {
            Cache::put(self::STATE_KEY, 'idle', 3600);
            Log::error('[ManualSyncService] ❌ Sync pipeline failed: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
                'stats'   => $stats,
            ];
        }
    }

    /**
     * Complete Dashboard Metrics & Analytics (iCloud / Google Drive style).
     */
    public function getSyncDashboardStats(): array
    {
        $sqlitePath = config('database.connections.sqlite.database');
        $sqliteSizeMb = file_exists($sqlitePath) ? round(filesize($sqlitePath) / 1048576, 2) : 0.0;

        $cacheDir = storage_path('app/patients');
        $cacheSizeMb = 0.0;
        if (is_dir($cacheDir)) {
            $bytes = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir)) as $f) {
                if ($f->isFile()) $bytes += $f->getSize();
            }
            $cacheSizeMb = round($bytes / 1048576, 2);
        }

        $lastSyncedAt = SyncQueue::where('status', 'synced')->max('updated_at');
        $longestPending = SyncQueue::where('status', 'pending')->min('created_at');
        $lastError = SyncQueue::where('status', 'failed')->whereNotNull('last_error')->latest('updated_at')->value('last_error');

        return [
            'engine_state'        => $this->getEngineState(),
            'total_queue'         => SyncQueue::count(),
            'pending'             => SyncQueue::where('status', 'pending')->count(),
            'processing'          => SyncQueue::where('status', 'processing')->count(),
            'failed'              => SyncQueue::where('status', 'failed')->count(),
            'conflict'            => SyncQueue::where('status', 'conflict')->count(),
            'synced'              => SyncQueue::where('status', 'synced')->count(),
            
            // Entity Breakdown
            'pending_patients'    => SyncQueue::where('entity_type', 'patient')->where('status', 'pending')->count(),
            'pending_notes'       => SyncQueue::where('entity_type', 'note')->where('status', 'pending')->count(),
            'pending_visits'      => SyncQueue::where('entity_type', 'visit')->where('status', 'pending')->count(),
            'pending_files'       => SyncQueue::whereIn('entity_type', ['file', 'image', 'video', 'document'])->where('status', 'pending')->count(),
            'pending_categories'  => SyncQueue::where('entity_type', 'category')->where('status', 'pending')->count(),
            'pending_deletes'     => SyncQueue::where('operation', 'delete')->where('status', 'pending')->count(),

            // Storage & Auth
            'sqlite_size_mb'      => $sqliteSizeMb,
            'local_cache_size_mb' => $cacheSizeMb,
            'last_successful_sync'=> $lastSyncedAt,
            'longest_pending_item'=> $longestPending,
            'last_error'          => $lastError,
            'auth_status'         => !empty($this->api->getToken()) ? 'Authenticated' : 'Unauthenticated',
        ];
    }

    /**
     * Try to refresh the API token using stored encrypted credentials.
     */
    private function refreshToken(): bool
    {
        try {
            $encrypted = session('auth_credentials');
            if (!$encrypted) {
                Log::warning('[ManualSyncService] No stored credentials available for token refresh');
                return false;
            }

            $creds = json_decode(decrypt($encrypted), true);
            if (!$creds || empty($creds['email']) || empty($creds['password'])) {
                Log::warning('[ManualSyncService] Invalid stored credentials for token refresh');
                return false;
            }

            Log::info('[ManualSyncService] Attempting token acquisition/refresh for: ' . $creds['email']);
            $response = \App\Services\Mobile\ApiService::loginToRemote($creds['email'], $creds['password']);

            if (isset($response['token'])) {
                $this->api->setToken($response['token']);
                Log::info('[ManualSyncService] Token refreshed/acquired successfully');
                return true;
            }

            Log::warning('[ManualSyncService] Token refresh returned no token');
            return false;
        } catch (\Throwable $e) {
            Log::error('[ManualSyncService] Token refresh failed: ' . $e->getMessage());
            return false;
        }
    }
}
