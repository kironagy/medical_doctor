<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Upsert: create or update admin account
        DB::table('users')->updateOrInsert(
            ['email' => 'admin@gmail.com'],
            [
                'name'              => 'Admin',
                'email'             => 'admin@gmail.com',
                'password'          => Hash::make('admin'),
                'email_verified_at' => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]
        );

        $this->command->info('✅ Admin user created: admin@gmail.com / admin');
    }
}
