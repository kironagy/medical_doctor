<?php

namespace App\Console\Commands;

use App\Domains\Mobile\Services\MobileSyncService;
use App\Domains\Mobile\Services\ProductionApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MobileInitialSync extends Command
{
    protected $signature = 'mobile:init-sync
        {--email= : Production account email}
        {--password= : Production account password}';

    protected $description = 'First-time sync: authenticate and pull all data from production';

    public function handle(): int
    {
        $email = $this->option('email');
        $password = $this->option('password');

        if (!$email) {
            $email = $this->ask('Enter your production account email');
        }

        if (!$password) {
            $password = $this->secret('Enter your production account password');
        }

        $this->info('Connecting to production server...');

        $api = new ProductionApiService;
        $result = $api->login($email, $password, 'nativephp-android');

        if (!$result || !isset($result['token'])) {
            $this->error('Authentication failed. Check your credentials.');
            return Command::FAILURE;
        }

        $token = $result['token'];
        Cache::put('mobile_auth_user', $result['user'], now()->addDays(30));
        Cache::put('mobile_auth_token', encrypt($token), now()->addDays(30));

        $this->info('Authenticated successfully as: ' . ($result['user']['name'] ?? $email));

        $sync = new MobileSyncService;
        $sync->setToken($token);

        $this->info('Pulling all data from production server...');

        $pullResult = $api->setToken($token)->pull(null);
        if (!$pullResult || !isset($pullResult['data'])) {
            $this->error('Failed to pull data from server.');
            return Command::FAILURE;
        }

        $data = $pullResult['data'];
        $totalCount = 0;
        foreach ($data as $entity => $items) {
            $count = count($items ?? []);
            $totalCount += $count;
            $this->line("  {$entity}: {$count} records");
        }

        $applied = $sync->syncNow();

        if (isset($pullResult['server_time'])) {
            Cache::put('mobile_last_sync_at', $pullResult['server_time'], now()->addDays(7));
        }

        $this->info("Initial sync complete. {$totalCount} records pulled from server.");
        $this->info('The app is now ready for offline use.');

        return Command::SUCCESS;
    }
}
