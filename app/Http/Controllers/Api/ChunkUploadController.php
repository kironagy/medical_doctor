<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Services\Upload\UploadSessionService;
use App\Services\Upload\ChunkUploadService;
use App\Services\Upload\ChunkMergeService;
use App\Services\Upload\UploadCleanupService;
use App\Services\Upload\UploadValidationService;
use App\Domains\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Encryption\DecryptException;

class ChunkUploadController extends Controller
{
    public function __construct(
        private readonly UploadSessionService $sessionService,
        private readonly ChunkUploadService $chunkService,
        private readonly ChunkMergeService $mergeService,
        private readonly UploadCleanupService $cleanupService,
        private readonly UploadValidationService $validationService,
    ) {}

    private function api(): ?\Illuminate\Http\Client\PendingRequest
    {
        if (!env('NATIVEPHP_APP_ID')) {
            return null;
        }
        $encryptedToken = session('api_token');
        $token = null;
        if ($encryptedToken) {
            try {
                $token = decrypt($encryptedToken);
            } catch (DecryptException $e) {
                Log::warning('Failed to decrypt API token', ['error' => $e->getMessage()]);
            }
        }
        return Http::timeout(120)
            ->withHeaders(['Accept' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));
    }

    private function apiBaseUrl(): string
    {
        return config('app.mobile_api_url', 'https://prof-hosam-fekry.online/api/v1/mobile');
    }

    public function init(Request $request)
    {
        $validated = $request->validate([
            'file_name' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1|max:5368709120',
            'mime_type' => 'required|string|max:255',
            'patient_id' => 'required',
            'chunk_size' => 'sometimes|integer|min:1048576|max:52428800',
            'metadata' => 'sometimes|array',
            'metadata.title' => 'sometimes|nullable|string|max:255',
            'metadata.desc' => 'sometimes|nullable|string|max:1000',
            'metadata.category' => 'sometimes|nullable|string|max:100',
            'metadata.date' => 'sometimes|nullable|date',
        ]);

        if (env('NATIVEPHP_APP_ID') && \App\Services\NetworkStatusService::isOnline()) {
            if ($token = session('api_token')) {
                try {
                    decrypt($token);
                } catch (DecryptException $e) {
                    Log::error('[ChunkUpload] api_token in session is corrupted, cannot proxy init', ['error' => $e->getMessage()]);
                    return response()->json(['message' => 'Session error: api_token corrupted, please re-login'], 401);
                }
            } else {
                Log::warning('[ChunkUpload] No api_token in session, proxy will be unauthenticated');
            }

            try {
                $response = $this->api()->post($this->apiBaseUrl() . '/chunk/init', $request->all());
                if ($response->failed()) {
                    Log::warning('[ChunkUpload] Remote init failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
                return response()->json($response->json(), $response->status());
            } catch (\Throwable $e) {
                Log::error('[ChunkUpload] Proxy exception during init', [
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['message' => 'Upload initiation failed: ' . $e->getMessage()], 502);
            }
        }

        $patient = is_numeric($request->patient_id)
            ? Patient::findOrFail((int) $request->patient_id)
            : Patient::where('uuid', $request->patient_id)->firstOrFail();

        if ($request->user()->cannot('view', $patient)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = array_merge($request->only(['file_name', 'file_size', 'mime_type']), [
            'patient_id' => $patient->id,
            'chunk_size' => $request->input('chunk_size', 5 * 1024 * 1024),
            'metadata' => $request->input('metadata'),
        ]);

        $session = $this->sessionService->create($data, $request->user()->id);

        return response()->json([
            'upload_id' => $session->uuid,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->total_chunks,
            'total_size' => $session->total_size,
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    }

    public function chunk(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string|size:36',
            'chunk_index' => 'required|integer|min:0',
            'chunk' => 'required|file|max:51200',
        ]);

        if (env('NATIVEPHP_APP_ID') && \App\Services\NetworkStatusService::isOnline()) {
            try {
                $response = $this->api()
                    ->attach('chunk', $request->file('chunk')->get(), 'chunk')
                    ->post($this->apiBaseUrl() . '/chunk/chunk', $request->except('chunk'));
                if ($response->failed()) {
                    Log::warning('[ChunkUpload] Remote chunk upload failed', [
                        'index' => $request->chunk_index,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
                return response()->json($response->json(), $response->status());
            } catch (\Throwable $e) {
                Log::error('[ChunkUpload] Proxy exception during chunk upload', [
                    'index' => $request->chunk_index,
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['message' => 'Chunk upload failed: ' . $e->getMessage()], 502);
            }
        }

        $session = $this->sessionService->findOrFail($request->upload_id);
        if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = $this->chunkService->storeChunk(
            $session,
            $request->file('chunk'),
            (int) $request->chunk_index
        );

        return response()->json($result);
    }

    public function complete(Request $request)
    {
        $request->validate([
            'upload_id' => 'required|string|size:36',
        ]);

        if (env('NATIVEPHP_APP_ID') && \App\Services\NetworkStatusService::isOnline()) {
            try {
                $response = $this->api()->post($this->apiBaseUrl() . '/chunk/complete', $request->all());
                if ($response->failed()) {
                    Log::warning('[ChunkUpload] Remote complete failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                } else {
                    $body = $response->json();
                    if (!empty($body['uuid'])) {
                        try {
                            app(PatientFileRepositoryInterface::class)->find($body['uuid']);
                        } catch (\Throwable $syncErr) {
                            Log::warning('[ChunkUpload] Failed to sync uploaded file to local cache', [
                                'uuid' => $body['uuid'],
                                'error' => $syncErr->getMessage(),
                            ]);
                        }
                    }
                }
                return response()->json($response->json(), $response->status());
            } catch (\Throwable $e) {
                Log::error('[ChunkUpload] Proxy exception during complete', [
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['message' => 'Upload completion failed: ' . $e->getMessage()], 502);
            }
        }

        $session = $this->sessionService->findOrFail($request->upload_id);
        if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $patientFile = $this->mergeService->merge($session);

        if (env('NATIVEPHP_APP_ID') && !\App\Services\NetworkStatusService::isOnline()) {
            \App\Models\PendingOperation::create([
                'uuid' => $patientFile->uuid,
                'entity_type' => 'PatientFile',
                'action' => 'create',
                'payload' => [
                    'patient_uuid' => $session->patient->uuid,
                    'title' => $patientFile->title,
                    'desc' => $patientFile->desc,
                    'category' => $patientFile->category,
                    'date' => $patientFile->date?->format('Y-m-d'),
                    'file_name' => $patientFile->file_name,
                    'local_file_path' => $patientFile->file_path,
                ],
            ]);
        }

        return response()->json([
            'uuid' => $patientFile->uuid,
            'upload_status' => $patientFile->upload_status,
            'url' => $patientFile->url,
            'thumbnail_url' => $patientFile->thumbnail_url,
            'type' => $patientFile->type,
        ]);
    }

    public function cancel(Request $request, string $uuid)
    {
        if (env('NATIVEPHP_APP_ID') && \App\Services\NetworkStatusService::isOnline()) {
            $response = $this->api()->post($this->apiBaseUrl() . '/chunk/' . $uuid . '/cancel');
            return response()->json($response->json(), $response->status());
        }

        $session = $this->sessionService->findOrFail($uuid);
        if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->chunkService->cancel($session);

        return response()->json(['message' => 'Upload cancelled']);
    }

    public function status(Request $request, string $uuid)
    {
        if (env('NATIVEPHP_APP_ID') && \App\Services\NetworkStatusService::isOnline()) {
            $response = $this->api()->get($this->apiBaseUrl() . '/chunk/' . $uuid . '/status');
            return response()->json($response->json(), $response->status());
        }

        $session = $this->sessionService->findOrFail($uuid);
        if (!$this->sessionService->ownedByUser($session, $request->user()->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->chunkService->getStatus($session));
    }
}
