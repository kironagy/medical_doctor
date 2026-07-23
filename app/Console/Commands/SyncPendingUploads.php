<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\OfflineFileRepositoryInterface;
use App\Services\Mobile\ApiService;
use App\Services\OfflineUploadService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

class SyncPendingUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:pending-uploads
                            {--batch=5 : Number of files to process per run}
                            {--force : Upload even if sync_status is not pending_upload}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upload pending offline files to the remote server';

    /**
     * Maximum retry attempts before permanently marking as failed.
     */
    private const MAX_RETRIES = 5;

    public function __construct(
        private readonly OfflineFileRepositoryInterface $offlineRepo,
        private readonly OfflineUploadService $uploadService,
        private readonly ApiService $api,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $force = (bool) $this->option('force');

        // ────────────────────────────────────────────────────────────
        // BLOCKER 3: Recover stuck uploading state
        // Reset records stuck in 'uploading' for more than 10 minutes
        // back to 'pending_upload' so they can be retried.
        // ────────────────────────────────────────────────────────────
        $stuckCount = \Illuminate\Support\Facades\DB::table('offline_files')
            ->where('sync_status', 'uploading')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['sync_status' => 'pending_upload', 'updated_at' => now()]);

        if ($stuckCount > 0) {
            $this->info("Recovered {$stuckCount} stuck uploading record(s).");
        }

        $pendingFiles = $force
            ? $this->offlineRepo->findByStatus('failed')
            : $this->offlineRepo->findPending();

        $pendingFiles = array_slice($pendingFiles, 0, $batchSize);

        if (empty($pendingFiles)) {
            $this->info('No pending uploads to sync.');
            return Command::SUCCESS;
        }

        $this->info("Found " . count($pendingFiles) . " pending file(s) to upload.");
        $successCount = 0;
        $failCount = 0;

        foreach ($pendingFiles as $file) {
            if ($file['retry_count'] >= self::MAX_RETRIES) {
                $this->warn("Skipping {$file['uuid']} — max retries reached.");
                $this->offlineRepo->markFailed(
                    $file['uuid'],
                    'Max retries (' . self::MAX_RETRIES . ') exceeded.'
                );
                $failCount++;
                continue;
            }

            $this->info("Uploading {$file['original_name']} ({$file['uuid']})...");

            try {
                $result = $this->uploadToRemote($file);
                $this->offlineRepo->markSynced($file['uuid'], $result['uuid']);
                $this->info("✓ Uploaded successfully (remote UUID: {$result['uuid']})");

                // Clean up local file from disk after successful sync
                $this->uploadService->deleteLocal($file['local_path']);
                $successCount++;
            } catch (ConnectionException $e) {
                $this->warn("Connection failed: {$e->getMessage()}");
                $this->offlineRepo->incrementRetry($file['uuid']);
                if ($file['retry_count'] + 1 >= self::MAX_RETRIES) {
                    $this->offlineRepo->markFailed($file['uuid'], $e->getMessage());
                }
                $failCount++;
                break; // Stop processing — likely a connectivity issue
            } catch (\Throwable $e) {
                $this->error("Upload failed: {$e->getMessage()}");
                $this->offlineRepo->markFailed($file['uuid'], $e->getMessage());
                $this->offlineRepo->incrementRetry($file['uuid']);
                $failCount++;
            }
        }

        $this->info("Done. {$successCount} uploaded, {$failCount} failed.");

        return $failCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Upload a single pending file to the remote server via ApiService.
     *
     * Uses streaming upload — never loads the entire file into memory.
     */
    private function uploadToRemote(array $file): array
    {
        $absolutePath = $this->uploadService->absolutePath($file['local_path']);

        if (!file_exists($absolutePath)) {
            throw new \RuntimeException('Local file not found on disk: ' . $file['local_path']);
        }

        $this->offlineRepo->markUploading($file['uuid']);

        // Use a temporary UploadedFile-like approach via the existing API
        // The ApiService::upload() method supports file paths as strings
        $response = $this->api->upload(
            "/patients/{$file['patient_uuid']}/files",
            ['file' => $absolutePath],
            [
                'title' => $file['original_name'],
                'desc'  => 'Uploaded from offline sync',
            ]
        );

        if (!isset($response['uuid']) && !isset($response['file']['uuid'])) {
            throw new \RuntimeException('Server response did not include file UUID.');
        }

        $remoteUuid = $response['uuid'] ?? $response['file']['uuid'] ?? null;

        if (!$remoteUuid) {
            throw new \RuntimeException('Could not determine remote file UUID from server response.');
        }

        return ['uuid' => $remoteUuid];
    }
}
