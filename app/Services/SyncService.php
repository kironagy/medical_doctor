<?php

namespace App\Services;

use App\Models\FileCategory;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncService
{
    private const MODELS = [
        'patients' => Patient::class,
        'patient_files' => PatientFile::class,
        'patient_visits' => PatientVisit::class,
        'file_categories' => FileCategory::class,
    ];

    public function initialSeed(): array
    {
        return [
            'server_time' => now()->toISOString(),
            'tables' => collect(array_keys(self::MODELS))->mapWithKeys(fn ($table) => [
                $table => $this->queryFor($table)->orderBy('id')->get(),
            ]),
        ];
    }

    public function changes(?string $since): array
    {
        $sinceDate = $since ? Carbon::parse($since) : Carbon::createFromTimestamp(0);

        return [
            'server_time' => now()->toISOString(),
            'tables' => collect(array_keys(self::MODELS))->mapWithKeys(fn ($table) => [
                $table => $this->queryFor($table)
                    ->withTrashed()
                    ->where(function ($query) use ($sinceDate): void {
                        $query->where('updated_at', '>', $sinceDate)
                            ->orWhere('client_updated_at', '>', $sinceDate)
                            ->orWhere('deleted_at', '>', $sinceDate);
                    })
                    ->orderBy('updated_at')
                    ->get(),
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
        $model = $modelClass::withTrashed()->where('uuid', $uuid)->first();

        if ($action === 'delete') {
            if ($model && ! $model->trashed()) {
                $model->delete();
            }

            return ['uuid' => $uuid, 'table' => $table, 'status' => 'deleted'];
        }

        unset($payload['id'], $payload['deleted_at'], $payload['created_at'], $payload['updated_at']);
        $payload['uuid'] = $uuid;
        $payload['client_updated_at'] = $payload['client_updated_at'] ?? now();

        if ($model && $model->trashed()) {
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

    private function queryFor(string $table)
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = self::MODELS[$table];

        return $modelClass::query();
    }
}
