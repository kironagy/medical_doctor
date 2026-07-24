<?php

namespace App\Repositories;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Repositories\Api\ApiCategoryRepository;
use App\Repositories\Eloquent\EloquentCategoryRepository;
use Illuminate\Support\Facades\Log;

/**
 * CategoryRepository — orchestrator: API-first with local SQLite fallback.
 *
 * Follows the exact same pattern as PatientRepository.
 *
 * Strategy:
 *   1. Try fetching from the production API (via ApiCategoryRepository).
 *   2. On success → upsert into local SQLite cache → return merged categories.
 *   3. On failure (offline / connection error) → read from local SQLite cache.
 *   4. If cache is empty and offline → fall back to config defaults (basic).
 *
 * This ensures categories are always available regardless of connectivity.
 *
 * @see PatientRepository for the same pattern used for patients.
 */
class CategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private readonly ApiCategoryRepository $api,
        private readonly EloquentCategoryRepository $local,
    ) {}

    /**
     * Get all categories — API-first, local fallback.
     *
     * @param  int|null  $userId  The doctor's user ID
     * @return array  Array of category arrays
     */
    public function all(?int $userId = null): array
    {
        // ── Try API first (production server is the source of truth) ──────
        try {
            $apiData = $this->api->all($userId);

            if (is_array($apiData) && !empty($apiData)) {
                // Upsert into local SQLite cache for offline use
                $this->local->refresh($userId, $apiData);
                return $apiData;
            }

            Log::debug('[CategoryRepo] API returned empty categories, falling back to local cache');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::info('[CategoryRepo] API unavailable (offline), reading from local cache: ' . $e->getMessage());
        } catch (\Throwable $e) {
            Log::warning('[CategoryRepo] API error, reading from local cache: ' . $e->getMessage());
        }

        // ── Fallback 1: Read from local SQLite cache ──────────────────────
        $localData = $this->local->all($userId);
        if (!empty($localData)) {
            return $localData;
        }

        // ── Fallback 2: Config defaults (last resort) ─────────────────────
        Log::info('[CategoryRepo] Local cache empty, using config defaults');
        return $this->buildFromDefaults();
    }

    /**
     * Force-refresh the local category cache from the API.
     *
     * @param  int|null  $userId  The doctor's user ID
     * @return array  Refreshed categories
     *
     * @throws \Throwable If both API and local cache fail
     */
    public function refresh(?int $userId = null): array
    {
        try {
            $apiData = $this->api->refresh($userId);

            if (is_array($apiData)) {
                return $this->local->refresh($userId, $apiData);
            }
        } catch (\Throwable $e) {
            Log::warning('[CategoryRepo] Refresh failed from API: ' . $e->getMessage());
        }

        // Return whatever we have locally as a best-effort response
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
