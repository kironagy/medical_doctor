<?php

namespace App\Repositories\Api;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Repositories\Api\Traits\MakesApiRequests;

/**
 * ApiCategoryRepository — fetches merged categories from the production server.
 *
 * Calls GET /categories on the production server, which returns the merged
 * result of default categories (from config/categories.php) + user custom
 * categories (from the user's preferences JSON column).
 *
 * Authentication:
 *   The MakesApiRequests trait sends the Sanctum Bearer token obtained from
 *   ApiService. The production server's CategoryController::index() manually
 *   resolves this token to identify the user and include their custom categories.
 *
 * Used by CategoryRepository (the orchestrator).
 */
class ApiCategoryRepository implements CategoryRepositoryInterface
{
    use MakesApiRequests;

    /**
     * Fetch all categories for a user from the production API.
     *
     * @param  int|null  $userId  Not used directly — the API identifies the user
     *                             from the Bearer token sent via MakesApiRequests.
     * @return array  Array of category arrays
     */
    public function all(?int $userId = null): array
    {
        $response = $this->apiCall('GET', '/categories');
        return $response->json() ?? [];
    }

    /**
     * Refresh from the API (same as all() for the API repo).
     */
    public function refresh(?int $userId = null): array
    {
        return $this->all($userId);
    }
}
