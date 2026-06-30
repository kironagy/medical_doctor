<?php

namespace App\Domains\Users\Services;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Domains\Users\Models\User;

class PermissionService
{
    public function setupDefaultRolesAndPermissions(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create Permissions
        $permissions = [
            'view patients',
            'create patients',
            'edit patients',
            'delete patients',
            'share patients',
            
            'view files',
            'upload files',
            'delete files',
            
            'manage users',
            'manage settings',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // 2. Create Roles and Assign Permissions
        
        // Super Admin (Bypasses all checks via Gate in AuthServiceProvider)
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        
        // Admin
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->givePermissionTo(Permission::all());

        // Doctor
        $doctor = Role::firstOrCreate(['name' => 'doctor']);
        $doctor->givePermissionTo([
            'view patients',
            'create patients',
            'edit patients',
            'delete patients',
            'share patients',
            'view files',
            'upload files',
            'delete files',
        ]);

        // Assistant
        $assistant = Role::firstOrCreate(['name' => 'assistant']);
        $assistant->givePermissionTo([
            'view patients',
            'create patients',
            'edit patients',
            'view files',
            'upload files',
        ]);

        // Receptionist
        $receptionist = Role::firstOrCreate(['name' => 'receptionist']);
        $receptionist->givePermissionTo([
            'view patients',
            'create patients',
        ]);
    }

    public function assignRoleToUser(User $user, string $roleName): void
    {
        $user->assignRole($roleName);
        $user->role = $roleName;
        $user->save();
    }
}
