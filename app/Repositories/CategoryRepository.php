<?php

namespace App\Repositories;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Repositories\Eloquent\EloquentCategoryRepository;
use Illuminate\Support\Facades\Log;

class CategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private readonly EloquentCategoryRepository $local,
    ) {}

    /**
     * Get all categories — local SQLite / DB, config fallback.
     *
     * @param  int|null  $userId  The doctor's user ID
     * @return array  Array of category arrays
     */
    public function all(?int $userId = null): array
    {
        $localData = $this->local->all($userId);
        if (!empty($localData)) {
            return $localData;
        }

        Log::info('[CategoryRepo] Local cache empty, using config defaults');
        return $this->buildFromDefaults();
    }

    /**
     * Refresh categories into local cache.
     *
     * @param  int|null  $userId  The doctor's user ID
     * @param  array     $categories  Optional categories array
     * @return array  Refreshed categories
     */
    public function refresh(?int $userId = null, array $categories = []): array
    {
        if (!empty($categories)) {
            return $this->local->refresh($userId, $categories);
        }

        return $this->local->all($userId) ?: $this->buildFromDefaults();
    }

    /**
     * Build categories from config defaults (offline fallback with no cache).
     *
     * @return array
     */
    private function buildFromDefaults(): array
    {
        $defaults = config('categories', []);
        if (!empty($defaults) && is_array($defaults)) {
            return $defaults;
        }

        // Last-resort fallback: load directly from config file
        $configFile = base_path('config/categories.php');
        if (file_exists($configFile)) {
            return require $configFile;
        }

        return [];
    }
}
