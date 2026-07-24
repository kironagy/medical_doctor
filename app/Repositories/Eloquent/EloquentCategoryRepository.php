<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Models\CachedCategory;
use Illuminate\Support\Facades\Log;

/**
 * EloquentCategoryRepository — reads/writes categories from/to local SQLite.
 *
 * This is the LOCAL data source for categories. It stores the merged result
 * of default categories + user custom categories in the cached_categories table.
 *
 * The cache is populated by CategoryRepository (orchestrator) whenever the API
 * is available, and read from here when offline.
 *
 * Upsert Strategy:
 *   Instead of deleting all rows and re-inserting (which could cause a brief
 *   window with no categories), we use updateOrCreate per row. This is atomic
 *   at the row level and preserves the UNIQUE constraint on (user_id, slug).
 */
class EloquentCategoryRepository implements CategoryRepositoryInterface
{
    /**
     * Read all cached categories for a user from the local SQLite.
     *
     * @param  int|null  $userId  The doctor's user ID
     * @return array  Array of category arrays
     */
    public function all(?int $userId = null): array
    {
        $query = CachedCategory::query();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $rows = $query->orderBy('sort_order')->get()->toArray();

        // Normalize to match the expected format (string booleans → bool)
        return array_map(function ($row) {
            return [
                'slug'       => $row['slug'],
                'name'       => $row['name'],
                'icon'       => $row['icon'] ?? 'folder',
                'color'      => $row['color'] ?? '#6b7280',
                'order'      => (int) ($row['sort_order'] ?? 99),
                'is_visible' => (bool) ($row['is_visible'] ?? true),
            ];
        }, $rows);
    }

    /**
     * Refresh (upsert) categories into the local SQLite cache.
     *
     * Uses updateOrCreate per row so that:
     *   - New categories are added
     *   - Existing categories are updated (name, icon, color, order, visibility)
     *   - Categories that were removed from the server are NOT deleted here
     *     (they become stale but harmless). A full cleanup happens when the
     *     workspace controller notices the API returned fewer categories.
     *
     * @param  int|null  $userId       The doctor's user ID
     * @param  array     $categories   Array of category arrays from the API
     * @return array  The upserted categories
     */
    public function refresh(?int $userId = null, array $categories = []): array
    {
        $upsertedSlugs = [];

        foreach ($categories as $cat) {
            $slug = $cat['slug'] ?? '';
            if (empty($slug)) continue;

            CachedCategory::updateOrCreate(
                ['user_id' => $userId, 'slug' => $slug],
                [
                    'name'       => $cat['name'] ?? $slug,
                    'icon'       => $cat['icon'] ?? 'folder',
                    'color'      => $cat['color'] ?? '#6b7280',
                    'sort_order' => (int) ($cat['order'] ?? $cat['sort_order'] ?? 99),
                    'is_visible' => (bool) ($cat['is_visible'] ?? true),
                ]
            );

            $upsertedSlugs[] = $slug;
        }

        // ── Cleanup stale categories that no longer exist on the server ──
        // If the server returned fewer categories than before, the removed
        // ones are still in the local cache. Remove them to keep the cache
        // in sync with the server.
        if (!empty($upsertedSlugs)) {
            $query = CachedCategory::query();
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
            $deleted = $query->whereNotIn('slug', $upsertedSlugs)->delete();
            if ($deleted > 0) {
                Log::debug('[CategoryCache] Cleaned up ' . $deleted . ' stale categories for user ' . ($userId ?? 'null'));
            }
        }

        // Return the cached data as the canonical source
        return $this->all($userId);
    }
}
