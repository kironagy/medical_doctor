<?php

namespace App\Jobs;

use App\Models\PendingOperation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SyncPendingOperationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $operations = PendingOperation::orderBy('created_at', 'asc')->get();

        foreach ($operations as $operation) {
            try {
                $this->processOperation($operation);
                $operation->delete();
            } catch (\Exception $e) {
                Log::error('Failed to sync operation: ' . $operation->id, ['error' => $e->getMessage()]);
                // Stop syncing further operations to maintain order
                break;
            }
        }
    }

    private function processOperation(PendingOperation $operation): void
    {
        $apiUrl = env('MOBILE_API_URL', 'https://prof-hosam-fekry.online/api/v1/mobile');
        $payload = $operation->payload ?? [];
        $endpoint = $this->getEndpoint($operation->entity_type, $operation->action, $operation->uuid, $payload);
        $method = $this->getMethod($operation->action);
        
        $url = rtrim($apiUrl, '/') . $endpoint;
        
        $encryptedToken = session('api_token');
        $token = null;
        if ($encryptedToken) {
            try {
                $token = decrypt($encryptedToken);
            } catch (\Exception $e) {}
        }
        
        $http = Http::timeout(120)
            ->withHeaders(['Accept' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token));

        if ($operation->entity_type === 'PatientFile' && $operation->action === 'create') {
            $localPath = $payload['local_file_path'] ?? null;
            if ($localPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($localPath)) {
                $fileContents = \Illuminate\Support\Facades\Storage::disk('local')->get($localPath);
                $fileFields = \Illuminate\Support\Arr::except($payload, ['local_file_path', 'file_name', 'patient_uuid']);
                
                $http = $http->attach('file', $fileContents, $payload['file_name']);
                foreach ($fileFields as $k => $v) {
                    if ($v !== null) $http = $http->attach($k, $v);
                }
                $response = $http->post($url);
            } else {
                throw new \Exception("Local file not found for sync: " . $localPath);
            }
        } else {
            $jsonPayload = \Illuminate\Support\Arr::except($payload, ['patient_uuid']);
            $response = $http->send($method, $url, $jsonPayload);
        }
            
        if ($response->successful()) {
            $resBody = $response->json();
            $newUuid = $resBody['uuid'] ?? $resBody['data']['uuid'] ?? null;
            if ($newUuid && $newUuid !== $operation->uuid) {
                $modelClass = match ($operation->entity_type) {
                    'Patient' => \App\Domains\Patients\Models\Patient::class,
                    'PatientNote' => \App\Domains\Patients\Models\PatientNote::class,
                    'PatientVisit' => \App\Domains\Patients\Models\PatientVisit::class,
                    'PatientFile' => \App\Domains\Media\Models\PatientFile::class,
                    default => null,
                };
                if ($modelClass) {
                    $modelClass::where('uuid', $operation->uuid)->update(['uuid' => $newUuid]);
                }
            }
        } else {
            // Delete operation if rejected by server to prevent blocking the queue
            if ($response->status() === 422 || $response->status() === 409 || $response->status() === 404 || $response->status() === 403) {
                Log::warning("Sync operation rejected by API: " . $operation->id . " Status: " . $response->status(), [
                    'body' => $response->body()
                ]);
                return;
            }
            throw new \Exception('Sync failed with status ' . $response->status() . ': ' . $response->body());
        }
    }

    private function getEndpoint(string $entityType, string $action, string $uuid, ?array $payload): string
    {
        $patientUuid = $payload['patient_uuid'] ?? '';

        return match ($entityType) {
            'Patient' => match ($action) {
                'create' => '/patients',
                default => '/patients/' . $uuid,
            },
            'PatientNote' => match ($action) {
                'create' => "/patients/{$patientUuid}/notes",
                default => "/patients/{$patientUuid}/notes/{$uuid}",
            },
            'PatientVisit' => match ($action) {
                'create' => "/patients/{$patientUuid}/visits",
                default => "/patients/{$patientUuid}/visits/{$uuid}",
            },
            'PatientFile' => match ($action) {
                'create' => "/patients/{$patientUuid}/files",
                default => "/files/{$uuid}",
            },
            default => throw new \Exception("Unknown entity type: {$entityType}"),
        };
    }

    private function getMethod(string $action): string
    {
        return match ($action) {
            'create' => 'POST',
            'update' => 'PUT',
            'delete' => 'DELETE',
            default => 'GET',
        };
    }
}
