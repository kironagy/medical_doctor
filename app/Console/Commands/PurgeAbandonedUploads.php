<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Domains\Media\Services\UploadService;

#[Signature('uploads:purge-abandoned {--hours=6 : Purge sessions untouched for at least N hours}')]
#[Description('Delete abandoned upload session directories (stale chunks from cancelled/interrupted uploads).')]
class PurgeAbandonedUploads extends Command
{
    public function handle(UploadService $uploadService): int
    {
        $hours = (int) $this->option('hours');
        if ($hours < 1) $hours = 1;

        $purged = $uploadService->purgeAbandonedSessions($hours * 3600);

        $this->info("Purged {$purged} abandoned upload sessions (older than {$hours}h).");
        return self::SUCCESS;
    }
}