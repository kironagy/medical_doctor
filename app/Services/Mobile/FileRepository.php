<?php

namespace App\Services\Mobile;

class FileRepository
{
    public function __construct(
        private readonly ApiService $api,
        private readonly FileCacheService $cache
    ) {}

    public function all(string $patientUuid, array $filters = []): array
    {
        return $this->api->get("/patients/{$patientUuid}/files", $filters);
    }

    public function find(string $fileUuid): array
    {
        return $this->api->get("/files/{$fileUuid}");
    }

    public function upload(string $patientUuid, array $files, array $data): array
    {
        return $this->api->upload("/patients/{$patientUuid}/files", $files, $data);
    }

    public function delete(string $fileUuid): array
    {
        return $this->api->delete("/files/{$fileUuid}");
    }

    public function download(string $fileUuid, string $fileName): ?string
    {
        $cacheKey = "file_{$fileUuid}";
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        $destination = storage_path("app/mobile-cache/files/{$fileUuid}_{$fileName}");
        $success = $this->api->download("/files/{$fileUuid}", $destination);

        if ($success) {
            $this->cache->put($cacheKey, $destination);
            return $destination;
        }

        return null;
    }

    public function getThumbnailUrl(string $fileUuid): string
    {
        return "https://prof-hosam-fekry.online/api/v1/mobile/files/{$fileUuid}";
    }
}
