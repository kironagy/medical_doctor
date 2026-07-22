<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Repositories\Api\Traits\MakesApiRequests;
use Illuminate\Support\Facades\Log;

class ApiPatientRepository implements PatientRepositoryInterface
{
    use MakesApiRequests;

    public function all(): array
    {
        $body = $this->apiCall('GET', '/patients', ['per_page' => 1000])->json() ?? [];
        $patients = $body['data'] ?? $body['patients'] ?? $body ?? [];
        $uuids = collect($patients)->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray();
        Log::channel('single')->info('[PATIENT_DEBUG] ApiPatientRepo::all()', [
            'total_in_response' => count($patients),
            'uuids' => $uuids,
            'raw_keys' => array_keys($body),
        ]);
        return $patients;
    }

    public function find(string $uuid): ?array
    {
        $response = $this->apiCall('GET', '/patients/' . $uuid);
        if ($response->notFound()) return null;
        $body = $response->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function findByUuid(string $uuid): array
    {
        $result = $this->find($uuid);
        if (!$result) throw new \RuntimeException('Patient not found.');
        return $result;
    }

    public function create(array $data): array
    {
        $body = $this->apiCall('POST', '/patients', $data)->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function update(string $uuid, array $data): array
    {
        $body = $this->apiCall('PUT', '/patients/' . $uuid, $data)->json() ?? [];
        return $body['data'] ?? $body;
    }

    public function delete(string $uuid): void
    {
        $this->apiCall('DELETE', '/patients/' . $uuid);
    }

    public function search(string $term): array
    {
        $body = $this->apiCall('GET', '/patients', ['search' => $term, 'per_page' => 1000])->json() ?? [];
        $patients = $body['data'] ?? $body['patients'] ?? [];
        Log::channel('single')->info('[PATIENT_DEBUG] ApiPatientRepo::search()', [
            'term' => $term,
            'total_in_response' => count($patients),
            'uuids' => collect($patients)->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray(),
        ]);
        return $patients;
    }

    public function shared(int $userId): array
    {
        $all = $this->all();
        return array_values(array_filter($all, fn($p) => ($p['primary_doctor_id'] ?? null) !== $userId));
    }

    public function stats(): array
    {
        return $this->apiCall('GET', '/dashboard/stats')->json() ?? [];
    }

    public function recent(int $limit): array
    {
        $body = $this->apiCall('GET', '/patients', ['per_page' => $limit])->json() ?? [];
        return $body['data'] ?? $body['patients'] ?? [];
    }

    public function paginated(int $perPage = 10, int $page = 1, ?string $status = null, ?string $search = null, ?string $updatedSince = null): array
    {
        $params = ['per_page' => $perPage, 'page' => $page];
        if ($status) {
            $params['status'] = $status;
        }
        if ($search && strlen($search) >= 2) {
            $params['search'] = $search;
        }
// Only fetch records updated since the given timestamp
        if ($updatedSince) {
            $params['updated_since'] = $updatedSince;
        }
        $body = $this->apiCall('GET', '/patients', $params)->json() ?? [];
        $dataItems = $body['data'] ?? [];
        Log::channel('single')->info('[PATIENT_DEBUG] ApiPatientRepo::paginated()', [
            'per_page' => $perPage,
            'page' => $page,
            'status' => $status,
            'total_from_api' => $body['meta']['total'] ?? $body['total'] ?? 'N/A',
            'returned_count' => count($dataItems),
            'uuids' => collect($dataItems)->map(fn($p) => ($p['uuid'] ?? '?') . ':' . ($p['name'] ?? '?') . ':' . ($p['code'] ?? '?'))->toArray(),
            'raw_keys' => array_keys($body),
        ]);
        return $body;
    }

    public function withTrashed(): array
    {
        return $this->all();
    }

    public function restore(string $uuid): void {}

    public function forceDelete(string $uuid): void
    {
        $this->delete($uuid);
    }
}
