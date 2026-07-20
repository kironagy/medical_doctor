<?php

namespace Tests\Feature;

use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    use RefreshDatabase;

    private function registerDoctor(): User
    {
        return User::create([
            'name'        => 'Dr Smith',
            'email'       => 'doctor@example.com',
            'password'    => Hash::make('password123'),
            'role'        => 'doctor',
            'status'      => 'active',
        ]);
    }

    private function bearerToken(User $user): string
    {
        return $user->createToken('test_token')->plainTextToken;
    }

    // ---------------------------------------------------------------
    // Index
    // ---------------------------------------------------------------

    public function test_list_patients_returns_paginated_data(): void
    {
        $user  = $this->registerDoctor();
        $token = $this->bearerToken($user);

        // DoctorIsolationScope filters to primary_doctor_id == auth user id
        Patient::create([
            'uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id'  => $user->id,
            'created_by_id'      => $user->id,
            'name'               => 'Patient One',
            'phone'              => '111',
        ]);
        Patient::create([
            'uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id'  => $user->id,
            'created_by_id'      => $user->id,
            'name'               => 'Patient Two',
            'phone'              => '222',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/mobile/patients')
            ->assertOk()
            ->assertJsonStructure([
    'data',
    'meta' => ['current_page', 'total', 'per_page'],
])
            ->assertJsonCount(2, 'data');
    }

    // ---------------------------------------------------------------
    // Store
    // ---------------------------------------------------------------

    public function test_create_patient_returns_201_and_persists(): void
    {
        $user  = $this->registerDoctor();
        $token = $this->bearerToken($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/mobile/patients', [
                'name'    => 'New Patient',
                'phone'   => '555-0100',
                'email'   => 'new@example.com',
            ])
            ->assertStatus(201)
            ->assertJsonFragment(['name' => 'New Patient']);

        $this->assertDatabaseHas('patients', [
            'name'  => 'New Patient',
            'email' => 'new@example.com',
        ]);
    }

    // ---------------------------------------------------------------
    // Show
    // ---------------------------------------------------------------

    public function test_show_patient_returns_single_record(): void
    {
        $user    = $this->registerDoctor();
        $token   = $this->bearerToken($user);
        $patient = Patient::create([
            'uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id'  => $user->id,
            'created_by_id'      => $user->id,
            'name'               => 'Visible Patient',
            'phone'              => '999-0000',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/mobile/patients/{$patient->uuid}")
            ->assertOk()
            ->assertJsonFragment(['uuid' => $patient->uuid]);
    }

    // ---------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------

    public function test_update_patient_persists_changes(): void
    {
        $user    = $this->registerDoctor();
        $token   = $this->bearerToken($user);
        $patient = Patient::create([
            'uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id'  => $user->id,
            'created_by_id'      => $user->id,
            'name'               => 'Old Name',
            'phone'              => '000',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/mobile/patients/{$patient->uuid}", [
                'name'      => 'Updated Name',
                'phone'     => '111-2222',
                'diagnosis' => 'Updated diagnosis',
            ])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Updated Name']);

        $this->assertDatabaseHas('patients', [
            'uuid' => $patient->uuid,
            'name' => 'Updated Name',
        ]);
    }

    // ---------------------------------------------------------------
    // Destroy (soft delete)
    // ---------------------------------------------------------------

    public function test_delete_patient_performs_soft_delete(): void
    {
        $user    = $this->registerDoctor();
        $token   = $this->bearerToken($user);
        $patient = Patient::create([
            'uuid'               => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id'  => $user->id,
            'created_by_id'      => $user->id,
            'name'               => 'To Delete',
            'phone'              => '000',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/mobile/patients/{$patient->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('patients', ['uuid' => $patient->uuid]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/mobile/patients/{$patient->uuid}")
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Auth guard
    // ---------------------------------------------------------------------

    public function test_patient_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/mobile/patients')->assertUnauthorized();
    }
}
