<?php

namespace App\Auth;

use App\Domains\Users\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Session;

class ApiUserProvider implements UserProvider
{
    private function getUserData(): ?array
    {
        $data = Session::get('api_user_data');
        return is_array($data) ? $data : null;
    }

    private function makeUser(?array $data, mixed $identifier = null): ?Authenticatable
    {
        if (!$data) {
            return null;
        }

        $user = new User();
        $user->exists = true;
        $user->forceFill([
            'id' => $data['id'] ?? $identifier,
            'name' => $data['name'] ?? '',
            'email' => $data['email'] ?? '',
            'role' => $data['role'] ?? ($data['roles'][0] ?? 'doctor'),
            'phone' => $data['phone'] ?? '',
            'address' => $data['address'] ?? '',
            'specialization' => $data['specialization'] ?? '',
            'uuid' => $data['uuid'] ?? null,
            'avatar_path' => $data['avatar_path'] ?? null,
            'preferences' => $data['preferences'] ?? [],
            'status' => $data['status'] ?? 'active',
        ]);

        $roleNames = $data['roles'] ?? (isset($data['role']) ? [$data['role']] : ['doctor']);
        $roles = collect($roleNames)->map(function ($name) {
            $role = new \Spatie\Permission\Models\Role();
            $role->name = $name;
            return $role;
        });
        $user->setRelation('roles', $roles);

        return $user;
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->makeUser($this->getUserData(), $identifier);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return $this->retrieveById($identifier);
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}
