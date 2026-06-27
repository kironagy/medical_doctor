<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\SyncQueueItem;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncHandlerTest extends TestCase
{
    use RefreshDatabase;

    private SyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncService = app(SyncService::class);
    }

    /**
     * Test UserSyncHandler password rules.
     */
    public function test_user_sync_handler_skips_creation_without_password(): void
    {
        $uuid = (string) Str::uuid();

        // 1. Creation without password -> Should skip
        $operations = [
            [
                'table' => 'users',
                'operation' => 'create',
                'uuid' => $uuid,
                'payload' => [
                    'uuid' => $uuid,
                    'name' => 'Sync Test Doctor',
                    'email' => 'synctest@doctor.com',
                    'role' => 'doctor',
                    'client_updated_at' => now()->toISOString(),
                ]
            ]
        ];

        $results = $this->syncService->applyOperations($operations);

        $this->assertEquals('skipped', $results[0]['status']);
        $this->assertDatabaseMissing('users', ['email' => 'synctest@doctor.com']);

        // 2. Pre-create the user locally
        $user = User::create([
            'uuid' => $uuid,
            'name' => 'Sync Test Doctor',
            'email' => 'synctest@doctor.com',
            'password' => 'secret_pass',
            'role' => 'doctor',
        ]);

        // 3. Update the user without password -> Should succeed (apply update on name)
        $updateOperations = [
            [
                'table' => 'users',
                'operation' => 'update',
                'uuid' => $uuid,
                'payload' => [
                    'uuid' => $uuid,
                    'name' => 'Updated Sync Doctor Name',
                    'email' => 'synctest@doctor.com',
                    'role' => 'doctor',
                    'client_updated_at' => now()->addMinute()->toISOString(),
                ]
            ]
        ];

        $resultsUpdate = $this->syncService->applyOperations($updateOperations);

        $this->assertEquals('applied', $resultsUpdate[0]['status']);
        $this->assertDatabaseHas('users', [
            'uuid' => $uuid,
            'name' => 'Updated Sync Doctor Name',
        ]);
        // Password should remain intact
        $this->assertTrue(\Hash::check('secret_pass', $user->fresh()->password));
    }

    /**
     * Test PatientSyncHandler code constraint pre-validation.
     */
    public function test_patient_sync_handler_pre_validates_unique_code(): void
    {
        $patient1 = Patient::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Patient One',
            'code' => 'P001',
            'phone' => '1234567890',
            'address' => 'Some address',
        ]);

        // Try to sync a new patient with duplicate code 'P001'
        $uuid2 = (string) Str::uuid();
        $operations = [
            [
                'table' => 'patients',
                'operation' => 'create',
                'uuid' => $uuid2,
                'payload' => [
                    'uuid' => $uuid2,
                    'name' => 'Patient Two',
                    'code' => 'P001',
                    'phone' => '0987654321',
                    'address' => 'Another address',
                    'client_updated_at' => now()->toISOString(),
                ]
            ]
        ];

        $results = $this->syncService->applyOperations($operations);

        $this->assertEquals('failed', $results[0]['status']);
        $this->assertStringContainsString('The patient code [P001] is already in use', $results[0]['error']);
        $this->assertDatabaseMissing('patients', ['uuid' => $uuid2]);
    }

    /**
     * Test isolated transactions: one failed operation does NOT roll back successful ones.
     */
    public function test_batch_sync_is_isolated_and_partial_failures_dont_rollback_successes(): void
    {
        $uuidSuccess = (string) Str::uuid();
        $uuidFail = (string) Str::uuid();

        $operations = [
            // 1. Valid patient creation (Success)
            [
                'table' => 'patients',
                'operation' => 'create',
                'uuid' => $uuidSuccess,
                'payload' => [
                    'uuid' => $uuidSuccess,
                    'name' => 'Successful Patient',
                    'phone' => '11223344',
                    'address' => 'Valid street address',
                    'client_updated_at' => now()->toISOString(),
                ]
            ],
            // 2. Invalid visit creation: missing patient (Fail)
            [
                'table' => 'patient_visits',
                'operation' => 'create',
                'uuid' => $uuidFail,
                'payload' => [
                    'uuid' => $uuidFail,
                    'visit_type' => 'كشف',
                    'reason' => 'عادي',
                    'visit_date' => '2026-06-27',
                    'patient_uuid' => (string) Str::uuid(), // Random non-existent patient UUID
                    'client_updated_at' => now()->toISOString(),
                ]
            ]
        ];

        $results = $this->syncService->applyOperations($operations);

        $this->assertCount(2, $results);
        $this->assertEquals('applied', $results[0]['status']);
        $this->assertEquals('failed', $results[1]['status']);

        // Verify successful record is committed
        $this->assertDatabaseHas('patients', ['uuid' => $uuidSuccess]);
        // Verify failed record was not created
        $this->assertDatabaseMissing('patient_visits', ['uuid' => $uuidFail]);
    }

    /**
     * Test mapping UUID references to local primary keys.
     */
    public function test_uuid_to_id_mapping_for_visits(): void
    {
        $patientUuid = (string) Str::uuid();
        $patient = Patient::create([
            'uuid' => $patientUuid,
            'name' => 'Visit Owner Patient',
            'phone' => '55667788',
            'address' => 'Owner home address',
        ]);

        $visitUuid = (string) Str::uuid();
        $operations = [
            [
                'table' => 'patient_visits',
                'operation' => 'create',
                'uuid' => $visitUuid,
                'payload' => [
                    'uuid' => $visitUuid,
                    'visit_type' => 'متابعة',
                    'reason' => 'متابعة سنوية',
                    'visit_date' => '2026-06-27',
                    'patient_uuid' => $patientUuid,
                    'client_updated_at' => now()->toISOString(),
                ]
            ]
        ];

        $results = $this->syncService->applyOperations($operations);

        $this->assertEquals('applied', $results[0]['status']);
        $this->assertDatabaseHas('patient_visits', [
            'uuid' => $visitUuid,
            'patient_id' => $patient->id, // Resolved local patient_id!
        ]);
    }
}
