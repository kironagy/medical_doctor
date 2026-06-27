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
        'patients'        => Patient::class,
        'patient_files'   => PatientFile::class,
        'patient_visits'  => PatientVisit::class,
        'file_categories' => FileCategory::class,
        'users'           => User::class,
    ];

    /**
     * Pull order ensures foreign key constraints are satisfied.
     * Categories must exist before files; patients before visits/files.
     */
    private const TABLE_ORDER = [
        'file_categories' => 1,
        'users'           => 2,
        'patients'        => 3,
        'patient_visits'  => 4,
        'patient_files'   => 5,
    ];

    public function __construct(private readonly SyncHandlerRegistry $registry)
    {
    }

    // ─── Initial Seed ─────────────────────────────────────────────────────────

    /**
     * Full initial seed using offset pagination.
     * Used only once when the mobile device initializes its local database.
     */
    public function initialSeed(int $page = 1, int $limit = 100): array
    {
        $hasMore = false;
        $tables  = [];

        foreach (self::MODELS as $table => $modelClass) {
            $paginator = $this->queryFor($table)
                ->orderBy('id')
                ->paginate($limit, ['*'], 'page', $page);

            if ($paginator->hasMorePages()) {
                $hasMore = true;
            }

            $tables[$table] = [
                'records'  => $this->serializeRecords($table, $paginator->getCollection()),
                'has_more' => $paginator->hasMorePages(),
            ];
        }

        return [
            'server_time' => now()->toISOString(),
            'has_more'    => $hasMore,
            'tables'      => $tables,
        ];
    }

    // ─── Delta Changes (Cursor Pagination) ────────────────────────────────────

    /**
     * Fetch only records changed since a given timestamp.
     *
     * Uses cursor-based pagination (no COUNT(*) overhead) to efficiently
     * stream large delta sets. The cursor is an opaque string returned by
     * Laravel's cursorPaginate() and must be passed back on subsequent calls.
     *
     * @param string|null $since  ISO-8601 timestamp of the last successful sync.
     * @param string|null $cursor Opaque cursor token from a previous response.
     * @param int         $limit  Records per page (max 500).
     */
    public function changes(?string $since, ?string $cursor = null, int $limit = 100): array
    {
        $sinceDate = $since ? Carbon::parse($since) : Carbon::createFromTimestamp(0);
        $tables    = [];
        $nextCursor = null;
        $hasMore   = false;

        // Sort tables by dependency order
        $orderedModels = collect(self::MODELS)
            ->sortBy(fn($class, $table) => self::TABLE_ORDER[$table] ?? 99)
            ->all();

        foreach ($orderedModels as $table => $modelClass) {
            $paginator = $this->queryFor($table, withDeleted: true)
                ->where(function ($query) use ($sinceDate, $table): void {
                    $query->where('updated_at', '>', $sinceDate)
                          ->orWhere('client_updated_at', '>', $sinceDate);

                    if (Schema::hasColumn($table, 'deleted_at')) {
                        $query->orWhere('deleted_at', '>', $sinceDate);
                    }
                })
                ->orderBy('id')
                ->cursorPaginate($limit, ['*'], 'cursor', $cursor);

            if ($paginator->hasMorePages()) {
                $hasMore    = true;
                $nextCursor = $paginator->nextCursor()?->encode();
            }

            $tables[$table] = [
                'records'     => $this->serializeRecords($table, $paginator->getCollection()),
                'has_more'    => $paginator->hasMorePages(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
            ];
        }

        return [
            'server_time' => now()->toISOString(),
            'has_more'    => $hasMore,
            'next_cursor' => $nextCursor,
            'tables'      => $tables,
        ];
    }

    // ─── Push Operations ──────────────────────────────────────────────────────

    /**
     * Apply a list of operations pushed from the mobile client.
     * Each operation is isolated — failures do not abort the batch.
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
     * Apply a single sync operation through the registered entity handler.
     */
    private function applyOperation(array $operation): array
    {
        $table   = $operation['table'] ?? '';
        $uuid    = $operation['uuid'] ?? ($operation['record_uuid'] ?? null);
        $action  = strtolower((string) ($operation['operation'] ?? ''));
        $payload = $operation['payload'] ?? [];

        $startedAt = microtime(true);

        try {
            if (!$this->registry->hasHandler($table)) {
                throw new InvalidArgumentException("Unsupported sync table [{$table}].");
            }

            $handler = $this->registry->getHandler($table);
            $result  = $handler->apply($action, $payload, $uuid);
            $result['table'] = $table;

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            Log::info('sync.operation_processed', [
                'table'       => $table,
                'uuid'        => $uuid,
                'operation'   => $action,
                'status'      => $result['status'] ?? 'unknown',
                'duration_ms' => $durationMs,
                'payload'     => $this->sanitizePayload($table, $payload),
                'error'       => $result['error'] ?? null,
            ]);

            return $result;

        } catch (\Throwable $throwable) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            Log::error('sync.operation_error', [
                'table'       => $table,
                'uuid'        => $uuid,
                'operation'   => $action,
                'duration_ms' => $durationMs,
                'message'     => $throwable->getMessage(),
                'trace'       => $throwable->getTraceAsString(),
            ]);

            return [
                'uuid'   => $uuid,
                'table'  => $table,
                'status' => 'failed',
                'error'  => $throwable->getMessage(),
            ];
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function queryFor(string $table, bool $withDeleted = false)
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = self::MODELS[$table];
        $query      = $modelClass::query();

        return $withDeleted && Schema::hasColumn($table, 'deleted_at')
            ? $query->withTrashed()
            : $query;
    }

    /**
     * Serialize records for outbound seed/changes, translating IDs to UUIDs.
     */
    private function serializeRecords(string $table, $records): array
    {
        return $records->map(function ($record) use ($table): array {
            $data = $record->makeVisible($table === 'users' ? ['password'] : [])->toArray();

            unset($data['remember_token']);

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
     * Redact sensitive fields from sync operation logs.
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
