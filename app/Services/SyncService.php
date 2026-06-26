<?php

namespace App\Services;

use App\Models\FileCategory;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class SyncService
{
    private const MODELS = [
        'patients' => Patient::class,
        'patient_files' => PatientFile::class,
        'patient_visits' => PatientVisit::class,
        'file_categories' => FileCategory::class,
        'users' => User::class,
    ];

    public function initialSeed(): array
    {
        return [
            'server_time' => now()->toISOString(),
            'tables' => collect(array_keys(self::MODELS))->mapWithKeys(fn ($table) => [
                $table => $this->serializeRecords($table, $this->queryFor($table)->orderBy('id')->get()),
            ]),
        ];
    }

    public function changes(?string $since): array
    {
        $sinceDate = $since ? Carbon::parse($since) : Carbon::createFromTimestamp(0);

        return [
            'server_time' => now()->toISOString(),
            'tables' => collect(array_keys(self::MODELS))->mapWithKeys(fn ($table) => [
                $table => $this->serializeRecords($table, $this->queryFor($table, true)
                    ->where(function ($query) use ($sinceDate, $table): void {
                        $query->where('updated_at', '>', $sinceDate)
                            ->orWhere('client_updated_at', '>', $sinceDate);

                        if (Schema::hasColumn($table, 'deleted_at')) {
                            $query->orWhere('deleted_at', '>', $sinceDate);
                        }
                    })
                    ->orderBy('updated_at')
                    ->get()),
            ]),
        ];
    }

    public function applyOperations(array $operations): array
    {
        $results = [];

        DB::transaction(function () use ($operations, &$results): void {
            foreach ($operations as $operation) {
                $results[] = $this->applyOperation($operation);
            }
        });

        return $results;
    }

    private function applyOperation(array $operation): array
    {
        $table = $operation['table'] ?? '';
        $uuid = $operation['uuid'] ?? ($operation['record_uuid'] ?? null);
        $action = strtolower((string) ($operation['operation'] ?? ''));
        $payload = $operation['payload'] ?? [];

        if (! isset(self::MODELS[$table])) {
            throw new InvalidArgumentException("Unsupported sync table [{$table}].");
        }

        if (! $uuid && isset($payload['uuid'])) {
            $uuid = $payload['uuid'];
        }

        if (! $uuid) {
            throw new InvalidArgumentException('Sync operation requires a uuid.');
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = self::MODELS[$table];
        $model = $this->queryFor($table, true)->where('uuid', $uuid)->first();

        if ($action === 'delete') {
            if ($model && (! method_exists($model, 'trashed') || ! $model->trashed())) {
                $model->delete();
            }

            return ['uuid' => $uuid, 'table' => $table, 'status' => 'deleted'];
        }

        unset($payload['id'], $payload['deleted_at'], $payload['created_at'], $payload['updated_at']);
        $payload['uuid'] = $uuid;
        $payload['client_updated_at'] = $payload['client_updated_at'] ?? now();

        if ($model && method_exists($model, 'trashed') && $model->trashed()) {
            $model->restore();
        }

        if ($model) {
            $serverTime = $model->client_updated_at ?? $model->updated_at;
            $clientTime = Carbon::parse($payload['client_updated_at']);

            if ($serverTime && $serverTime->greaterThan($clientTime)) {
                return ['uuid' => $uuid, 'table' => $table, 'status' => 'conflict_server_won'];
            }

            $model->update($payload);
        } else {
            $model = $modelClass::create($payload);
        }

        return ['uuid' => $uuid, 'table' => $table, 'status' => 'applied', 'id' => $model->id];
    }

    private function queryFor(string $table, bool $withDeleted = false)
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = self::MODELS[$table];

        $query = $modelClass::query();

        return $withDeleted && Schema::hasColumn($table, 'deleted_at')
            ? $query->withTrashed()
            : $query;
    }

    private function serializeRecords(string $table, $records): array
    {
        return $records->map(function ($record) use ($table): array {
            $data = $record->makeVisible($table === 'users' ? ['password'] : [])->toArray();

            unset($data['remember_token']);

            return $data;
        })->all();
    }
}
