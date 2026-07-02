<?php

namespace App\Console\Commands;

use App\Domains\Mobile\Services\MobileSyncService;
use Illuminate\Console\Command;

class MobileSync extends Command
{
    protected $signature = 'mobile:sync
        {--once : Run a single sync cycle and exit}
        {--daemon : Run continuously, syncing every 30 seconds}
        {--force : Force sync even if offline (will fail gracefully)}';

    protected $description = 'Synchronize local SQLite data with the production server';

    public function handle(MobileSyncService $sync): int
    {
        $once = $this->option('once');
        $daemon = $this->option('daemon');

        if ($daemon) {
            return $this->runDaemon($sync);
        }

        return $this->runOnce($sync);
    }

    protected function runOnce(MobileSyncService $sync): int
    {
        $this->info('Checking connection...');

        if (!$sync->isOnline()) {
            $this->warn('No internet connection. Skipping sync.');
            if (!$this->option('force')) {
                return Command::SUCCESS;
            }
        }

        $token = MobileSyncService::getStoredToken();
        if (!$token) {
            $this->error('Not authenticated. Please login first.');
            return Command::FAILURE;
        }

        $sync->setToken($token);

        $this->info('Starting sync...');
        $result = $sync->syncNow();

        $this->line("Pulled: {$result['pulled']} records");
        $this->line("Pushed: {$result['pushed']} records");

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->error($error);
            }
            return Command::FAILURE;
        }

        $this->info('Sync completed successfully.');
        return Command::SUCCESS;
    }

    protected function runDaemon(MobileSyncService $sync): int
    {
        $this->info('Mobile sync daemon started. Syncing every 30 seconds.');
        $this->info('Press Ctrl+C to stop.');

        while (true) {
            if ($sync->isOnline()) {
                $token = MobileSyncService::getStoredToken();
                if ($token) {
                    $sync->setToken($token);
                    $result = $sync->syncNow();
                    $this->line('[' . now()->toTimeString() . "] Pulled: {$result['pulled']}, Pushed: {$result['pushed']}");
                }
            }

            sleep(30);
        }
    }
}
