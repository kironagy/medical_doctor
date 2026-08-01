<?php

namespace App\Services\Sync;

use App\Services\Mobile\RemoteApiService;
use App\Domains\Sync\Models\SyncQueue;
use App\Domains\Sync\Services\SyncQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CategorySyncService
{
    public function __construct(
        private readonly RemoteApiService $api,
        private readonly SyncQueueService $queueService
    ) {}

    public function processItem(SyncQueue $item): bool
    {
        $this->queueService->markProcessing($item->id);

        try {
            $response = $this->api->get('/categories');
            $categories = $response['data'] ?? $response ?? [];

            foreach ($categories as $cat) {
                if (empty($cat['name'])) continue;
                DB::table('file_categories')->updateOrInsert(
                    ['name' => $cat['name']],
                    [
                        'slug' => $cat['slug'] ?? \Illuminate\Support\Str::slug($cat['name']),
                        'updated_at' => now(),
                    ]
                );
            }

            $this->queueService->markSynced($item->id);
            return true;
        } catch (Throwable $e) {
            Log::warning("[CategorySyncService] Category sync failed: " . $e->getMessage());
            $this->queueService->markFailed($item->id, $e->getMessage());
            return false;
        }
    }
}
