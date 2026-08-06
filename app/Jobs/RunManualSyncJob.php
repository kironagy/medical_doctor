<?php

namespace App\Jobs;

use App\Services\SyncEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MedicalPlus\BackgroundSync\Facades\BackgroundSync;
use Throwable;

/**
 * Runs the full sync pipeline on the queue instead of inside the HTTP request.
 *
 * WHY THIS EXISTS
 * ---------------
 * The embedded Android runtime serialises every PHP request through one global
 * mutex (g_php_request_mutex in the native bridge), so a sync running inside
 * POST /_native/api/sync/manual holds that lock for its entire duration and
 * every other request — opening a patient, going back, loading a list — queues
 * behind it. That is what made the app feel frozen while syncing.
 *
 * NativePHP already ships PHPQueueWorker, which loops `queue:work --once` on a
 * DEDICATED PHP runtime ("so queue processing never contends with the main
 * phpExecutor used for UI requests"). Pushing the sync onto the queue therefore
 * moves it off the UI lock entirely — the app stays responsive while it runs.
 *
 * Requires a real queue driver: with QUEUE_CONNECTION=sync a dispatched job
 * executes inline in the caller's request, which would reintroduce the block.
 * AppServiceProvider forces the database driver on the device for that reason.
 */
class RunManualSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A large offline backlog can legitimately take a long time. */
    public int $timeout = 3600;

    /** Never auto-retry: the pipeline is resumable and re-running blind can duplicate work. */
    public int $tries = 1;

    public const STATE_KEY  = 'manual_sync_status';
    public const RESULT_KEY = 'manual_sync_result';

    public function handle(SyncEngineService $engine): void
    {
        self::writeState('running');

        // Runs on PHPQueueWorker's dedicated runtime, off the UI request
        // mutex — but the process itself still has no foreground presence
        // once MainActivity is gone. This is what lets a sync started right
        // before closing the app keep running instead of being killed with it.
        BackgroundSync::start('جاري المزامنة', 'برجاء الانتظار...');

        try {
            $results = $engine->syncAll();
            self::writeState('idle', [
                'success'      => true,
                'stats'        => $results,
                'message'      => 'Sync completed successfully',
                'finished_at'  => now()->toISOString(),
            ]);
            Log::info('[RunManualSyncJob] completed', $results);
            BackgroundSync::stop('اكتملت المزامنة');
        } catch (Throwable $e) {
            // Recorded rather than rethrown: the state row is what the UI polls,
            // and a failed job row would leave the UI spinning forever.
            Log::error('[RunManualSyncJob] failed: ' . $e->getMessage());
            self::writeState('idle', [
                'success'     => false,
                'message'     => $e->getMessage(),
                'finished_at' => now()->toISOString(),
            ]);
            BackgroundSync::stop('فشلت المزامنة');
        }
    }

    /**
     * Persist progress in sync_states so any runtime (UI request or worker) can
     * read it — the worker has its own PHP context, so session/cache state set
     * there is not visible to the request that started the sync.
     */
    public static function writeState(string $status, ?array $result = null): void
    {
        try {
            if (!Schema::hasTable('sync_states')) {
                return;
            }

            DB::table('sync_states')->updateOrInsert(
                ['key' => self::STATE_KEY],
                ['value' => $status, 'updated_at' => now()]
            );

            if ($result !== null) {
                DB::table('sync_states')->updateOrInsert(
                    ['key' => self::RESULT_KEY],
                    ['value' => json_encode($result), 'updated_at' => now()]
                );
            }
        } catch (Throwable $e) {
            Log::warning('[RunManualSyncJob] could not persist state: ' . $e->getMessage());
        }
    }

    /**
     * @return array{status:string,result:array|null,updated_at:string|null}
     */
    public static function readState(): array
    {
        $status = 'idle';
        $result = null;
        $updatedAt = null;

        try {
            if (Schema::hasTable('sync_states')) {
                $row = DB::table('sync_states')->where('key', self::STATE_KEY)->first();
                if ($row) {
                    $status = $row->value ?: 'idle';
                    $updatedAt = $row->updated_at ?? null;
                }

                $resultRow = DB::table('sync_states')->where('key', self::RESULT_KEY)->first();
                if ($resultRow && $resultRow->value) {
                    $result = json_decode($resultRow->value, true) ?: null;
                }
            }
        } catch (Throwable $e) {
            Log::warning('[RunManualSyncJob] could not read state: ' . $e->getMessage());
        }

        // A 'running' row older than the job timeout means the worker died
        // mid-flight; report idle so the UI is never stuck spinning forever.
        if ($status === 'running' && $updatedAt) {
            try {
                if (now()->diffInSeconds(\Illuminate\Support\Carbon::parse($updatedAt)) > 3900) {
                    $status = 'idle';
                }
            } catch (Throwable $e) {
                // Leave the status as-is when the timestamp cannot be parsed.
            }
        }

        return ['status' => $status, 'result' => $result, 'updated_at' => $updatedAt];
    }
}
