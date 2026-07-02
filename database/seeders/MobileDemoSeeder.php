<?php

namespace Database\Seeders;

use App\Domains\Users\Models\User;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Media\Models\FileCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MobileDemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Admin Doctor',
                'password' => Hash::make('demo1234'),
                'role' => 'doctor',
                'phone' => '01000000000',
                'specialization' => 'General Surgery',
                'code' => 'DOC-001',
                'status' => 'active',
            ]
        );

        $demoDoctor = User::firstOrCreate(
            ['email' => 'doctor@demo.com'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Dr. Ahmed Hassan',
                'password' => Hash::make('demo1234'),
                'role' => 'doctor',
                'phone' => '01011111111',
                'specialization' => 'Cardiology',
                'code' => 'DOC-002',
                'status' => 'active',
            ]
        );

        $categories = ['Medical History', 'Lab Results', 'Imaging', 'Prescriptions', 'Surgery', 'Follow-up', 'Vaccination', 'Insurance', 'Other'];
        foreach ($categories as $index => $catName) {
            FileCategory::firstOrCreate(
                ['name' => $catName],
                [
                    'uuid' => (string) Str::uuid(),
                    'icon' => match ($index) { 0 => 'clipboard', 1 => 'flask', 2 => 'x-ray', 3 => 'capsule', 4 => 'scalpel', 5 => 'stethoscope', 6 => 'syringe', 7 => 'file-text', default => 'folder' },
                    'color' => match ($index) { 0 => '#0ea5e9', 1 => '#8b5cf6', 2 => '#f59e0b', 3 => '#10b981', 4 => '#ef4444', 5 => '#06b6d4', 6 => '#f97316', 7 => '#6366f1', default => '#64748b' },
                ]
            );
        }

        $demoPatients = [
            ['name' => 'Mariam Youssef', 'phone' => '01220000001', 'diagnosis' => 'Hypertension'],
            ['name' => 'Omar Abdelaziz', 'phone' => '01220000002', 'diagnosis' => 'Diabetes Type 2'],
            ['name' => 'Nourhan Ali', 'phone' => '01220000003', 'diagnosis' => 'Asthma'],
            ['name' => 'Khaled Ibrahim', 'phone' => '01220000004', 'diagnosis' => 'Lower Back Pain'],
            ['name' => 'Sara Mahmoud', 'phone' => '01220000005', 'diagnosis' => 'Migraine'],
        ];

        foreach ($demoPatients as $index => $pData) {
            $patient = Patient::firstOrCreate(
                ['uuid' => (string) Str::uuid()],
                [
                    'primary_doctor_id' => $demoDoctor->id,
                    'code' => 'PAT-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
                    'name' => $pData['name'],
                    'phone' => $pData['phone'],
                    'diagnosis' => $pData['diagnosis'],
                    'gender' => $index % 2 === 0 ? 'female' : 'male',
                    'date_of_birth' => now()->subYears(rand(25, 70))->subDays(rand(0, 365)),
                ]
            );

            PatientNote::firstOrCreate(
                ['uuid' => (string) Str::uuid()],
                [
                    'patient_id' => $patient->id,
                    'author_id' => $demoDoctor->id,
                    'category' => 'general',
                    'content' => "Initial consultation notes for {$pData['name']}. Patient presents with {$pData['diagnosis']}. Recommended follow-up in 2 weeks.",
                ]
            );

            PatientVisit::firstOrCreate(
                ['uuid' => (string) Str::uuid()],
                [
                    'patient_id' => $patient->id,
                    'visit_type' => 'checkup',
                    'reason' => 'Routine checkup',
                    'visit_date' => now()->subDays(rand(1, 90)),
                    'diagnosis' => $pData['diagnosis'],
                    'prescription' => 'Prescribed appropriate medication. Follow-up scheduled.',
                    'cost' => rand(50, 500),
                ]
            );
        }

        echo "[Mobile Demo] Seeded: 2 users, " . count($categories) . " categories, " . count($demoPatients) . " patients with notes + visits\n";
    }
}
