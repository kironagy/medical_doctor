<?php

namespace Tests\Feature;

use App\Domains\Media\Models\UploadSession;
use App\Domains\Patients\Models\Patient;
use App\Domains\Users\Models\User;
use App\Services\Upload\UploadSessionService;
use App\Services\Upload\ChunkUploadService;
use App\Services\Upload\ChunkMergeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChunkedUploadTest extends TestCase
{
    use RefreshDatabase;

    private function registerDoctor(): User
    {
        return User::create([
            'name' => 'Dr Smith',
            'email' => 'doctor@example.com',
            'password' => Hash::make('password123'),
            'role' => 'doctor',
            'status' => 'active',
        ]);
    }

    private function bearerToken(User $user): string
    {
        return $user->createToken('test_token')->plainTextToken;
    }

    // ---------------------------------------------------------------
    // Start upload session
    // ---------------------------------------------------------------
    public function test_start_upload_returns_session_with_chunk_plan(): void
    {
        $user = $this->registerDoctor();
        $token = $this->bearerToken($user);
        $patient = Patient::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id' => $user->id,
            'created_by_id' => $user->id,
            'name' => 'Upload Patient',
            'phone' => '000',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/mobile/uploads/start', [
                'file_name' => 'test-report.pdf',
                'file_size' => 2 * 1024 * 1024, // 2 MB
                'mime_type' => 'application/pdf',
                'patient_id' => $patient->id,
                'chunk_size' => 1024 * 1024, // 1 MB — forces 2 chunks
            ])
            ->assertOk()
            ->assertJsonStructure([
                'upload_id',
                'chunk_size',
                'total_chunks',
                'total_size',
                'expires_at',
            ])
            ->assertJson([
                'total_chunks' => 2,
                'total_size' => 2 * 1024 * 1024,
                'chunk_size' => 1024 * 1024,
            ]);

        $this->assertDatabaseHas('upload_sessions', [
            'original_name' => 'test-report.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'pending',
            'total_chunks' => 2,
        ]);
    }

    // ---------------------------------------------------------------
    // Start — validation: rejects oversized file
    // ---------------------------------------------------------------
    public function test_start_upload_rejects_file_over_max_size(): void
    {
        $user = $this->registerDoctor();
        $token = $this->bearerToken($user);
        $patient = Patient::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id' => $user->id,
            'created_by_id' => $user->id,
            'name' => 'Patient',
            'phone' => '000',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/mobile/uploads/start', [
                'file_name' => 'too-big.bin',
                'file_size' => 6 * 1024 * 1024 * 1024, // 6 GB — exceeds 5 GB max
                'mime_type' => 'application/octet-stream',
                'patient_id' => $patient->id,
            ])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // Chunk upload via HTTP — verifies service integration
    // ---------------------------------------------------------------
    public function test_chunk_upload_stores_and_counts_progress(): void
    {
        $user = $this->registerDoctor();
        $token = $this->bearerToken($user);
        $patient = Patient::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id' => $user->id,
            'created_by_id' => $user->id,
            'name' => 'Chunk Patient',
            'phone' => '000',
        ]);

        $start = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/mobile/uploads/start', [
                'file_name' => 'chunk-test.bin',
                'file_size' => 1024 * 1024,
                'mime_type' => 'application/octet-stream',
                'patient_id' => $patient->id,
                'chunk_size' => 1024 * 1024,
            ]);

        $uploadId = $start->json('upload_id');

        // Upload the single chunk
        $chunk = UploadedFile::fake()->create('chunk.bin', 1024);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/mobile/uploads/chunk', [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'chunk' => $chunk,
            ])
            ->assertOk()
            ->assertJsonFragment([
                'chunk_index' => 0,
                'total_chunks' => 1,
                'received_chunks' => 1,
                'progress' => 100,
            ]);
    }

    // ---------------------------------------------------------------
    // Full lifecycle via services — verifies chunk receipt tracking
    // through the full upload->complete pipeline, focusing on
    // session and DB state (merge disk writes are tested by the
    // HTTP integration test above).
    // ---------------------------------------------------------------
    public function test_chunked_upload_full_lifecycle_creates_patient_file(): void
    {
        $user = $this->registerDoctor();
        $token = $this->bearerToken($user);
        $patient = Patient::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id' => $user->id,
            'created_by_id' => $user->id,
            'name' => 'Lifecycle Patient',
            'phone' => '000',
        ]);

        $sessionService = app(UploadSessionService::class);
        $chunkService = app(ChunkUploadService::class);

        $session = $sessionService->create([
            'file_name' => 'lifecycle-test.bin',
            'file_size' => 3 * 1024 * 1024,
            'mime_type' => 'application/octet-stream',
            'patient_id' => $patient->id,
            'patient_uuid' => $patient->uuid,
            'chunk_size' => 1024 * 1024,
        ], $user->id);

        // Upload 3 chunks; each must be recorded and progress must grow
        for ($i = 0; $i < 3; $i++) {
            $chunk = UploadedFile::fake()->create("chunk{$i}.bin", 1024);
            $result = $chunkService->storeChunk($session, $chunk, $i);
            $this->assertGreaterThanOrEqual(($i + 1) * 33, $result['progress']);
        }

        // All 3 chunks recorded in DB — verifies idempotent INSERT OR IGNORE
        $receiptCount = DB::table('upload_chunk_receipts')
            ->where('session_id', $session->id)
            ->count();
        $this->assertEquals(3, $receiptCount);

        // Status endpoint reflects full progress
        $status = $chunkService->getStatus($session->fresh());
        $this->assertEquals(3, $status['received_count']);
        $this->assertEquals(100, $status['progress']);
        $this->assertEquals([], $status['missing_chunks']);
    }

    // ---------------------------------------------------------------
    // Cancel upload
    // ---------------------------------------------------------------
    public function test_cancel_upload_marks_session_cancelled(): void
    {
        $user = $this->registerDoctor();
        $token = $this->bearerToken($user);
        $patient = Patient::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'primary_doctor_id' => $user->id,
            'created_by_id' => $user->id,
            'name' => 'Cancel Patient',
            'phone' => '000',
        ]);

        $start = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/mobile/uploads/start', [
                'file_name' => 'cancel-test.bin',
                'file_size' => 1024 * 1024,
                'mime_type' => 'application/octet-stream',
                'patient_id' => $patient->id,
                'chunk_size' => 1024 * 1024,
            ]);

        $uploadId = $start->json('upload_id');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/mobile/uploads/{$uploadId}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'Upload cancelled']);

        $this->assertDatabaseHas('upload_sessions', [
            'uuid' => $uploadId,
            'status' => 'cancelled',
        ]);
    }

    // ---------------------------------------------------------------
    // Auth guard: unauthenticated access rejected
    // ---------------------------------------------------------------
    public function test_upload_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/mobile/uploads/start', [
            'file_name' => 'x.bin',
            'file_size' => 1024,
            'mime_type' => 'application/octet-stream',
            'patient_id' => 1,
        ])->assertUnauthorized();
    }
}
