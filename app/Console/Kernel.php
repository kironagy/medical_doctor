<?php

namespace App\Console;

use App\Services\SyncQueueService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\OptimizeVideosCommand::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Daily cleanup: remove synced items older than 7 days
        // Prevents sync_queue from growing unbounded
        $schedule->call(function () {
            try {
                $service = app(SyncQueueService::class);
                $cleared = $service->clearSyncedOperations(7);
                \Illuminate\Support\Facades\Log::info('[Kernel] Scheduled cleanup: cleared ' . $cleared . ' synced operations older than 7 days.');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[Kernel] Failed to clear synced operations: ' . $e->getMessage());
            }
        })->dailyAt('02:00')->description('Clear old synced queue items');

        // Weekly cleanup: remove permanently failed items older than 30 days
        $schedule->call(function () {
            try {
                $service = app(SyncQueueService::class);
                $cleared = $service->clearPermanentlyFailed(30);
                \Illuminate\Support\Facades\Log::info('[Kernel] Scheduled cleanup: cleared ' . $cleared . ' permanently failed operations older than 30 days.');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[Kernel] Failed to clear permanently failed operations: ' . $e->getMessage());
            }
        })->weeklyOn(1, '03:00')->description('Clear old permanently failed queue items');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        // Also require the console routes if they exist
        if (file_exists(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }
}
