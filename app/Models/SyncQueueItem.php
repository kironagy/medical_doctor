<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncQueueItem extends Model
{
    protected $table = 'sync_queue';

    protected $fillable = [
        'uuid',
        'entity',
        'table_name',
        'record_uuid',
        'operation',
        'payload',
        'priority',
        'retry_count',
        'status',
        'last_error',
        'last_attempt_at',
        'available_at',
    ];

    protected $casts = [
        'payload'         => 'array',
        'available_at'    => 'datetime',
        'last_attempt_at' => 'datetime',
        'priority'        => 'integer',
        'retry_count'     => 'integer',
    ];

    /**
     * Entity name map (table → human-readable name) used for logging and priority.
     */
    public const ENTITY_MAP = [
        'patients'       => 'Patient',
        'patient_visits' => 'Visit',
        'patient_files'  => 'File',
        'file_categories'=> 'Category',
        'users'          => 'User',
    ];

    /**
     * Priority map — files are lowest priority (large payload), categories highest.
     */
    public const PRIORITY_MAP = [
        'file_categories' => 1,
        'patients'        => 2,
        'patient_visits'  => 3,
        'users'           => 4,
        'patient_files'   => 8, // Files last: largest payloads
    ];

    protected static function booted(): void
    {
        static::creating(function (SyncQueueItem $item): void {
            $item->uuid         ??= (string) Str::uuid();
            $item->available_at ??= now();
            $item->priority     ??= self::PRIORITY_MAP[$item->table_name] ?? 5;
            $item->entity       ??= self::ENTITY_MAP[$item->table_name] ?? $item->table_name;
        });
    }

    // ─── Query Scopes ─────────────────────────────────────────────────────────

    /**
     * Pending and retrying items that are ready to be processed.
     */
    public function scopePendingAndDue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['pending', 'retrying'])
            ->where(fn($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()));
    }

    /**
     * Order by priority ASC then by ID ASC (FIFO within same priority).
     * Enforces Create → Update → Delete ordering within entity priority.
     */
    public function scopeOrderedByPriority(Builder $query): Builder
    {
        return $query
            ->orderBy('priority')
            ->orderByRaw("CASE operation WHEN 'create' THEN 1 WHEN 'update' THEN 2 WHEN 'delete' THEN 3 ELSE 4 END")
            ->orderBy('id');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Mark this item as currently being processed.
     */
    public function markRunning(): void
    {
        $this->update([
            'status'          => 'running',
            'last_attempt_at' => now(),
        ]);
    }

    /**
     * Mark this item as successfully completed.
     */
    public function markCompleted(): void
    {
        $this->update([
            'status'     => 'completed',
            'last_error' => null,
        ]);
    }

    /**
     * Mark this item as skipped (valid operation, intentionally not applied).
     */
    public function markSkipped(string $reason): void
    {
        $this->update([
            'status'     => 'skipped',
            'last_error' => $reason,
        ]);
    }

    /**
     * Increment retry count and set exponential backoff for next attempt.
     * Permanently fails after 10 retries.
     */
    public function markFailed(string $error, int $maxRetries = 10): void
    {
        $retry     = $this->retry_count + 1;
        $newStatus = $retry >= $maxRetries ? 'failed' : 'retrying';
        $backoffSec = min(3600, 2 ** min($retry, 10)); // Max 1 hour backoff

        $this->update([
            'status'       => $newStatus,
            'retry_count'  => $retry,
            'last_error'   => $error,
            'available_at' => now()->addSeconds($backoffSec),
        ]);
    }
}
