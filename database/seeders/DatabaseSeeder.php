<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin user
        User::create([
            'name' => 'System Admin',
            'email' => 'admin@gmail.com',
            'password' => bcrypt('admin'),
            'role' => 'admin',
        ]);

        // Default doctor
        User::create([
            'name' => 'Doctor',
            'email' => 'doctor@gmail.com',
            'password' => bcrypt('doctor'),
            'role' => 'doctor',
            'specialization' => 'General',
        ]);

        // Default File Categories
        $categories = [
            ['name' => 'التاريخ الطبي (Medical history)', 'icon' => 'fa-solid fa-book-medical', 'color' => '#3B82F6'],
            ['name' => 'أشعة قبل العملية (Pre-op Radiology)', 'icon' => 'fa-solid fa-x-ray', 'color' => '#8B5CF6'],
            ['name' => 'تحاليل (Investigations)', 'icon' => 'fa-solid fa-vial', 'color' => '#EF4444'],
            ['name' => 'أشعة بعد العملية (Post-op Radiology)', 'icon' => 'fa-solid fa-x-ray', 'color' => '#10B981'],
            ['name' => 'تفاصيل العملية (Operation sheet)', 'icon' => 'fa-solid fa-file-medical', 'color' => '#F59E0B'],
            ['name' => 'التخدير (Anesthesia)', 'icon' => 'fa-solid fa-mask-ventilator', 'color' => '#6366F1'],
            ['name' => 'أدوية المتابعة (Follow-up medications)', 'icon' => 'fa-solid fa-pills', 'color' => '#14B8A6'],
            ['name' => 'ملاحظات العملية (Operation Notes)', 'icon' => 'fa-solid fa-clipboard-user', 'color' => '#64748B'],
        ];

        foreach ($categories as $cat) {
            \App\Models\FileCategory::create($cat);
        }
    }
}
