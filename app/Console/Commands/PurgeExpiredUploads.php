<?php

namespace App\Console\Commands;

use App\Services\Upload\UploadCleanupService;
use Illuminate\Console\Command;

class PurgeExpiredUploads extends Command
{
    protected $signature = 'uploads:purge-expired {--hours=6 : Session expiration age in hours}';
    protected $description = 'Purge expired upload sessions and their orphan chunks';

    public function handle(UploadCleanupService $cleanupService): int
    {
        $count = $cleanupService->purgeExpired((int) $this->option('hours'));
        $this->info("Purged {$count} expired upload sessions/temp files.");
        return Command::SUCCESS;
    }
}
