<?php

namespace App\Jobs;

use App\Services\FullSyncService;
use App\Services\NetworkStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FullSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(FullSyncService $syncService): void
    {
        if (!NetworkStatusService::isOnline()) {
            return;
        }

        $syncService->syncAll();
    }
}
