<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\DoctorController;

// ── Session Restore (auto-login for Embedded Laravel) ────────────────
// The embedded Laravel application does NOT use Sanctum tokens for local
// authentication. When the WebView restarts, the frontend calls this
// endpoint to auto-establish the web session using the local database user.
// The production API token (from localStorage) is restored via ApiService.
Route::post('/api/session/restore', function (\Illuminate\Http\Request $request) {
    try {
        // ── Auto-login the local user ──────────────────────────────────
        // The embedded Laravel is a single-user device. Find the first
        // (and only) user in the local SQLite and establish the session.
        // This replaces the previous Sanctum token validation which required
        // a valid PersonalAccessToken in the local database. Since the local
        // application no longer uses Sanctum, we auto-login directly.
        /** @var \App\Domains\Users\Models\User|null $user */
        $user = \App\Domains\Users\Models\User::first();

        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('Session restore failed: no user found in local database');
            return response()->json(['error' => 'No user configured'], 401);
        }

        // Log the user in via web session
        \Illuminate\Support\Facades\Auth::login($user);
        $request->session()->regenerate();

        // ── Restore the production API token if provided ─────────────────
        // On app restart, the frontend sends the persisted api_token from
        // localStorage. Without this, the sync engine would send requests
        // to the production server without authentication (401).
        $apiToken = $request->input('api_token');
        if ($apiToken) {
            try {
                app(\App\Services\Mobile\ApiService::class)->setToken($apiToken);
                \Illuminate\Support\Facades\Log::info('Remote API token restored from localStorage');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to restore API token: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'user' => array_merge($user->toArray(), [
                'roles' => $user->roles->pluck('name'),
                'role' => $user->roles->first()?->name,
            ]),
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('Session restore failed: ' . $e->getMessage());
        return response()->json(['error' => 'Session restore failed'], 500);
    }
});

Route::get('/', function () {
    if (auth()->check() && (auth()->user()->hasRole('super-admin') || auth()->user()->role === 'super-admin')) {
        return redirect()->route('admin.doctors.index');
    }
    return redirect('/dashboard');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

use App\Http\Controllers\PatientController;

// Debug trace logging endpoint (Phase 7 investigation — MUST be outside auth middleware
// so the JS trace() calls work even when session cookies are unavailable/expired)
Route::post('/_native/api/debug/trace', function (\Illuminate\Http\Request $req) {
    $msg = $req->input('message', 'no message');
    $line = now()->format('H:i:s.v') . ' ' . $msg . "\n";
    // Write to internal storage for debugging
    $intFile = storage_path('logs/traces.log');
    @file_put_contents($intFile, $line, FILE_APPEND | LOCK_EX);
    // Also write to /data/local/tmp/ for adb pull access
    @file_put_contents('/data/local/tmp/np_traces.txt', $line, FILE_APPEND | LOCK_EX);
    return response()->json(['ok' => true]);
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    Route::get('patients/shared', [PatientController::class, 'shared'])->name('patients.shared');
    Route::resource('patients', PatientController::class)->parameters([
        'patients' => 'uuid'
    ]);

    Route::post('/notes', [\App\Http\Controllers\PatientController::class, 'storeNote']);

    // Admin Routes
    Route::group(['prefix' => 'admin', 'as' => 'admin.', 'middleware' => ['role:super-admin']], function () {
        Route::get('/', [App\Http\Controllers\Admin\DoctorController::class, 'index'])->name('dashboard');
        Route::resource('doctors', App\Http\Controllers\Admin\DoctorController::class);
        Route::post('doctors/{doctor}/suspend', [App\Http\Controllers\Admin\DoctorController::class, 'suspend'])->name('doctors.suspend');
        Route::get('doctors/{doctor}/patients', [App\Http\Controllers\Admin\DoctorController::class, 'patients'])->name('doctors.patients');
        Route::get('doctors/{doctor}/files', [App\Http\Controllers\Admin\DoctorController::class, 'files'])->name('doctors.files');
    });

    // Doctor Workspace
    Route::get('/workspace', [\App\Http\Controllers\WorkspaceController::class, 'index'])->name('workspace');

    // Settings Routes
    Route::group(['prefix' => 'settings', 'as' => 'settings.'], function () {
        Route::get('/', [\App\Http\Controllers\SettingsController::class, 'index'])->name('index');
        Route::post('/profile', [\App\Http\Controllers\SettingsController::class, 'updateProfile'])->name('profile');
        Route::delete('/avatar', [\App\Http\Controllers\SettingsController::class, 'removeAvatar'])->name('avatar.remove');
        Route::put('/password', [\App\Http\Controllers\SettingsController::class, 'updatePassword'])->name('password');
        Route::put('/preferences', [\App\Http\Controllers\SettingsController::class, 'updatePreferences'])->name('preferences');
    });
});

// ── Internal SPA API Routes (OUTSIDE auth middleware) ─────────────────
// These are JSON API routes called by axios, NOT browser form submissions.
// Authentication is handled at the CONTROLLER level, not middleware level.
// When OFFLINE, the embedded Laravel has no session/tokens, so middleware
// auth would ALWAYS return 401 before the controller can handle null user.
// When ONLINE, these requests go to the production server (EXTERNAL),
// which has its own auth — so removing middleware here is safe.
// The controllers already have their own auth guards (e.g. storePatient()
// handles null user gracefully, updatePatient() returns 401 if no user).
//
// 🚫 CSRF excluded — these are JSON API routes, not browser form submissions.
// When offline, the embedded Laravel runtime has no valid CSRF token.
// Without this exemption, all POST/PUT/DELETE operations return HTTP 419.
Route::prefix('api/v1')->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->group(function () {
    // Client-side error logging endpoint (always returns 200 to avoid feedback loops)
    Route::post('/log/client-error', function (\Illuminate\Http\Request $request) {
        \Illuminate\Support\Facades\Log::channel('daily')->warning('CLIENT_ERROR', $request->all());
        return response()->json(['ok' => true]);
    });

    // Chunked upload endpoints
    Route::post('/chunk/init', [\App\Http\Controllers\Api\ChunkUploadController::class, 'init']);
    Route::post('/chunk/chunk', [\App\Http\Controllers\Api\ChunkUploadController::class, 'chunk']);
    Route::post('/chunk/complete', [\App\Http\Controllers\Api\ChunkUploadController::class, 'complete']);
    Route::post('/chunk/{uuid}/cancel', [\App\Http\Controllers\Api\ChunkUploadController::class, 'cancel']);
    Route::get('/chunk/{uuid}/status', [\App\Http\Controllers\Api\ChunkUploadController::class, 'status']);

    // Direct upload endpoint
    Route::post('/patients/{patientUuid}/files', [\App\Http\Controllers\Api\UploadController::class, 'store']);

    // Optional progress endpoint for compatibility
    Route::get('/uploads/progress', [\App\Http\Controllers\Api\UploadController::class, 'progress']);

    Route::get('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'streamDirect'])->name('api.files.stream');
    Route::get('/files/{uuid}/signed-url', [\App\Http\Controllers\Api\FileAccessController::class, 'generateSignedUrl']);
    Route::get('/files/{uuid}/status', [\App\Http\Controllers\Api\FileAccessController::class, 'status']);
    Route::get('/files/{uuid}/thumbnail', [\App\Http\Controllers\Api\FileAccessController::class, 'thumbnailDirect']);
    Route::delete('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'destroy']);
    Route::put('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'update']);

    // Global Search API
    Route::get('/search', [\App\Http\Controllers\Api\GlobalSearchController::class, 'search']);

    // Category Management API
    Route::get('/categories', [\App\Http\Controllers\Api\CategoryController::class, 'index']);
    Route::put('/categories', [\App\Http\Controllers\Api\CategoryController::class, 'update']);
    Route::post('/categories', [\App\Http\Controllers\Api\CategoryController::class, 'addCategory']);
    Route::delete('/categories/{slug}', [\App\Http\Controllers\Api\CategoryController::class, 'deleteCategory']);

    // Workspace API
    Route::get('/workspace/patients-list', [\App\Http\Controllers\WorkspaceController::class, 'patientList']);
    Route::get('/workspace/{patient:uuid}', [\App\Http\Controllers\WorkspaceController::class, 'patientData']);
    Route::get('/workspace/{patient:uuid}/export', [\App\Http\Controllers\WorkspaceController::class, 'exportPatient']);
    Route::get('/workspace/{patient:uuid}/print', [\App\Http\Controllers\WorkspaceController::class, 'printPatient']);
    Route::post('/workspace/{patient:uuid}/download-files', [\App\Http\Controllers\WorkspaceController::class, 'downloadFiles']);
    Route::get('/workspace/downloads/{jobId}/status', [\App\Http\Controllers\WorkspaceController::class, 'checkDownloadStatus']);
    Route::get('/workspace/{patient:uuid}/download-zip/{jobId}', [\App\Http\Controllers\WorkspaceController::class, 'downloadZip'])->name('api.patients.download_zip');

    // Inline Patient CRUD (JSON responses for Workspace)
    Route::post('/workspace/patients', [\App\Http\Controllers\WorkspaceController::class, 'storePatient']);
    Route::put('/workspace/patients/{patient:uuid}', [\App\Http\Controllers\WorkspaceController::class, 'updatePatient']);
    Route::delete('/workspace/patients/{patient:uuid}', [\App\Http\Controllers\WorkspaceController::class, 'deletePatient']);
    Route::delete('/workspace/patients/{patient:uuid}/force', [\App\Http\Controllers\WorkspaceController::class, 'forceDeletePatient']);
    Route::post('/workspace/patients/{patient:uuid}/restore', [\App\Http\Controllers\WorkspaceController::class, 'restorePatient']);

    // Category Files API (paginated, searchable)
    Route::get('/patients/{patientUuid}/categories/{slug}/files', [\App\Http\Controllers\Api\CategoryFileController::class, 'files']);

    // Visits API
    Route::get('/patients/{patientUuid}/visits', [\App\Http\Controllers\Api\VisitController::class, 'index']);
    Route::post('/patients/{patientUuid}/visits', [\App\Http\Controllers\Api\VisitController::class, 'store']);
    Route::put('/patients/{patientUuid}/visits/{visitId}', [\App\Http\Controllers\Api\VisitController::class, 'update']);
    Route::delete('/patients/{patientUuid}/visits/{visitId}', [\App\Http\Controllers\Api\VisitController::class, 'destroy']);

    // Notes API
    Route::post('/patients/{patientUuid}/notes', [\App\Http\Controllers\Api\NoteController::class, 'store']);
    Route::get('/patients/{patientUuid}/notes', [\App\Http\Controllers\Api\NoteController::class, 'index']);
    Route::put('/patients/{patientUuid}/notes/{uuid}', [\App\Http\Controllers\Api\NoteController::class, 'update']);
    Route::delete('/patients/{patientUuid}/notes/{uuid}', [\App\Http\Controllers\Api\NoteController::class, 'destroy']);

    // Sharing API
    Route::get('/doctors/search', [\App\Http\Controllers\Api\PatientShareController::class, 'searchDoctors']);
    Route::get('/patients/{patientUuid}/shares', [\App\Http\Controllers\Api\PatientShareController::class, 'index']);
    Route::post('/patients/{patientUuid}/shares', [\App\Http\Controllers\Api\PatientShareController::class, 'store']);
    Route::delete('/patients/{patientUuid}/shares/{shareId}', [\App\Http\Controllers\Api\PatientShareController::class, 'destroy']);

    // Admin Doctor API (for workspace)
    Route::get('/admin/doctors/{doctor}', [App\Http\Controllers\Admin\DoctorController::class, 'apiShow']);
});

// ── Phase 7 — Offline File Uploads (OUTSIDE auth middleware) ─────────
// ── BUG-018 FIX: Merged duplicate _native/api/offline groups ────────────
// Previously this prefix was declared twice (uploads + notes separately).
// Now consolidated into ONE group to eliminate route naming conflicts.
// 🚫 CSRF excluded — same reasoning as other _native routes.
Route::prefix('_native/api/offline')->name('offline.')->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->group(function () {
    // ── Offline file uploads ──────────────────────────────────────────
    Route::post('/uploads', [\App\Http\Controllers\Api\OfflineUploadController::class, 'store'])->name('uploads.store');
    Route::get('/uploads', [\App\Http\Controllers\Api\OfflineUploadController::class, 'index'])->name('uploads.index');
    Route::get('/uploads/{uuid}/status', [\App\Http\Controllers\Api\OfflineUploadController::class, 'status'])->name('uploads.status');
    Route::post('/uploads/{uuid}/retry', [\App\Http\Controllers\Api\OfflineUploadController::class, 'retry'])->name('uploads.retry');
    Route::delete('/uploads/{uuid}', [\App\Http\Controllers\Api\OfflineUploadController::class, 'destroy'])->name('uploads.destroy');
    // ── Offline notes ─────────────────────────────────────────────────
    Route::post('/notes', [\App\Http\Controllers\Api\OfflineNoteController::class, 'store'])->name('notes.store');
    Route::get('/notes', [\App\Http\Controllers\Api\OfflineNoteController::class, 'index'])->name('notes.index');
    Route::delete('/notes/{uuid}', [\App\Http\Controllers\Api\OfflineNoteController::class, 'destroy'])->name('notes.destroy');
});

// ═══ SYNC-005 FIX: Removed competing sync endpoints ═══════════════════
// The POST /_native/api/sync/patients and POST /_native/api/sync/files
// routes have been removed. Sync is now handled exclusively by the
// SyncEngineService via POST /_native/api/sync/engine. This eliminates
// race conditions between multiple sync paths.
//
// The pending patients query endpoint is kept for frontend rehydration:

// ── Local Pending Patients Query (OUTSIDE auth middleware) ──────────
// Returns all local patients with sync_status pending (NOT synced).
// The frontend uses this to rehydrate pending patients after app restart
// so they don't disappear when refreshPatientList() runs.
Route::get('/_native/api/patients/pending', function () {
    try {
        $pending = \App\Domains\Patients\Models\Patient::whereIn(
            'sync_status', ['pending_create', 'pending_update']
        )->get()->toArray();
        return response()->json(['data' => $pending, 'count' => count($pending)]);
    } catch (\Throwable $e) {
        return response()->json(['data' => [], 'count' => 0]);
    }
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

// ═══ SYNC-005 FIX: Removed /sync/all endpoint ════════════════════════
// This endpoint was a competing sync path that duplicated logic from
// SyncEngineService. All sync operations now go through the single
// POST /_native/api/sync/engine endpoint.

// ── Phase 7 — Sync Engine (OUTSIDE auth middleware) ─────────────────
// Robust ordered synchronization: patients → files → deletes.
// Used by useSyncEngine composable for connectivity-based auto-sync.
// 🚫 CSRF excluded — same reasoning as other _native routes.
Route::prefix('_native/api/sync')->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->group(function () {
    // Full sync: patients first, then files (only after patient is synced), then deletes
    Route::post('/engine', function (\Illuminate\Http\Request $request) {
        try {
            // ══ FIX: Capture Bearer token from sync request ═══════════════════
            // The frontend now sends the production API token as a Bearer header
            // directly in the sync request. This bypasses the broken session-based
            // token transfer (API routes don't have StartSession middleware, so
            // session() writes from NoteController never persisted across requests).
            //
            // This ensures ApiService has the token REGARDLESS of whether:
            //   - The session has the token (no StartSession on API routes)
            //   - The file-based token storage is working (permissions, path issues)
            //   - The singleton persisted across requests
            //
            // ⚠ CRITICAL: Do NOT replace setToken() — it also writes to file for
            // persistence across app restarts. We call it here to ensure both the
            // in-memory singleton AND the file backup are populated.
            if (config('database.default') === 'sqlite') {
                $bearerToken = $request->bearerToken();
                if ($bearerToken) {
                    try {
                        app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                        \Illuminate\Support\Facades\Log::info('[SyncEngine] Bearer token captured from sync request and stored in ApiService');
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[SyncEngine] Failed to capture Bearer token: ' . $e->getMessage());
                    }
                } else {
                    \Illuminate\Support\Facades\Log::warning('[SyncEngine] No Bearer token in sync request — sync may fail with 401');
                }
            }

            // ── AUTH INSTRUMENTATION: Log token state before sync ────────
            $apiToken = app(\App\Services\Mobile\ApiService::class)->getToken();
            $sessionToken = session('api_token');
            $apiTokenId = $apiToken ? (explode('|', $apiToken, 2)[0] ?? 'unknown') : 'NONE';
            \Illuminate\Support\Facades\Log::info('[SyncEngine] Pre-sync auth state', [
                'api_service_token_present' => $apiToken ? 'YES' : 'NO',
                'api_service_token_prefix' => $apiToken ? substr($apiToken, 0, 20) . '...' : 'NONE',
                'api_service_token_hash' => $apiToken ? md5($apiToken) : 'NONE',
                'api_service_sanctum_id' => $apiTokenId,
                'session_api_token_present' => $sessionToken ? 'YES' : 'NO',
                'session_id' => session()->getId(),
                'auth_user_id' => auth()->id(),
                'auth_check' => auth()->check() ? 'YES' : 'NO',
            ]);

            $engine = app(\App\Services\SyncEngineService::class);
            $results = $engine->syncAll();
            return response()->json([
                'success' => true,
                'message' => 'Sync cycle completed',
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[Sync] engine failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    });

    // Get pending operations summary (patients + files waiting to sync)
    Route::get('/pending-summary', function () {
        try {
            $engine = app(\App\Services\SyncEngineService::class);
            return response()->json($engine->getPendingSummary());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Sync] pending-summary query failed: ' . $e->getMessage());
            return response()->json(['patients' => 0, 'files' => 0, 'deletes' => 0, 'total' => 0]);
        }
    });
});

// ── Phase 6 — Local File Cache (OUTSIDE auth middleware) ────────────
// Same rationale — controller-level auth, offline-friendly.
// 🚫 CSRF excluded — same reasoning.
Route::prefix('_native/cache')->name('cache.')->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->group(function () {
    Route::get('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'streamCached'])->name('files.stream');
    Route::get('/files/{uuid}/thumbnail', [\App\Http\Controllers\Api\FileAccessController::class, 'thumbnailDirect'])->name('files.thumbnail'); // FIX: was missing
    Route::get('/files/{uuid}/status', [\App\Http\Controllers\Api\FileAccessController::class, 'cacheStatus'])->name('files.status');
    Route::post('/files/{uuid}/cache', [\App\Http\Controllers\Api\FileAccessController::class, 'cacheFile'])->name('files.cache');
    Route::delete('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'removeCached'])->name('files.remove');
    Route::delete('/patient/{patientUuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'removePatientCached'])->name('patient.remove');
});


// ── Phase 8 — Offline Notes routes merged above into _native/api/offline ──
// (BUG-018 FIX: Was a duplicate prefix group here, now consolidated above)

// ── Phase 8 — Category Cache Refresh (OUTSIDE auth middleware) ────
// The frontend calls this endpoint to refresh the local category cache
// from the production server. It uses the sync engine's API token to
// authenticate against the production API.
// 🚫 CSRF excluded — same reasoning as other _native routes.
Route::post('/_native/api/categories/refresh', function () {
    try {
        $userId = auth()->id();
        $repo = app(\App\Contracts\Repositories\CategoryRepositoryInterface::class);
        $categories = $repo->refresh($userId);
        \Illuminate\Support\Facades\Log::info('[CategoryCache] Refreshed ' . count($categories) . ' categories for user ' . ($userId ?? 'null'));
        return response()->json([
            'success' => true,
            'categories' => $categories,
            'count' => count($categories),
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('[CategoryCache] Refresh failed: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);


