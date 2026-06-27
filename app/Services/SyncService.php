<?php

namespace App\Services;

use App\Models\FileCategory;
use App\Models\Patient;
use App\Models\PatientFile;
use App\Models\PatientVisit;
use App\Models\User;
use App\Sync\SyncHandlerRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private readonly SyncHandlerRegistry $registry)
    {
    }

    public function initialSeed(int $page = 1, int $limit = 100): array
    {
        $hasMore = false;
        $tables = [];

        foreach (self::MODELS as $table => $modelClass) {
            $paginator = $this->queryFor($table)->orderBy('id')->paginate($limit, ['*'], 'page', $page);
            if ($paginator->hasMorePages()) {
                $hasMore = true;
            }
            $tables[$table] = [
                'records' => $this->serializeRecords($table, $paginator->getCollection()),
                'has_more' => $paginator->hasMorePages(),
            ];
        }

        return [
            'server_time' => now()->toISOString(),
            'has_more' => $hasMore,
            'tables' => $tables,
        ];
    }

    public function changes(?string $since, int $page = 1, int $limit = 100): array
    {
        $sinceDate = $since ? Carbon::parse($since) : Carbon::createFromTimestamp(0);
        $hasMore = false;
        $tables = [];

        foreach (self::MODELS as $table => $modelClass) {
            $paginator = $this->queryFor($table, true)
                ->where(function ($query) use ($sinceDate, $table): void {
                    $query->where('updated_at', '>', $sinceDate)
                        ->orWhere('client_updated_at', '>', $sinceDate);

                    if (Schema::hasColumn($table, 'deleted_at')) {
                        $query->orWhere('deleted_at', '>', $sinceDate);
                    }
                })
                ->orderBy('updated_at')
                ->paginate($limit, ['*'], 'page', $page);

            if ($paginator->hasMorePages()) {
                $hasMore = true;
            }
            $tables[$table] = [
                'records' => $this->serializeRecords($table, $paginator->getCollection()),
                'has_more' => $paginator->hasMorePages(),
            ];
        }

        return [
            'server_time' => now()->toISOString(),
            'has_more' => $hasMore,
            'tables' => $tables,
        ];
    }

    /**
     * Apply a list of operations pushed from the client.
     * Process entities independently and commit individually (isolated transactions).
     */
    public function applyOperations(array $operations): array
    {
        $results = [];

        foreach ($operations as $operation) {
            $results[] = $this->applyOperation($operation);
        }

        return $results;
    }

    /**
     * Apply a single synchronization operation using entity-specific handlers.
     */
    private function applyOperation(array $operation): array
    {
        $table = $operation['table'] ?? '';
        $uuid = $operation['uuid'] ?? ($operation['record_uuid'] ?? null);
        $action = strtolower((string) ($operation['operation'] ?? ''));
        $payload = $operation['payload'] ?? [];

        $startedAt = microtime(true);

        try {
            if (!$this->registry->hasHandler($table)) {
                throw new InvalidArgumentException("Unsupported sync table [{$table}].");
            }

            $handler = $this->registry->getHandler($table);

            // Execute application flow through the specific entity handler
            $result = $handler->apply($action, $payload, $uuid);
            $result['table'] = $table;

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            // Detailed operational logging
            Log::info('sync.operation_processed', [
                'table' => $table,
                'uuid' => $uuid,
                'operation' => $action,
                'status' => $result['status'] ?? 'unknown',
                'duration_ms' => $durationMs,
                'payload' => $this->sanitizePayload($table, $payload),
                'error' => $result['error'] ?? null,
            ]);

            return $result;
        } catch (\Throwable $throwable) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            Log::error('sync.operation_error', [
                'table' => $table,
                'uuid' => $uuid,
                'operation' => $action,
                'duration_ms' => $durationMs,
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            return [
                'uuid' => $uuid,
                'table' => $table,
                'status' => 'failed',
                'error' => $throwable->getMessage(),
            ];
        }
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

    /**
     * Serialize records for outbound seed and changes syncing.
     * Translates foreign keys to global UUID references.
     */
    private function serializeRecords(string $table, $records): array
    {
        return $records->map(function ($record) use ($table): array {
            $data = $record->makeVisible($table === 'users' ? ['password'] : [])->toArray();

            unset($data['remember_token']);

            // Map patient_id to patient_uuid for visit and file entities
            if ($table === 'patient_visits' || $table === 'patient_files') {
                if (!empty($data['patient_id']) && empty($data['patient_uuid'])) {
                    $patient = Patient::find($data['patient_id']);
                    if ($patient) {
                        $data['patient_uuid'] = $patient->uuid;
                    }
                }
            }

            return $data;
        })->all();
    }

    /**
     * Helper to redact sensitive information in sync logs.
     */
    private function sanitizePayload(string $table, array $payload): array
    {
        if ($table === 'users') {
            foreach (['password', 'remember_token'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $payload[$key] = '[redacted]';
                }
            }
        }
        if (isset($payload['data'])) {
            $payload['data'] = '[base64_data_omitted_for_log]';
        }
        return $payload;
    }
}
