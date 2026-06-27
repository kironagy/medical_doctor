<?php

namespace App\Sync\Handlers;

use Illuminate\Database\Eloquent\Model;

interface SyncHandlerInterface
{
    /**
     * Validate the operation payload.
     *
     * @param array $payload
     * @param string $operation
     * @param Model|null $model
     * @return array Array of validation errors, empty if valid.
     */
    public function validate(array $payload, string $operation, ?Model $model = null): array;

    /**
     * Transform the payload before saving to the database.
     * Includes field unsetting, mapping (e.g. UUID to ID), and value conversions.
     *
     * @param array $payload
     * @param string $operation
     * @return array Transformed payload.
     */
    public function transform(array $payload, string $operation): array;

    /**
     * Determine conflict resolution (who wins).
     *
     * @param Model $model Existing database model.
     * @param array $payload Incoming sync payload.
     * @return bool True if incoming payload wins and should overwrite; false if existing model wins.
     */
    public function resolveConflict(Model $model, array $payload): bool;

    /**
     * Apply the operation (insert/update/delete) in the database.
     *
     * @param string $operation ('create', 'update', 'delete')
     * @param array $payload
     * @param string|null $uuid Record UUID.
     * @return array Standard result format: ['uuid' => string, 'status' => string, 'error' => ?string, 'id' => ?int]
     */
    public function apply(string $operation, array $payload, ?string $uuid = null): array;
}
