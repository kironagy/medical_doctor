<?php

namespace App\Contracts\Repositories;

interface OfflineFileRepositoryInterface
{
    public function create(array $data): array;

    public function findByUuid(string $uuid): ?array;

    public function findByStatus(string $status): array;

    public function findPending(): array;

    /** Find all non-synced offline files for a patient */
    public function findByPatientUuid(string $patientUuid): array;

    public function markUploading(string $uuid): void;

    public function markSynced(string $uuid, string $remoteUuid): void;

    public function markFailed(string $uuid, string $errorMessage): void;

    public function incrementRetry(string $uuid): void;

    public function delete(string $uuid): void;
}
