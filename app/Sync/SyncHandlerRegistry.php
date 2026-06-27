<?php

namespace App\Sync;

use App\Sync\Handlers\SyncHandlerInterface;
use App\Sync\Handlers\UserSyncHandler;
use App\Sync\Handlers\PatientSyncHandler;
use App\Sync\Handlers\VisitSyncHandler;
use App\Sync\Handlers\FileSyncHandler;
use App\Sync\Handlers\CategorySyncHandler;
use InvalidArgumentException;

class SyncHandlerRegistry
{
    private array $handlers = [];

    public function __construct()
    {
        $this->handlers = [
            'users' => UserSyncHandler::class,
            'patients' => PatientSyncHandler::class,
            'patient_visits' => VisitSyncHandler::class,
            'patient_files' => FileSyncHandler::class,
            'file_categories' => CategorySyncHandler::class,
        ];
    }

    public function getHandler(string $table): SyncHandlerInterface
    {
        if (!isset($this->handlers[$table])) {
            throw new InvalidArgumentException("No synchronization handler registered for table [{$table}].");
        }

        return app($this->handlers[$table]);
    }

    public function hasHandler(string $table): bool
    {
        return isset($this->handlers[$table]);
    }
}
