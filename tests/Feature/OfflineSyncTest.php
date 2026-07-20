<?php

namespace Tests\Feature;

use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;
use App\Repositories\Api\ApiUserRepository;
use App\Services\FullSyncService;
use App\Services\SyncQueueService;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Spatie roles must exist in the in-memory DB before syncUsersLocally runs
        foreach (['super-admin', 'admin', 'doctor'] as $roleName) {
            \Spatie\Permission\Models\Role::findOrCreate($roleName, 'web');
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeUser(string $name, string $role = 'doctor'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::slug($name) . '@example.com',
            'password' => Hash::make('secret'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /**
     * @return array{service: FullSyncService, apiUserRepo: Mockery\MockInterface, apiPatientRepo: Mockery\MockInterface, ...}
     */
    private function buildService(): array
    {
        $patientRepo = Mockery::mock(PatientRepositoryInterface::class);
        $fileRepo = Mockery::mock(PatientFileRepositoryInterface::class);
        $noteRepo = Mockery::mock(PatientNoteRepositoryInterface::class);
        $visitRepo = Mockery::mock(PatientVisitRepositoryInterface::class);
        $userRepo = Mockery::mock(UserRepositoryInterface::class);
        $syncQueue = Mockery::mock(SyncQueueService::class);
        $syncQueue->shouldReceive('processPendingOperations')
            ->andReturn(\Illuminate\Database\Eloquent\Collection::make([]));
        $syncQueue->shouldReceive('markItemResult')->andReturn(null);

        $apiUserRepo = Mockery::spy(ApiUserRepository::class);
        $apiPatientRepo = Mockery::spy(\App\Repositories\Api\ApiPatientRepository::class);
        $apiFileRepo = Mockery::spy(\App\Repositories\Api\ApiPatientFileRepository::class);
        $apiNoteRepo = Mockery::spy(\App\Repositories\Api\ApiPatientNoteRepository::class);
        $apiVisitRepo = Mockery::spy(\App\Repositories\Api\ApiPatientVisitRepository::class);

        $service = new FullSyncService(
            $patientRepo,
            $fileRepo,
            $noteRepo,
            $visitRepo,
            $userRepo,
            $syncQueue,
            $apiPatientRepo,
            $apiFileRepo,
            $apiNoteRepo,
            $apiVisitRepo,
            $apiUserRepo,
        );

        return compact('service', 'apiUserRepo', 'apiPatientRepo', 'apiFileRepo', 'apiNoteRepo', 'apiVisitRepo');
    }

    // ---------------------------------------------------------------
    // Code-level verification: users synced before patients
    // ---------------------------------------------------------------

    public function test_sync_all_code_calls_users_before_patients(): void
    {
        $source = file_get_contents((new \ReflectionClass(\App\Services\FullSyncService::class))->getFileName());

        $userSyncLine = strpos($source, '$this->apiUserRepo->doctors()');
        $patientSyncLine = strpos($source, '$this->apiPatientRepo->all()');

        $this->assertNotFalse($userSyncLine, 'apiUserRepo->doctors() call must exist');
        $this->assertNotFalse($patientSyncLine, 'apiPatientRepo->all() call must exist');
        $this->assertLessThan($patientSyncLine, $userSyncLine,
            'FullSyncService must call apiUserRepo->doctors() BEFORE apiPatientRepo::all()');
    }

    // ---------------------------------------------------------------
    // Code-level verification: syncLocalCache uses withoutGlobalScopes
    // ---------------------------------------------------------------

    public function test_sync_local_cache_uses_without_global_scopes(): void
    {
        $source = file_get_contents((new \ReflectionClass(\App\Services\FullSyncService::class))->getFileName());

        $this->assertStringContainsString('withoutGlobalScopes', $source,
            'syncLocalCache must use withoutGlobalScopes() to bypass DoctorIsolationScope');
    }

    // ---------------------------------------------------------------
    // Code-level verification: FK pragma disabled during sync
    // ---------------------------------------------------------------

    public function test_sync_all_disables_fk_during_bulk_sync(): void
    {
        $source = file_get_contents((new \ReflectionClass(\App\Services\FullSyncService::class))->getFileName());

        $this->assertStringContainsString("PRAGMA foreign_keys", $source,
            'syncAll must disable FK constraints during bulk sync to allow user-first ordering');
    }

    // ---------------------------------------------------------------
    // syncLocalCache: uses withoutGlobalScopes to bypass isolation
    // ---------------------------------------------------------------

    public function test_sync_local_cache_bypasses_doctor_isolation(): void
    {
        $doctorA = $this->makeUser('Sync Doctor A');
        $doctorB = $this->makeUser('Sync Doctor B');

        // Create a patient for doctorB even when authenticated as doctorA
        \Illuminate\Support\Facades\Auth::login($doctorA);

        $remoteRecord = [
            'uuid' => (string) Str::uuid(),
            'name' => 'Cross-Doctor Patient',
            'primary_doctor_id' => $doctorB->id,
            'created_by_id' => $doctorB->id,
            'medical_record_number' => 'SYNC-CROSS',
        ];

        // Call syncLocalCache via reflection (private method)
        $service = new FullSyncService(
            Mockery::mock(PatientRepositoryInterface::class),
            Mockery::mock(PatientFileRepositoryInterface::class),
            Mockery::mock(PatientNoteRepositoryInterface::class),
            Mockery::mock(PatientVisitRepositoryInterface::class),
            Mockery::mock(UserRepositoryInterface::class),
            Mockery::mock(SyncQueueService::class),
            Mockery::mock(\App\Repositories\Api\ApiPatientRepository::class),
            Mockery::mock(\App\Repositories\Api\ApiPatientFileRepository::class),
            Mockery::mock(\App\Repositories\Api\ApiPatientNoteRepository::class),
            Mockery::mock(\App\Repositories\Api\ApiPatientVisitRepository::class),
            Mockery::mock(ApiUserRepository::class),
        );

        $method = new \ReflectionMethod($service, 'syncLocalCache');
        $method->setAccessible(true);
        $method->invoke($service, [$remoteRecord], Patient::class);

        // withoutGlobalScopes bypasses the isolation scope so the patient IS created
        $this->assertDatabaseHas('patients', [
            'uuid' => $remoteRecord['uuid'],
            'name' => 'Cross-Doctor Patient',
            'primary_doctor_id' => $doctorB->id,
        ]);
    }

    // ---------------------------------------------------------------
    // syncUsersLocally: creates users with correct data
    // ---------------------------------------------------------------

    public function test_sync_users_locally_creates_users_with_roles(): void
    {
        $service = new FullSyncService(
            Mockery::mock(PatientRepositoryInterface::class),
            Mockery::mock(PatientFileRepositoryInterface::class),
            Mockery::mock(PatientNoteRepositoryInterface::class),
            Mockery::mock(PatientVisitRepositoryInterface::class),
            Mockery::mock(UserRepositoryInterface::class),
            Mockery::mock(SyncQueueService::class),
            Mockery::mock(\App\Repositories\Api\ApiPatientRepository::class),
            Mockery::mock(\App\Repositories\Api\ApiPatientFileRepository::class),
            Mockery::mock(\App\Repositories\Api\ApiPatientNoteRepository::class),
            Mockery::mock(\App\Repositories\Api\ApiPatientVisitRepository::class),
            Mockery::mock(ApiUserRepository::class),
        );

        $remoteDoctors = [
            ['id' => 10, 'uuid' => 'doc-10', 'name' => 'Sync Doc', 'email' => 'sync@test.com', 'role' => 'doctor'],
            ['id' => 11, 'uuid' => 'doc-11', 'name' => 'Sync Admin', 'email' => 'admin@test.com', 'role' => 'super-admin'],
        ];

        $method = new \ReflectionMethod($service, 'syncUsersLocally');
        $method->setAccessible(true);
        $method->invoke($service, $remoteDoctors);

        $this->assertDatabaseHas('users', ['id' => 10, 'email' => 'sync@test.com', 'name' => 'Sync Doc']);
        $this->assertDatabaseHas('users', ['id' => 11, 'email' => 'admin@test.com', 'name' => 'Sync Admin']);

        // Verify roles are assigned
        $doc = User::find(10);
        $this->assertTrue($doc->hasRole('doctor'), 'Synced user should have doctor role');
    }

    // ---------------------------------------------------------------
    // FK: patients can be created after users exist
    // ---------------------------------------------------------------

    public function test_patients_save_after_users_exist_no_fk_violation(): void
    {
        $doctor = $this->makeUser('FK Target');

        $patient = Patient::create([
            'uuid' => (string) Str::uuid(),
            'primary_doctor_id' => $doctor->id,
            'created_by_id' => $doctor->id,
            'name' => 'FK-safe Patient',
        ]);

        $this->assertDatabaseHas('patients', ['uuid' => $patient->uuid]);
    }
}
