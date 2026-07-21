<?php

namespace Tests\Unit;

use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Contracts\Role;
use Tests\TestCase;

class DoctorIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super-admin', 'admin', 'doctor'] as $roleName) {
            /** @var Role $role */
            $role = app(Role::class);
            $role->findOrCreate($roleName, 'web');
        }
    }

    private function makeDoctor(string $name = 'Dr Smith', string $email = null): User
    {
        $email ??= Str::slug($name) . '@example.com';

        /** @var User $user */
        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make('password123'),
            'role'     => 'doctor',
            'status'   => 'active',
        ]);
        $user->assignRole('doctor');
        return $user->fresh();
    }

    private function makeAdmin(string $name = 'Admin User', string $email = null): User
    {
        $email ??= Str::slug($name) . '@example.com';

        /** @var User $user */
        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make('password123'),
            'role'     => 'super-admin',
            'status'   => 'active',
        ]);
        $user->assignRole('super-admin');
        return $user->fresh();
    }

    private function makePatient(User $primaryDoctor, ?string $name = null): Patient
    {
        return Patient::create([
            'uuid'              => (string) Str::uuid(),
            'primary_doctor_id' => $primaryDoctor->id,
            'created_by_id'     => $primaryDoctor->id,
            'name'              => $name ?? 'Patient ' . Str::random(5),
            'phone'             => '000',
        ]);
    }

    public function test_doctor_sees_only_own_patients(): void
    {
        $doctorA = $this->makeDoctor('Doctor A', 'doctor-a-only@example.com');
        $doctorB = $this->makeDoctor('Doctor B', 'doctor-b-only@example.com');

        $patientA = $this->makePatient($doctorA, 'Patient Alpha');
        $patientB = $this->makePatient($doctorB, 'Patient Beta');

        Auth::login($doctorA);

        $visibleIds = Patient::query()->pluck('id')->all();

        $this->assertContains($patientA->id, $visibleIds, 'Doctor A should see their own patient');
        $this->assertNotContains($patientB->id, $visibleIds, 'Doctor A should NOT see Doctor B\'s patient');
    }

    public function test_admin_sees_all_patients(): void
    {
        $admin   = $this->makeAdmin('Admin All', 'admin-all@example.com');
        $doctorA = $this->makeDoctor('Admin Doc A', 'admin-doc-a@example.com');
        $doctorB = $this->makeDoctor('Admin Doc B', 'admin-doc-b@example.com');

        $patientA = $this->makePatient($doctorA, 'Admin Patient Alpha');
        $patientB = $this->makePatient($doctorB, 'Admin Patient Beta');

        Auth::login($admin);

        $visibleIds = Patient::query()->pluck('id')->all();

        $this->assertContains($patientA->id, $visibleIds);
        $this->assertContains($patientB->id, $visibleIds);
        $this->assertCount(2, $visibleIds);
    }

    public function test_doctor_sees_patients_shared_with_them(): void
    {
        $doctorA = $this->makeDoctor('Shareee Doctor', 'shareee-doc@example.com');
        $doctorB = $this->makeDoctor('Sharer Doctor', 'sharer-doc@example.com');

        $patient = $this->makePatient($doctorB, 'Shared Patient');

        $patient->shares()->create([
            'doctor_id'    => $doctorA->id,
            'shared_by_id' => $doctorB->id,
            'permission'   => 'view',
        ]);

        Auth::login($doctorA);

        $visibleIds = Patient::query()->pluck('id')->all();

        $this->assertContains($patient->id, $visibleIds, 'Doctor A should see patient shared with them');
    }

    public function test_without_global_scopes_bypasses_isolation_for_doctor(): void
    {
        $doctorA  = $this->makeDoctor('Scope Doc A', 'scope-doc-a@example.com');
        $doctorB  = $this->makeDoctor('Scope Doc B', 'scope-doc-b@example.com');

        $patientA = $this->makePatient($doctorA, 'Scope Patient A');
        $patientB = $this->makePatient($doctorB, 'Scope Patient B');

        Auth::login($doctorA);

        $allIds = Patient::withoutGlobalScopes()->pluck('id')->all();

        $this->assertContains($patientA->id, $allIds);
        $this->assertContains($patientB->id, $allIds,
            'withoutGlobalScopes must return ALL patients regardless of the authenticated role'
        );
        $this->assertCount(2, $allIds);
    }

    public function test_unauthenticated_scope_does_not_throw(): void
    {
        Auth::logout();

        $result = Patient::all();
        $this->assertCount(0, $result);
    }
}
