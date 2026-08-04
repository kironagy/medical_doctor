<?php

namespace Database\Seeders;

use App\Domains\Users\Models\User;
use App\Domains\Users\Services\PermissionService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        (new PermissionService())->setupDefaultRolesAndPermissions();

        // On the embedded mobile app (SQLite) the real doctor account is created
        // from the production server during the first login. Seeding demo users
        // here would make AuthController::showLogin() auto-login as "Admin User"
        // — the login screen would never appear, no API token would be obtained,
        // and the workspace would stay empty. These demo accounts also carry a
        // well-known password, which must never ship on a device.
        if (config('database.default') === 'sqlite') {
            return;
        }

        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@medical.test',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('super-admin');

        $doctor = User::create([
            'name' => 'Dr. Ahmed',
            'email' => 'doctor@medical.test',
            'password' => bcrypt('password'),
        ]);
        $doctor->assignRole('doctor');
    }
}
