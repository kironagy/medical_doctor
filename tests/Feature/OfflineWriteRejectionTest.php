<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Domains\Users\Models\User;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Models\PatientNote;
use App\Domains\Patients\Models\PatientVisit;
use App\Domains\Media\Models\PatientFile;
use Illuminate\Http\UploadedFile;

/**
 * Phase 2 verification: the test environment runs on DB_CONNECTION=sqlite
 * (phpunit.xml), which is exactly the condition every offline-write guard
 * checks — so these requests exercise the real "genuinely offline device"
 * code path end to end, not a mock of it.
 */
class OfflineWriteRejectionTest extends TestCase
{
    use RefreshDatabase;

    private function createDoctor(): User
    {
        return User::create([
            'id' => 1,
            'name' => 'Default Doctor',
            'email' => 'doctor@local.test',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_patient_create_via_workspace_route_rejected_when_offline()
    {
        $this->createDoctor();

        $response = $this->postJson('/api/v1/workspace/patients', [
            'name' => 'Test Patient',
        ]);

        $response->assertStatus(503);
        $this->assertSame(0, Patient::count());
    }

    public function test_patient_create_via_mobile_route_rejected_when_offline()
    {
        $this->createDoctor();

        $response = $this->postJson('/api/v1/mobile/patients', [
            'name' => 'Test Patient',
        ]);

        $response->assertStatus(503);
        $this->assertSame(0, Patient::count());
    }

    public function test_patient_update_rejected_when_offline_and_leaves_row_unchanged()
    {
        $doctor = $this->createDoctor();
        $patient = Patient::create([
            'name' => 'Original Name',
            'primary_doctor_id' => $doctor->id,
            'created_by_id' => $doctor->id,
        ]);

        $response = $this->putJson("/api/v1/workspace/patients/{$patient->uuid}", [
            'name' => 'Changed Name',
        ]);

        $response->assertStatus(503);
        $this->assertSame('Original Name', $patient->fresh()->name);
    }

    public function test_patient_delete_rejected_when_offline()
    {
        $doctor = $this->createDoctor();
        $patient = Patient::create([
            'name' => 'Still Here',
            'primary_doctor_id' => $doctor->id,
            'created_by_id' => $doctor->id,
        ]);

        $response = $this->deleteJson("/api/v1/workspace/patients/{$patient->uuid}");

        $response->assertStatus(503);
        $this->assertNotNull($patient->fresh());
        $this->assertNull($patient->fresh()->deleted_at);
    }

    public function test_note_create_rejected_when_offline_and_creates_no_stub_patient()
    {
        $this->createDoctor();
        $unknownUuid = '9f0a1b2c-3d4e-5f60-7182-93a4b5c6d7e8';

        $response = $this->postJson("/api/v1/mobile/patients/{$unknownUuid}/notes", [
            'content' => 'Some note content',
        ]);

        $response->assertStatus(503);
        $this->assertNull(Patient::where('uuid', $unknownUuid)->first());
        $this->assertSame(0, PatientNote::count());
    }

    public function test_visit_create_rejected_when_offline_and_creates_no_stub_patient()
    {
        $this->createDoctor();
        $unknownUuid = 'a1b2c3d4-e5f6-4708-9192-a3b4c5d6e7f8';

        $response = $this->postJson("/api/v1/mobile/patients/{$unknownUuid}/visits", [
            'visit_type' => 'checkup',
        ]);

        $response->assertStatus(503);
        $this->assertNull(Patient::where('uuid', $unknownUuid)->first());
        $this->assertSame(0, PatientVisit::count());
    }

    public function test_file_upload_direct_endpoint_rejected_when_offline()
    {
        $doctor = $this->createDoctor();
        $patient = Patient::create([
            'name' => 'File Owner',
            'primary_doctor_id' => $doctor->id,
            'created_by_id' => $doctor->id,
        ]);

        // A well-formed request (file present, passes StoreFileRequest's own
        // validation) is required to reach the controller body at all — the
        // guard lives inside the method, after Laravel's FormRequest
        // validation runs. A malformed/incomplete request offline currently
        // surfaces as a normal 422 validation error instead of 503, since it
        // never reaches the guard — no write happens in that case either, so
        // the core guarantee (no local mutation) still holds either way.
        $response = $this->post("/api/v1/mobile/patients/{$patient->uuid}/files", [
            'file' => UploadedFile::fake()->image('test.jpg'),
        ]);

        $response->assertStatus(503);
        $this->assertSame(0, PatientFile::count());
    }
}
