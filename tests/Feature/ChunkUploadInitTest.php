<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Domains\Users\Models\User;
use App\Domains\Patients\Models\Patient;

class ChunkUploadInitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Online-direct architecture cutover: a mutation reaching the embedded
     * (sqlite) instance now means the device is genuinely offline, and
     * offline upload is not supported. This used to silently create a
     * "Patient (xxxxxxxx)" stub row and return 200 — the documented root
     * cause of patients randomly renaming themselves. It must now reject
     * cleanly instead, with no stub patient created.
     */
    public function test_chunk_upload_init_rejects_when_offline_and_creates_no_stub_patient()
    {
        // 1. Ensure a default doctor exists in database
        User::create([
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

        // 3. Confirm the request is cleanly rejected as offline, not silently accepted
        $response->assertStatus(503);

        // 4. Confirm no stub patient was created for the unresolved UUID
        $this->assertNull(Patient::where('uuid', $uuid)->first());
    }
}
