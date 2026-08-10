<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use App\Domains\Users\Models\User;
use App\Domains\Patients\Models\Patient;
use App\Domains\Media\Models\PatientFile;
use App\Domains\Offline\Models\OfflinePackage;

/**
 * Phase 3 verification. RemoteApiService is faked at the HTTP layer
 * (Http::fake) rather than mocked at the class level, so the real
 * OfflinePackageService -> RemoteApiService -> resolveUrl() chain runs
 * end to end, including the /files/ URL-resolution change.
 */
class OfflinePackageTest extends TestCase
{
    use RefreshDatabase;

    private function doctor(): User
    {
        return User::create([
            'name' => 'Local Doctor',
            'email' => 'doctor@local.test',
            'password' => bcrypt('password'),
        ]);
    }

    private function fakeProductionFor(string $uuid, array $files): void
    {
        // Each file download uses the HTTP client's `sink` option to stream
        // the response body straight to disk. A single shared Http::response()
        // fixture's underlying stream is exhausted after the first sink-based
        // read, so every subsequent file in the same test would silently fail
        // to write — a test-fixture quirk, not a RemoteApiService bug (verified
        // directly: real sequential downloads work fine). A closure fake hands
        // back a fresh response instance per request, avoiding that entirely.
        Http::fake([
            "*/patients/{$uuid}" => Http::response([
                'data' => [
                    'uuid' => $uuid,
                    'name' => 'Remote Patient',
                    'phone' => '0100000000',
                    'diagnosis' => 'Test diagnosis',
                ],
            ], 200),
            "*/patients/{$uuid}/notes*" => Http::response(['data' => []], 200),
            "*/patients/{$uuid}/visits*" => Http::response(['data' => []], 200),
            "*/patients/{$uuid}/files*" => Http::response(['data' => $files], 200),
            "*/files/*" => fn () => Http::response(str_repeat('x', 1024), 200),
        ]);
    }

    public function test_download_creates_ready_package_with_local_patient_and_file_bytes()
    {
        $doctor = $this->doctor();
        Storage::fake('local');
        $uuid = 'b1c2d3e4-f5a6-4708-9192-a3b4c5d6e7f8';

        $this->fakeProductionFor($uuid, [
            [
                'uuid' => 'aaaaaaaa-1111-4111-8111-111111111111',
                'file_name' => 'A.jpg',
                'mime_type' => 'image/jpeg',
                'size' => 1024,
                'type' => 'image',
                'category' => 'notes',
            ],
        ]);

        $response = $this->postJson("/_native/api/offline-package/{$uuid}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', OfflinePackage::STATUS_READY);

        $package = OfflinePackage::where('patient_uuid', $uuid)->where('owner_user_id', $doctor->id)->first();
        $this->assertNotNull($package);
        $this->assertSame(OfflinePackage::STATUS_READY, $package->status);
        $this->assertNotNull($package->downloaded_at);

        $patient = Patient::withoutGlobalScopes()->where('uuid', $uuid)->first();
        $this->assertNotNull($patient);
        $this->assertSame('Remote Patient', $patient->name);

        $file = PatientFile::withoutGlobalScopes()->where('patient_id', $patient->id)->first();
        $this->assertNotNull($file);
        Storage::disk('local')->assertExists($file->file_path);
    }

    public function test_refresh_removes_deleted_file_and_downloads_new_one()
    {
        // Mirrors the exact spec example: server has A.jpg, B.jpg, C.mp4;
        // another doctor deletes B.jpg and adds D.jpg. After refresh:
        // A unchanged, B removed locally, C unchanged, D downloaded.
        $doctor = $this->doctor();
        Storage::fake('local');
        $uuid = 'c1d2e3f4-a5b6-4708-9192-a3b4c5d6e7f9';

        $fileA = ['uuid' => '11111111-1111-4111-8111-111111111111', 'file_name' => 'A.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'type' => 'image', 'category' => 'notes'];
        $fileB = ['uuid' => '22222222-2222-4222-8222-222222222222', 'file_name' => 'B.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'type' => 'image', 'category' => 'notes'];
        $fileC = ['uuid' => '33333333-3333-4333-8333-333333333333', 'file_name' => 'C.mp4', 'mime_type' => 'video/mp4', 'size' => 10, 'type' => 'video', 'category' => 'notes'];
        $fileD = ['uuid' => '44444444-4444-4444-8444-444444444444', 'file_name' => 'D.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'type' => 'image', 'category' => 'notes'];

        // A single Http::fake() registration whose /files list is read from a
        // mutable reference at request time — calling fakeProductionFor() a
        // second time would just register a second stub for the same
        // pattern, and Laravel matches in registration order, so the first
        // (stale) one would keep winning during refresh.
        $currentFiles = [$fileA, $fileB, $fileC];
        Http::fake([
            "*/patients/{$uuid}" => Http::response(['data' => ['uuid' => $uuid, 'name' => 'Remote Patient']], 200),
            "*/patients/{$uuid}/notes*" => Http::response(['data' => []], 200),
            "*/patients/{$uuid}/visits*" => Http::response(['data' => []], 200),
            "*/patients/{$uuid}/files*" => function () use (&$currentFiles) {
                return Http::response(['data' => $currentFiles], 200);
            },
            "*/files/*" => fn () => Http::response(str_repeat('x', 1024), 200),
        ]);

        $this->postJson("/_native/api/offline-package/{$uuid}")->assertStatus(200);

        $patient = Patient::withoutGlobalScopes()->where('uuid', $uuid)->first();
        $this->assertSame(3, PatientFile::withoutGlobalScopes()->where('patient_id', $patient->id)->count());
        $pathB = PatientFile::withoutGlobalScopes()->where('uuid', $fileB['uuid'])->first()->file_path;
        Storage::disk('local')->assertExists($pathB);

        // Now simulate the other doctor's change: B gone, D added.
        $currentFiles = [$fileA, $fileC, $fileD];
        $refreshResponse = $this->postJson("/_native/api/offline-package/{$uuid}/refresh");
        $refreshResponse->assertStatus(200);
        $refreshResponse->assertJsonPath('status', OfflinePackage::STATUS_READY);

        $remainingUuids = PatientFile::withoutGlobalScopes()->where('patient_id', $patient->id)->pluck('uuid');
        $this->assertCount(3, $remainingUuids);
        $this->assertTrue($remainingUuids->contains($fileA['uuid']));
        $this->assertFalse($remainingUuids->contains($fileB['uuid']), 'B.jpg should have been removed locally');
        $this->assertTrue($remainingUuids->contains($fileC['uuid']));
        $this->assertTrue($remainingUuids->contains($fileD['uuid']), 'D.jpg should have been downloaded');

        Storage::disk('local')->assertMissing($pathB);
    }

    public function test_list_is_scoped_to_owner_and_delete_removes_local_data()
    {
        $doctorA = $this->doctor();
        $doctorB = User::create(['name' => 'Doctor B', 'email' => 'b@local.test', 'password' => bcrypt('password')]);
        Storage::fake('local');
        $uuidA = 'd1e2f3a4-b5c6-4708-9192-a3b4c5d6e7fa';
        $uuidB = 'e1f2a3b4-c5d6-4708-9192-a3b4c5d6e7fb';

        $this->fakeProductionFor($uuidA, []);
        $this->actingAs($doctorA)->postJson("/_native/api/offline-package/{$uuidA}")->assertStatus(200);

        $this->fakeProductionFor($uuidB, []);
        $this->actingAs($doctorB)->postJson("/_native/api/offline-package/{$uuidB}")->assertStatus(200);

        $this->assertCount(1, OfflinePackage::where('owner_user_id', $doctorA->id)->get());
        $this->assertCount(1, OfflinePackage::where('owner_user_id', $doctorB->id)->get());

        // Doctor A's list must not show doctor B's package, and vice versa.
        $listAsA = $this->actingAs($doctorA)->getJson('/_native/api/offline-package');
        $listAsA->assertStatus(200);
        $listAsA->assertJsonCount(1, 'data');
        $listAsA->assertJsonPath('data.0.patient_uuid', $uuidA);

        $listAsB = $this->actingAs($doctorB)->getJson('/_native/api/offline-package');
        $listAsB->assertJsonCount(1, 'data');
        $listAsB->assertJsonPath('data.0.patient_uuid', $uuidB);

        $deleteResponse = $this->actingAs($doctorA)->deleteJson("/_native/api/offline-package/{$uuidA}");
        $deleteResponse->assertStatus(200);

        $this->assertNull(OfflinePackage::where('patient_uuid', $uuidA)->first());
        $this->assertNull(Patient::withoutGlobalScopes()->where('uuid', $uuidA)->first());
        // Doctor B's package must be unaffected by doctor A's delete.
        $this->assertNotNull(OfflinePackage::where('patient_uuid', $uuidB)->first());
    }
}
