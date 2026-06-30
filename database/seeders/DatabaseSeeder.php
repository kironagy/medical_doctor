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
