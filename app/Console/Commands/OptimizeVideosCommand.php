<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domains\Media\Jobs\OptimizeVideoForStreaming;
use App\Domains\Media\Models\PatientFile;

class OptimizeVideosCommand extends Command
{
    protected $signature = 'videos:optimize {--dry-run : Show what would be optimized without doing it}';
    protected $description = 'Optimize all uploaded video files for streaming (move moov atom to beginning)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $files = PatientFile::whereNotNull('mime_type')
            ->where('mime_type', 'like', 'video/%')
            ->lazy();

        $count = 0;
        foreach ($files as $file) {
            $count++;
            if ($dryRun) {
                $this->info("Would optimize: {$file->file_name} ({$file->uuid})");
                continue;
            }
            OptimizeVideoForStreaming::dispatch($file->id);
            $this->info("Queued optimization for: {$file->file_name}");
        }

        $this->info("Total video files: $count");
        if ($dryRun) {
            $this->info('Dry run completed. Run without --dry-run to actually optimize.');
        } else {
            $this->info('Optimization jobs dispatched. Check queue workers.');
        }
    }
}
