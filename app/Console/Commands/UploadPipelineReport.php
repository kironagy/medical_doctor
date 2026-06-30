<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use App\Domains\Media\Models\PatientFile;

#[Signature('uploads:pipeline-report {--limit=100 : Number of recent files to include}')]
#[Description('Show average per-step timing for the upload pipeline.')]
class UploadPipelineReport extends Command
{
    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $files = PatientFile::whereNotNull('processing_times')
            ->latest()
            ->limit($limit)
            ->get(['id', 'uuid', 'type', 'size', 'upload_status', 'processing_times', 'created_at']);

        if ($files->isEmpty()) {
            $this->warn('No files with timing data found. processing_times is populated after upload.');
            return self::SUCCESS;
        }

        // Aggregate per step
        $steps = ['merge_ms', 'mark_ready_ms', 'thumbnail_ms'];
        $sums  = array_fill_keys($steps, 0);
        $counts = array_fill_keys($steps, 0);

        $totalFiles = $files->count();
        $videoFiles = 0;
        $thumbFiles = 0;

        foreach ($files as $file) {
            $t = $file->processing_times ?? [];
            if ($file->type === 'video') $videoFiles++;
            foreach ($steps as $step) {
                if (isset($t[$step])) {
                    $sums[$step] += $t[$step];
                    $counts[$step]++;
                    if ($step === 'thumbnail_ms') $thumbFiles++;
                }
            }
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>═══════════════════════════════════════════════════</>');
        $this->line('<fg=cyan;options=bold>   Upload Pipeline Performance Report              </>');
        $this->line('<fg=cyan;options=bold>═══════════════════════════════════════════════════</>');
        $this->newLine();
        $this->line("  Files analysed : <fg=white>{$totalFiles}</> (last {$limit})");
        $this->line("  Video files    : <fg=white>{$videoFiles}</>");
        $this->newLine();

        $this->table(
            ['Step', 'Avg (ms)', 'Avg (s)', 'Sample size', 'Notes'],
            [
                [
                    'Chunk upload',
                    'network-bound',
                    '—',
                    $totalFiles,
                    'Depends on file size & connection speed',
                ],
                [
                    'Store chunk (per chunk)',
                    '< 5 ms',
                    '—',
                    '—',
                    'Atomic rename, no DB write',
                ],
                [
                    'Chunk merge + SHA-256',
                    $counts['merge_ms'] > 0 ? round($sums['merge_ms'] / $counts['merge_ms'], 1) : '—',
                    $counts['merge_ms'] > 0 ? round($sums['merge_ms'] / $counts['merge_ms'] / 1000, 2) : '—',
                    $counts['merge_ms'],
                    'Runs on "uploads" queue (async, non-blocking)',
                ],
                [
                    'Mark file ready',
                    $counts['mark_ready_ms'] > 0 ? round($sums['mark_ready_ms'] / $counts['mark_ready_ms'], 2) : '—',
                    $counts['mark_ready_ms'] > 0 ? round($sums['mark_ready_ms'] / $counts['mark_ready_ms'] / 1000, 3) : '—',
                    $counts['mark_ready_ms'],
                    'File immediately viewable after this step',
                ],
                [
                    'Thumbnail (background)',
                    $counts['thumbnail_ms'] > 0 ? round($sums['thumbnail_ms'] / $counts['thumbnail_ms'], 1) : '—',
                    $counts['thumbnail_ms'] > 0 ? round($sums['thumbnail_ms'] / $counts['thumbnail_ms'] / 1000, 2) : '—',
                    $counts['thumbnail_ms'],
                    'Runs on "thumbnails" queue — never blocks viewing',
                ],
                [
                    'HLS encoding',
                    'REMOVED',
                    '—',
                    '—',
                    'Was: minutes–hours. Not needed for document storage.',
                ],
                [
                    'MP4 faststart / transcode',
                    'REMOVED',
                    '—',
                    '—',
                    'Was: 30s–30min. Range requests work without it.',
                ],
            ]
        );

        $this->newLine();
        $this->line('<fg=green>  ✔  File available to doctor: immediately after merge completes.</>');
        $this->line('<fg=yellow>  ⏳  Thumbnail: background (doctor never waits for this).</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
