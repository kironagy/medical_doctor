<?php

namespace App\Contracts\Repositories;

interface PatientRepositoryInterface
{
    public function all(): array;
    public function find(string $uuid): ?array;
    public function findByUuid(string $uuid): array;
    public function create(array $data): array;
    public function update(string $uuid, array $data): array;
    public function delete(string $uuid): void;
    public function search(string $term): array;
    public function shared(int $userId): array;
    public function stats(): array;
    public function recent(int $limit): array;
    public function withTrashed(): array;
    public function restore(string $uuid): void;
    public function forceDelete(string $uuid): void;
}
