<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Domains\Media\Jobs\OptimizeVideoForStreaming;
use App\Domains\Media\Models\PatientFile;
Artisan::command('videos:optimize', function () {
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
})->purpose('Optimize all uploaded video files for streaming (move moov atom to beginning)')->option('dry-run', null, 'Show what would be optimized without doing it');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
