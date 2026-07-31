<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Domains\Users\Models\User;
use App\Domains\Patients\Models\Patient;

class ChunkUploadInitTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunk_upload_init_resolves_non_existent_patient_offline()
    {
        // 1. Ensure a default doctor exists in database
        $doctor = User::create([
            'id' => 1,
            'name' => 'Default Doctor',
            'email' => 'doctor@local.test',
            'password' => bcrypt('password'),
        ]);

        // 2. Perform chunk/init request with non-existent patient UUID
        $uuid = '413a6087-b98b-46ca-b130-42b18c963dce';
        $payload = [
            'file_name' => 'Screenshot_2026-07-30.jpg',
            'file_size' => 894160,
            'mime_type' => 'image/jpeg',
            'patient_id' => $uuid,
            'chunk_size' => 5242880,
            'metadata' => [
                'category' => 'medical_history'
            ]
        ];

        // SQLite DB is default on NativePHP (simulated by checking schema or config)
        $response = $this->postJson('/api/v1/chunk/init', $payload);

        // 3. Confirm response is HTTP 200 OK
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'upload_id',
            'chunk_size',
            'total_chunks',
            'total_size',
            'expires_at'
        ]);

        // 4. Confirm the stub patient was created and has a primary_doctor_id assigned
        $patient = Patient::where('uuid', $uuid)->first();
        $this->assertNotNull($patient);
        $this->assertEquals($doctor->id, $patient->primary_doctor_id);
        $this->assertEquals($doctor->id, $patient->created_by_id);
    }
}
