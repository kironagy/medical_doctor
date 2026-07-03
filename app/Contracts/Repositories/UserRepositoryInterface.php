<?php

namespace App\Contracts\Repositories;

interface UserRepositoryInterface
{
    public function find(int $id): ?array;
    public function update(int $id, array $data): array;
    public function updatePassword(int $id, string $password): void;
    public function updatePreferences(int $id, array $preferences): void;
    public function doctors(): array;
    public function searchDoctors(string $term): array;
}
