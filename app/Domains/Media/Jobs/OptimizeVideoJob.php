<?php

namespace App\Domains\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Domains\Media\Models\PatientFile;

/**
 * Post-upload video handler — medical clinic edition.
 *
 * Previous behaviour (removed):
 *   ❌  ffprobe full metadata extraction
 *   ❌  MP4 faststart re-mux / codec transcode (files already stream fine)
 *   ❌  Multi-timestamp thumbnail extraction (4 attempts)
 *   ❌  HLS adaptive streaming (1080p / 720p / 480p / 360p renditions)
 *
 * Why removed: this is a document-storage system. The original file is served
 * with HTTP Range support (206 Partial Content) so byte-range seeking works
 * perfectly without any transcoding. HLS is a video-platform feature with no
 * benefit here — it was blocking "ready" status for potentially hours.
 *
 * New behaviour:
 *   1. Mark the file "ready" immediately — doctor can view it right away.
 *   2. Dispatch GenerateThumbnailJob to a low-priority background queue.
 *      If thumbnail extraction fails, the file remains fully usable.
 *
 * Total time added to the critical path: ~0 ms.
 */
class OptimizeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries = 2;

    public function __construct(private readonly PatientFile $patientFile) {}

    public function handle(): void
    {
        $t0 = microtime(true);

        // The file is on disk and ready to be served right now.
        // Mark it available without any processing delay.
        $this->patientFile->update(['upload_status' => 'ready']);

        $elapsed = round((microtime(true) - $t0) * 1000, 2);

        // Update the session cache to reflect 'ready' status
        $this->updateSessionStatus('ready');

        // Record timing — best-effort, never blocks marking the file ready.
        try {
            $times = $this->patientFile->processing_times ?? [];
            $times['mark_ready_ms'] = $elapsed;
            $this->patientFile->update(['processing_times' => $times]);
        } catch (\Throwable) {
            // instrumentation is optional
        }

        Log::channel('upload')->info('OptimizeVideoJob: file marked ready immediately', [
            'uuid' => $this->patientFile->uuid,
            'ms'   => $elapsed,
        ]);

        // Thumbnail is optional and runs entirely in the background.
        // Uses the same 'video' queue — no separate worker needed.
        GenerateThumbnailJob::dispatch($this->patientFile)->onQueue('video');
    }

    /**
     * Update the session cache to reflect the current status.
     */
    private function updateSessionStatus(string $status): void
    {
        // Find the session by looking for the file UUID in cache keys
        // We store session→uuid mapping, so we need to find which session has this UUID
        try {
            $fileUuid = $this->patientFile->uuid;
            
            // Check a limited number of recent sessions (cache keys with upload: prefix)
            // This is a best-effort update - the /status endpoint also checks PatientFile directly
            $redis = Cache::getRedis();
            $keys = $redis->keys('*upload:*');
            
            foreach ($keys as $key) {
                $sessionData = Cache::get($key);
                if (is_array($sessionData) && ($sessionData['uuid'] ?? null) === $fileUuid) {
                    $sessionData['status'] = $status;
                    $sessionData['ready_at'] = now()->toIso8601String();
                    Cache::put($key, $sessionData, now()->addHours(6));
                    
                    Log::channel('upload')->debug('OptimizeVideoJob: updated session status', [
                        'file_uuid' => $fileUuid,
                        'status' => $status,
                        'session_key' => $key,
                    ]);
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Session update is best-effort - don't fail the job
            Log::channel('upload')->debug('OptimizeVideoJob: could not update session status', [
                'file_uuid' => $this->patientFile->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        // Even if this job fails somehow, try to mark the file usable.
        try {
            $this->patientFile->update(['upload_status' => 'ready']);
            $this->updateSessionStatus('ready');
        } catch (\Throwable) {
            // best effort
        }

        Log::channel('upload')->error('OptimizeVideoJob failed', [
            'uuid'  => $this->patientFile->uuid,
            'error' => $e->getMessage(),
        ]);
    }
}
