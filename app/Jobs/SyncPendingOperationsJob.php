<?php

namespace App\Jobs;

use App\Models\PendingOperation;
use App\Services\NetworkStatusService;
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
        if (!NetworkStatusService::isOnline()) {
            return;
        }

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
        $apiUrl = env('MOBILE_API_URL');
        $endpoint = $this->getEndpoint($operation->entity_type, $operation->action, $operation->uuid);
        $method = $this->getMethod($operation->action);
        
        $url = rtrim($apiUrl, '/') . $endpoint;
        
        $encryptedToken = session('api_token');
        $token = null;
        if ($encryptedToken) {
            try {
                $token = decrypt($encryptedToken);
            } catch (\Exception $e) {}
        }
        
        $response = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json'])
            ->when($token, fn($c) => $c->withToken($token))
            ->send($method, $url, [
                'json' => $operation->payload
            ]);
            
        if (!$response->successful() && $response->status() !== 404 && $response->status() !== 409) {
            // 404 (not found on update/delete) or 409 (conflict) can be considered non-retriable or handled.
            // But for safety, throw exception on 5xx or general 4xx to retry.
            if ($response->serverError()) {
                throw new \Exception('API returned server error: ' . $response->status());
            }
        }
    }

    private function getEndpoint(string $entityType, string $action, string $uuid): string
    {
        // Convert entity type (e.g. 'Patient') to endpoint (e.g. '/patients')
        $base = '/' . strtolower(\Illuminate\Support\Str::plural($entityType));
        if ($action === 'create') {
            return $base;
        }
        return $base . '/' . $uuid;
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
