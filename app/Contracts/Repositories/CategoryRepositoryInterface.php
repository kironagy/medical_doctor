<?php

namespace App\Contracts\Repositories;

interface CategoryRepositoryInterface
{
    /**
     * Get all categories for a user.
     *
     * Strategy:
     *   1. Try API first (production server is source of truth)
     *   2. If API fails (offline), read from local SQLite cache
     *   3. If no cache exists, fall back to config defaults
     *
     * @param  int|null  $userId  The doctor's user ID (null = anonymous)
     * @return array  Array of category arrays with keys: slug, name, icon, color, sort_order, is_visible
     */
    public function all(?int $userId = null): array;

    /**
     * Force-refresh the local category cache from the API.
     *
     * Fetches merged categories from the production server and
     * upserts them into the local SQLite cached_categories table.
     *
     * @param  int|null  $userId  The doctor's user ID
     * @return array  The refreshed array of categories
     */
    public function refresh(?int $userId = null): array;
}
