<?php

namespace App\Repositories;

use App\Domains\Offline\Models\OfflinePackage;

/**
 * Thin persistence layer for offline-package lifecycle/ownership rows.
 * Deliberately does not know how to fetch or store patient data itself —
 * that orchestration lives in OfflinePackageService. Keeping this isolated
 * from the old sync system (SyncQueueService etc.) is intentional: this is
 * a new, independent feature, not an extension of the pending_* sync queue.
 */
class OfflinePackageRepository
{
    public function find(string $patientUuid, int $ownerUserId): ?OfflinePackage
    {
        return OfflinePackage::where('patient_uuid', $patientUuid)
            ->where('owner_user_id', $ownerUserId)
            ->first();
    }

    public function findOrCreate(string $patientUuid, int $ownerUserId): OfflinePackage
    {
        return OfflinePackage::firstOrCreate(
            ['patient_uuid' => $patientUuid, 'owner_user_id' => $ownerUserId],
            ['status' => OfflinePackage::STATUS_DOWNLOADING]
        );
    }

    public function listForOwner(int $ownerUserId): array
    {
        return OfflinePackage::where('owner_user_id', $ownerUserId)
            ->orderByDesc('downloaded_at')
            ->get()
            ->toArray();
    }

    public function updateStatus(OfflinePackage $package, string $status, array $extra = []): OfflinePackage
    {
        $package->update(array_merge(['status' => $status], $extra));
        return $package->fresh();
    }

    public function delete(string $patientUuid, int $ownerUserId): void
    {
        OfflinePackage::where('patient_uuid', $patientUuid)
            ->where('owner_user_id', $ownerUserId)
            ->delete();
    }
}
