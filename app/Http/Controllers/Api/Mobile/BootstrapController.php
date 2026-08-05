<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Domains\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BootstrapController — Cache all master data required for offline operation.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────
 *
 * When the app goes offline, forms must still work. Dropdowns for:
 *   - Categories (file categories for uploads)
 *   - Visit types
 *   - Doctors list (for sharing)
 *   - User profile
 *
 * ... must be populated WITHOUT making server calls.
 *
 * This controller is called immediately after the first successful login.
 * It fetches all master data from the PRODUCTION server and caches it locally
 * in the SQLite database. When offline, forms read from this local cache.
 *
 * ROUTE (SQLite only — no-op on production):
 *   GET /_native/api/bootstrap/refresh
 *
 * ─────────────────────────────────────────────────────────────────────────
 */
class BootstrapController extends Controller
{
    /**
     * Return all data needed immediately after login.
     *
     * This is called by the embedded Laravel and serves data FROM local SQLite.
     * The actual production fetch happens in refreshCache() below.
     */
    public function data(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user'        => $user,
            'categories'  => $this->getCachedCategories(),
            'visit_types' => $this->getVisitTypes(),
        ]);
    }

    /**
     * Fetch and cache all master data from the production server.
     *
     * Called by the frontend after login via /_native/api/bootstrap/refresh.
     * Stores categories and settings in local SQLite tables.
     */
    public function refreshCache(Request $request)
    {
        if (config('database.default') !== 'sqlite') {
            return response()->json(['success' => false, 'message' => 'Only applicable on embedded Laravel']);
        }

        $token = $request->bearerToken()
            ?? (string) $request->input('token')
            ?? null;

        if (!$token) {
            // Try to load from stored token file
            try {
                $api = app(\App\Services\Mobile\ApiService::class);
                $token = $api->getToken();
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if (!$token) {
            return response()->json(['success' => false, 'message' => 'No API token available — cannot fetch master data'], 200);
        }

        // Store the token for sync engine use
        try {
            app(\App\Services\Mobile\ApiService::class)->setToken($token);
        } catch (\Throwable $e) {
            Log::warning('[Bootstrap] Could not store token: ' . $e->getMessage());
        }

        $cached = ['categories' => 0, 'errors' => []];
        $userId = null;

        // ── 1. Cache user profile first to resolve local userId ──────────
        try {
            $meResponse = \Illuminate\Support\Facades\Http::timeout(10)
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(rtrim(config('app.mobile_api_url', config('app.url')), '/') . '/api/v1/me');

            if ($meResponse->successful()) {
                $userData = $meResponse->json();
                if (!empty($userData['id'])) {
                    $userId = $userData['id'];
                    // Upsert user in local SQLite
                    $existing = \App\Domains\Users\Models\User::where('email', $userData['email'])->first();
                    \App\Domains\Users\Models\User::unguard();
                    if ($existing) {
                        $existing->update([
                            'name'       => $userData['name'] ?? $existing->name,
                            'avatar_url' => $userData['avatar_url'] ?? null,
                        ]);
                    } else {
                        \App\Domains\Users\Models\User::create([
                            'id'             => $userData['id'],
                            'name'           => $userData['name'],
                            'email'          => $userData['email'],
                            'role'           => $userData['role'] ?? 'doctor',
                            'password'       => bcrypt('password'),
                            'uuid'           => $userData['uuid'] ?? (string) \Illuminate\Support\Str::uuid(),
                            'specialization' => $userData['specialization'] ?? null,
                        ]);
                    }
                    \App\Domains\Users\Models\User::reguard();
                    $cached['user'] = $userData['email'] ?? 'unknown';
                }
            }
        } catch (\Throwable $e) {
            $cached['errors'][] = 'user: ' . $e->getMessage();
            Log::warning('[Bootstrap] Failed to cache user profile: ' . $e->getMessage());
        }

        // Resolve fallback userId if profile call failed or was offline
        if (!$userId) {
            $localUser = \App\Domains\Users\Models\User::first();
            $userId = $localUser ? $localUser->id : 1;
        }

        // ── 2. Cache categories with user ID association ──────────────────
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withToken($token)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(rtrim(config('app.mobile_api_url', config('app.url')), '/') . '/api/v1/mobile/categories');

            if ($response->successful()) {
                $categories = $response->json('data') ?? $response->json() ?? [];
                if (!empty($categories)) {
                    // Truncate only for this specific user to support multi-doctor offline use
                    DB::table('cached_categories')->where('user_id', $userId)->delete();
                    foreach ($categories as $cat) {
                        DB::table('cached_categories')->insert([
                            'user_id'      => $userId,
                            'uuid'         => $cat['uuid'] ?? \Illuminate\Support\Str::uuid(),
                            'name'         => $cat['name'] ?? 'Unnamed',
                            'slug'         => $cat['slug'] ?? \Illuminate\Support\Str::slug($cat['name'] ?? 'unnamed'),
                            'icon'         => $cat['icon'] ?? null,
                            'color'        => $cat['color'] ?? null,
                            'sort_order'   => $cat['sort_order'] ?? 0,
                            'is_active'    => $cat['is_active'] ?? true,
                            'raw_json'     => json_encode($cat),
                            'synced_at'    => now(),
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                        $cached['categories']++;
                    }
                    Log::info('[Bootstrap] Cached ' . $cached['categories'] . ' categories for user ' . $userId);
                }
            } else {
                $cached['errors'][] = 'categories: HTTP ' . $response->status();
            }
        } catch (\Throwable $e) {
            $cached['errors'][] = 'categories: ' . $e->getMessage();
            Log::warning('[Bootstrap] Failed to cache categories: ' . $e->getMessage());
        }

        // ── 3. Hydrate the local patient cache (first page only) ───────────
        // Intentionally NOT a full sync — only the first patient page (same
        // page size the workspace UI uses) is fetched and merged as a delta
        // (insert new / update changed, local pending_* always wins). Without
        // this, SQLite's `patients` table stays empty until the user
        // explicitly presses "Sync Now".
        try {
            $patientCounts = app(\App\Services\Sync\DownloadSyncService::class)->cacheFirstPage(10);
            $cached['patients'] = $patientCounts;
        } catch (\Throwable $e) {
            $cached['errors'][] = 'patients: ' . $e->getMessage();
            Log::warning('[Bootstrap] Failed to cache patients: ' . $e->getMessage());
        }

        return response()->json([
            'success'  => true,
            'cached'   => $cached,
            'message'  => 'Bootstrap cache refreshed',
        ]);
    }

    private function getCachedCategories(): array
    {
        try {
            return DB::table('cached_categories')
                ->orderBy('sort_order')
                ->get()
                ->map(fn($r) => json_decode($r->raw_json, true) ?? (array) $r)
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getVisitTypes(): array
    {
        // Static visit types — these rarely change and don't need server sync
        return [
            ['id' => 'general',    'label' => 'General'],
            ['id' => 'follow_up',  'label' => 'Follow Up'],
            ['id' => 'emergency',  'label' => 'Emergency'],
            ['id' => 'surgery',    'label' => 'Surgery'],
            ['id' => 'consultation','label'=> 'Consultation'],
            ['id' => 'lab',        'label' => 'Lab / Tests'],
            ['id' => 'x_ray',      'label' => 'X-Ray / Imaging'],
            ['id' => 'other',      'label' => 'Other'],
        ];
    }
}
