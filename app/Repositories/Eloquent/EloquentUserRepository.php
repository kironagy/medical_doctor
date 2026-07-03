<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Domains\Users\Models\User;

class EloquentUserRepository implements UserRepositoryInterface
{
    public function find(int $id): ?array
    {
        return User::find($id)?->toArray();
    }

    public function update(int $id, array $data): array
    {
        $user = User::findOrFail($id);
        $user->update($data);
        return $user->fresh()->toArray();
    }

    public function updatePassword(int $id, string $password): void
    {
        $user = User::findOrFail($id);
        $user->update(['password' => bcrypt($password)]);
    }

    public function updatePreferences(int $id, array $preferences): void
    {
        $user = User::findOrFail($id);
        $prefs = array_merge($user->preferences ?? [], $preferences);
        $user->update(['preferences' => $prefs]);
    }

    public function doctors(): array
    {
        return User::role('doctor')->get()->toArray();
    }

    public function searchDoctors(string $term): array
    {
        return User::role('doctor')
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            })
            ->get()
            ->toArray();
    }
}
