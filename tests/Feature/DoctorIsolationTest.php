<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Domains\Users\Models\User;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Services\ShareService;
use App\Domains\Users\Services\PermissionService;

class DoctorIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup Roles
        (new PermissionService())->setupDefaultRolesAndPermissions();
    }

    private function createUser()
    {
        return User::create([
            'name' => 'Test User ' . uniqid(),
            'email' => uniqid() . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_doctor_can_only_see_own_patients()
    {
        $doctorA = $this->createUser();
        $doctorA->assignRole('doctor');

        $doctorB = $this->createUser();
        $doctorB->assignRole('doctor');

        $patientA = Patient::create(['primary_doctor_id' => $doctorA->id, 'name' => 'Patient A']);
        $patientB = Patient::create(['primary_doctor_id' => $doctorB->id, 'name' => 'Patient B']);

        $this->actingAs($doctorA);

        $visiblePatients = Patient::all();

        $this->assertCount(1, $visiblePatients);
        $this->assertEquals($patientA->id, $visiblePatients->first()->id);
    }

    public function test_doctor_can_see_shared_patients()
    {
        $doctorA = $this->createUser();
        $doctorA->assignRole('doctor');

        $doctorB = $this->createUser();
        $doctorB->assignRole('doctor');

        $patientA = Patient::create(['primary_doctor_id' => $doctorA->id, 'name' => 'Patient A']);

        // Initially Doctor B cannot see Patient A
        $this->actingAs($doctorB);
        $this->assertCount(0, Patient::all());

        // Doctor A shares Patient A with Doctor B
        $shareService = app(ShareService::class);
        $shareService->sharePatient($patientA, $doctorB, $doctorA, 'read');

        // Now Doctor B can see Patient A
        $this->actingAs($doctorB);
        $visiblePatients = Patient::all();

        $this->assertCount(1, $visiblePatients);
        $this->assertEquals($patientA->id, $visiblePatients->first()->id);
    }

    public function test_admin_can_see_all_patients()
    {
        $admin = $this->createUser();
        $admin->assignRole('admin');

        $doctorA = $this->createUser();
        $doctorA->assignRole('doctor');

        Patient::create(['primary_doctor_id' => $doctorA->id, 'name' => 'Patient A']);
        Patient::create(['primary_doctor_id' => $doctorA->id, 'name' => 'Patient B']);

        $this->actingAs($admin);

        $this->assertCount(2, Patient::all());
    }
}
