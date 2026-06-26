<?php

namespace App\Services;

use App\Models\FileCategory;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Models\SyncQueueItem;
use App\Models\SyncState;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OfflineSyncEngine
{
    private const MODELS = [
        'patients' => Patient::class,
        'patient_files' => PatientFile::class,
        'patient_visits' => PatientVisit::class,
        'file_categories' => FileCategory::class,
        'users' => User::class,
    ];

    public function __construct(private readonly MobileApiClient $api)
    {
    }

    public function initializeLocalDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $database = config('database.connections.sqlite.database');
        if ($database && $database !== ':memory:' && !file_exists($database)) {
            touch($database);
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    public function initialSeed(string $token): void
    {
        if ($this->state('initialized')) {
            return;
        }

        $payload = $this->api->seed($token);

        DB::transaction(function () use ($payload): void {
            $this->applyTables($payload['tables'] ?? []);
            $this->setState('last_sync_at', $payload['server_time'] ?? now()->toISOString());
            $this->setState('initialized', true);
        });
    }

    public function queue(string $table, string $operation, array $payload, ?string $recordUuid = null): SyncQueueItem
    {
        return SyncQueueItem::create([
            'table_name' => $table,
            'record_uuid' => $recordUuid ?? ($payload['uuid'] ?? null),
            'operation' => $operation,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    public function sync(string $token): array
    {
        $uploaded = $this->flushQueue($token);
        $downloaded = $this->pullChanges($token);

        return compact('uploaded', 'downloaded');
    }

    public function flushQueue(string $token, int $limit = 100): int
    {
        $items = SyncQueueItem::where('status', 'pending')
            ->where(fn($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $operations = $items->map(fn(SyncQueueItem $item) => [
            'uuid' => $item->record_uuid,
            'table' => $item->table_name,
            'operation' => $item->operation,
            'payload' => $item->payload ?? [],
        ])->all();

        try {
            $this->api->push($token, $operations);
            SyncQueueItem::whereIn('id', $items->pluck('id'))->update(['status' => 'synced', 'last_error' => null]);

            return $items->count();
        } catch (Throwable $throwable) {
            foreach ($items as $item) {
                $retry = $item->retry_count + 1;
                $item->update([
                    'retry_count' => $retry,
                    'last_error' => $throwable->getMessage(),
                    'available_at' => now()->addSeconds(min(3600, 2 ** min($retry, 10))),
                ]);
            }

            throw $throwable;
        }
    }

    public function pullChanges(string $token): int
    {
        $payload = $this->api->changes($token, $this->state('last_sync_at'));

        return DB::transaction(function () use ($payload): int {
            $count = $this->applyTables($payload['tables'] ?? []);
            $this->setState('last_sync_at', $payload['server_time'] ?? now()->toISOString());

            return $count;
        });
    }

    private function applyTables(array $tables): int
    {
        $count = 0;

        foreach ($tables as $table => $records) {
            if (!isset(self::MODELS[$table])) {
                continue;
            }

            /** @var class-string<Model> $modelClass */
            $modelClass = self::MODELS[$table];

            foreach ($records as $record) {
                $uuid = $record['uuid'] ?? null;
                if (!$uuid) {
                    continue;
                }

                $query = $modelClass::query();
                if (Schema::hasColumn($table, 'deleted_at')) {
                    $query->withTrashed();
                }

                $model = $query->where('uuid', $uuid)->first();

                if (!empty($record['deleted_at'])) {
                    if ($model && (!method_exists($model, 'trashed') || !$model->trashed())) {
                        $model->delete();
                    }
                    $count++;
                    continue;
                }

                unset($record['deleted_at']);
                $model ? $model->update($record) : $modelClass::create($record);
                $count++;
            }
        }

        return $count;
    }

    private function state(string $key): mixed
    {
        return SyncState::find($key)?->value['data'] ?? null;
    }

    private function setState(string $key, mixed $value): void
    {
        SyncState::updateOrCreate(['key' => $key], ['value' => ['data' => $value]]);
    }
}
