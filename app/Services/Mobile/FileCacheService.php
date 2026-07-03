<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileCacheService
{
    private const CACHE_DIR = 'mobile-cache';

    private const INDEX_FILE = 'cache_index.json';

    private array $index = [];

    public function __construct()
    {
        $this->loadIndex();
    }

    public function has(string $key): bool
    {
        return isset($this->index[$key]) && File::exists($this->index[$key]['path']);
    }

    public function get(string $key): ?string
    {
        if (!$this->has($key)) {
            return null;
        }
        return $this->index[$key]['path'];
    }

    public function put(string $key, string $path): void
    {
        $this->index[$key] = [
            'path' => $path,
            'cached_at' => now()->toIso8601String(),
        ];
        $this->saveIndex();
    }

    public function forget(string $key): void
    {
        if (isset($this->index[$key])) {
            if (File::exists($this->index[$key]['path'])) {
                File::delete($this->index[$key]['path']);
            }
            unset($this->index[$key]);
            $this->saveIndex();
        }
    }

    public function clear(): void
    {
        $dir = storage_path("app/" . self::CACHE_DIR);
        if (File::isDirectory($dir)) {
            File::cleanDirectory($dir);
        }
        $this->index = [];
        $this->saveIndex();
    }

    private function loadIndex(): void
    {
        $path = $this->indexPath();
        if (File::exists($path)) {
            $this->index = json_decode(File::get($path), true) ?? [];
        }
    }

    private function saveIndex(): void
    {
        File::ensureDirectoryExists(storage_path("app/" . self::CACHE_DIR));
        File::put($this->indexPath(), json_encode($this->index, JSON_PRETTY_PRINT));
    }

    private function indexPath(): string
    {
        return storage_path("app/" . self::CACHE_DIR . '/' . self::INDEX_FILE);
    }

    public function cacheFileFromUrl(string $key, string $url, ?string $extension = null): ?string
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $ext = $extension ? '.' . $extension : '';
        $destination = storage_path("app/" . self::CACHE_DIR . "/files/{$key}{$ext}");
        File::ensureDirectoryExists(dirname($destination));

        try {
            $content = file_get_contents($url);
            File::put($destination, $content);
            $this->put($key, $destination);
            return $destination;
        } catch (\Exception $e) {
            Log::warning("Failed to cache file from URL: {$url}", ['error' => $e->getMessage()]);
            return null;
        }
    }
}
