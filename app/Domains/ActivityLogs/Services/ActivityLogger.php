<?php

namespace App\Domains\ActivityLogs\Services;

use App\Domains\ActivityLogs\Models\ActivityLog;

class ActivityLogger
{
    public function log(string $action, ?string $entityType = null, ?string $entityUuid = null, array $payload = [], ?\App\Domains\Users\Models\User $user = null): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $user ? $user->id : auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'payload' => $payload,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
