<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncJob extends Model
{
    protected $table = 'sync_jobs';

    protected $fillable = [
        'uuid',
        'status',
        'direction',
        'total_items',
        'processed_items',
        'failed_items',
        'skipped_items',
        'error',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SyncJob $job): void {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    // ─── Status helpers ───────────────────────────────────────────────────────

    public function markProcessing(): void
    {
        $this->update([
            'status'     => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(int $uploaded, int $downloaded, int $failed, int $skipped): void
    {
        $this->update([
            'status'          => 'completed',
            'processed_items' => $uploaded + $downloaded,
            'failed_items'    => $failed,
            'skipped_items'   => $skipped,
            'completed_at'    => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status'       => 'failed',
            'error'        => $error,
            'completed_at' => now(),
        ]);
    }

    public function incrementProcessed(int $by = 1): void
    {
        $this->increment('processed_items', $by);
    }

    public function incrementFailed(int $by = 1): void
    {
        $this->increment('failed_items', $by);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    public function progressPercentage(): int
    {
        if ($this->total_items === 0) {
            return 100;
        }
        return (int) (($this->processed_items / $this->total_items) * 100);
    }
}
