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
        // Respect an explicit logout: don't silently re-authenticate a device
        // whose local user row happens to still exist but that chose to sign out.
        if (!\App\Http\Controllers\AuthController::deviceIsAuthenticated()) {
            return response()->json(['error' => 'Logged out'], 401);
        }

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
    if (config('database.default') === 'sqlite') {
        $user = \App\Domains\Users\Models\User::first();
        if ($user) {
            auth()->login($user);
        }
        return redirect()->route('workspace');
    }
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

// ── Mobile API Aliases (EMBEDDED APP ONLY) ────────────────────────────────
// The frontend (useWorkspace, useUploads, AddRecordModal, DoctorWorkspace)
// calls /api/v1/mobile/* endpoints on the embedded Laravel. On the PRODUCTION
// server these live in api.php (auth:sanctum protected). On the embedded
// NativePHP app (SQLite) they must be registered here so the exact same
// frontend code works offline — otherwise online flows 404.
//
// 🔐 SECURITY: These routes are registered ONLY when running on SQLite
// (embedded single-user device). On the production MySQL server this block
// is skipped entirely, so no unauthenticated production endpoint is exposed.
// The controllers already handle null users gracefully (offline-first design).
if (config('database.default') === 'sqlite') {
    Route::prefix('api/v1/mobile')->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ])->group(function () {
        // Patients
        Route::post('/patients', [\App\Http\Controllers\Api\Mobile\PatientController::class, 'store']);
        Route::put('/patients/{uuid}', [\App\Http\Controllers\Api\Mobile\PatientController::class, 'update']);
        Route::delete('/patients/{uuid}', [\App\Http\Controllers\Api\Mobile\PatientController::class, 'destroy']);
        Route::get('/patients/{uuid}', [\App\Http\Controllers\Api\Mobile\PatientController::class, 'show']);
        Route::get('/patients', [\App\Http\Controllers\Api\Mobile\PatientController::class, 'index']);

        // Files (direct upload path used by useUploads.uploadDirectly for images)
        Route::post('/patients/{uuid}/files', [\App\Http\Controllers\Api\Mobile\FileController::class, 'store']);
        Route::get('/patients/{uuid}/files', [\App\Http\Controllers\Api\Mobile\FileController::class, 'index']);
        Route::put('/files/{fileUuid}', [\App\Http\Controllers\Api\Mobile\FileController::class, 'update']);
        Route::delete('/files/{fileUuid}', [\App\Http\Controllers\Api\Mobile\FileController::class, 'destroy']);
        Route::get('/files/{fileUuid}', [\App\Http\Controllers\Api\Mobile\FileController::class, 'show']);
        Route::get('/files/{fileUuid}/stream', [\App\Http\Controllers\Api\Mobile\FileController::class, 'stream']);
        Route::get('/files/{fileUuid}/thumbnail', [\App\Http\Controllers\Api\Mobile\FileController::class, 'thumbnail']);

        // Notes
        Route::post('/patients/{uuid}/notes', [\App\Http\Controllers\Api\Mobile\NoteController::class, 'store']);
        Route::get('/patients/{uuid}/notes', [\App\Http\Controllers\Api\Mobile\NoteController::class, 'index']);
        Route::put('/patients/{uuid}/notes/{noteUuid}', [\App\Http\Controllers\Api\Mobile\NoteController::class, 'update']);
        Route::delete('/patients/{uuid}/notes/{noteUuid}', [\App\Http\Controllers\Api\Mobile\NoteController::class, 'destroy']);

        // Visits
        Route::post('/patients/{uuid}/visits', [\App\Http\Controllers\Api\Mobile\VisitController::class, 'store']);
        Route::get('/patients/{uuid}/visits', [\App\Http\Controllers\Api\Mobile\VisitController::class, 'index']);
        Route::put('/patients/{uuid}/visits/{visitId}', [\App\Http\Controllers\Api\Mobile\VisitController::class, 'update']);
        Route::delete('/patients/{uuid}/visits/{visitId}', [\App\Http\Controllers\Api\Mobile\VisitController::class, 'destroy']);

        // Shares
        Route::get('/patients/{uuid}/shares', [\App\Http\Controllers\Api\Mobile\ShareController::class, 'index']);
        Route::delete('/patients/{uuid}/shares/{shareId}', [\App\Http\Controllers\Api\Mobile\ShareController::class, 'destroy']);

        // Bootstrap cache
        Route::get('/bootstrap', [\App\Http\Controllers\Api\Mobile\BootstrapController::class, 'data']);
        Route::post('/bootstrap/refresh', [\App\Http\Controllers\Api\Mobile\BootstrapController::class, 'refreshCache']);

        // Chunked upload compatibility (older embedded builds used these URLs)
        Route::post('/chunk/init', [\App\Http\Controllers\Api\ChunkUploadController::class, 'init']);
        Route::post('/chunk/chunk', [\App\Http\Controllers\Api\ChunkUploadController::class, 'chunk']);
        Route::post('/chunk/complete', [\App\Http\Controllers\Api\ChunkUploadController::class, 'complete']);
        Route::post('/chunk/{uuid}/cancel', [\App\Http\Controllers\Api\ChunkUploadController::class, 'cancel']);
        Route::get('/chunk/{uuid}/status', [\App\Http\Controllers\Api\ChunkUploadController::class, 'status']);

        // Category files (paginated)
        Route::get('/patients/{uuid}/categories/{slug}/files', [\App\Http\Controllers\Api\CategoryFileController::class, 'files']);
    });
}

// ── Phase 7 — Offline File Uploads (OUTSIDE auth middleware) ─────────
// ── BUG-018 FIX: Merged duplicate _native/api/offline groups ────────────
// Previously this prefix was declared twice (uploads + notes separately).
// Now consolidated into ONE group to eliminate route naming conflicts.
// 🚫 CSRF excluded — same reasoning as other _native routes.
Route::prefix('_native/api/offline')->name('offline.')->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->group(function () {
    // File upload endpoints (consolidated into Mobile FileController)
    Route::post('/uploads', [\App\Http\Controllers\Api\Mobile\FileController::class, 'store'])->name('uploads.store');
    Route::delete('/uploads/{fileUuid}', [\App\Http\Controllers\Api\Mobile\FileController::class, 'destroy'])->name('uploads.destroy');

    // ── GET: List pending offline uploads (BUG-FIX) ─────────────────────
    // The frontend (CategoryBlock.loadCategoryData + useWorkspace.selectPatient
    // rehydration) calls GET /_native/api/offline/uploads?patient_uuid=… to
    // merge locally-pending files into the category view. These routes were
    // previously POST/DELETE only, so the GET returned 405 Method Not Allowed
    // (spamming the logs) and pending files never appeared for new patients.
    Route::get('/uploads', [\App\Http\Controllers\Api\Mobile\FileController::class, 'pendingIndex'])->name('uploads.index');

    // Note creation endpoints (consolidated into Mobile NoteController)
    Route::post('/notes', [\App\Http\Controllers\Api\Mobile\NoteController::class, 'store'])->name('notes.store');
    Route::delete('/notes/{noteUuid}', [\App\Http\Controllers\Api\Mobile\NoteController::class, 'destroy'])->name('notes.destroy');

    // ── GET: List pending local notes (BUG-FIX) ─────────────────────────
    // Same rationale as uploads.index — CategoryBlock fetches pending local
    // notes via GET so offline-created notes show immediately.
    Route::get('/notes', [\App\Http\Controllers\Api\Mobile\NoteController::class, 'pendingIndex'])->name('notes.index');
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

// ── Manual Sync Engine (OUTSIDE auth middleware) ──────────────────────
// Consolidated 11-step offline-first manual sync service.
// Triggered strictly when user presses "Sync Now".
// 🚫 CSRF excluded — same reasoning as other _native routes.
Route::prefix('_native/api/sync')->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->group(function () {
    // Sync Dashboard Stats Endpoint
    Route::get('/dashboard', function () {
        $manualSync = app(\App\Services\ManualSyncService::class);
        return response()->json([
            'success' => true,
            'stats'   => $manualSync->getSyncDashboardStats(),
        ]);
    });

    // Control Endpoints: Pause, Resume, Cancel
    Route::post('/pause', function () {
        app(\App\Services\ManualSyncService::class)->pause();
        return response()->json(['success' => true, 'message' => 'Sync paused']);
    });

    Route::post('/resume', function () {
        app(\App\Services\ManualSyncService::class)->resume();
        return response()->json(['success' => true, 'message' => 'Sync resumed']);
    });

    Route::post('/cancel', function () {
        app(\App\Services\ManualSyncService::class)->cancel();
        return response()->json(['success' => true, 'message' => 'Sync cancelled']);
    });

    // Manual sync pipeline endpoint
    Route::post('/manual', function (\Illuminate\Http\Request $request) {
        try {
            if (config('database.default') === 'sqlite') {
                $bearerToken = $request->bearerToken();
                if ($bearerToken) {
                    try {
                        app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[ManualSync] Failed to capture Bearer token: ' . $e->getMessage());
                    }
                }
            }

            $syncEngine = app(\App\Services\SyncEngineService::class);
            $results = $syncEngine->syncAll();
            return response()->json([
                'success' => true,
                'message' => 'Sync completed successfully',
                'stats'   => $results,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[ManualSync] Failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    });

    // Compatibility endpoint alias pointing to SyncEngineService
    Route::post('/engine', function (\Illuminate\Http\Request $request) {
        if (config('database.default') === 'sqlite') {
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                try {
                    app(\App\Services\Mobile\ApiService::class)->setToken($bearerToken);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[SyncEngine] Failed to capture Bearer token: ' . $e->getMessage());
                }
            }
        }

        $syncEngine = app(\App\Services\SyncEngineService::class);
        $results = $syncEngine->syncAll();
        return response()->json([
            'success' => true,
            'message' => 'Manual sync completed',
            'results' => $results,
        ]);
    });

    // Get full realtime sync dashboard stats
    Route::get('/dashboard', function () {
        try {
            $patientPending = \App\Domains\Patients\Models\Patient::whereIn('sync_status', ['pending_create', 'pending_update'])->count();
            $patientDeletes = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_delete')->count();
            $notesPending   = \App\Domains\Patients\Models\PatientNote::whereIn('sync_status', ['pending_create', 'pending_delete'])->count();
            $visitsPending  = \App\Domains\Patients\Models\PatientVisit::whereIn('sync_status', ['pending_create', 'pending_update', 'pending_delete'])->count();
            $filesPending   = \Illuminate\Support\Facades\DB::table('offline_files')->whereIn('sync_status', ['pending_upload', 'failed'])->count() 
                            + \App\Domains\Media\Models\PatientFile::whereNull('remote_uuid')->where('upload_status', 'ready')->count();
            $totalQueue     = \App\Domains\Sync\Models\SyncQueue::where('status', 'pending')->count();
            $totalPending   = $patientPending + $patientDeletes + $notesPending + $visitsPending + $filesPending;

            $sqliteDbFile   = database_path('database.sqlite');
            $sqliteSizeMb   = file_exists($sqliteDbFile) ? round(filesize($sqliteDbFile) / 1048576, 2) : 0;

            return response()->json([
                'success' => true,
                'stats' => [
                    'engine_state' => 'idle',
                    'auth_status'  => auth()->check() ? 'Authenticated' : 'Offline Session',
                    'pending'      => $totalPending,
                    'total_queue'  => $totalQueue,
                    'pending_patients' => $patientPending,
                    'pending_notes'    => $notesPending,
                    'pending_visits'   => $visitsPending,
                    'pending_files'    => $filesPending,
                    'pending_deletes'  => $patientDeletes,
                    'synced'           => \App\Domains\Sync\Models\SyncQueue::where('status', 'completed')->count(),
                    'failed'           => \App\Domains\Sync\Models\SyncQueue::where('status', 'failed')->count(),
                    'sqlite_size_mb'   => $sqliteSizeMb,
                    'last_successful_sync' => cache()->get('last_successful_sync_at', null),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    // Get pending operations summary
    Route::get('/pending-summary', function () {
        try {
            $patientCreate = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_create')->count();
            $patientUpdate = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_update')->count();
            $patientDelete = \App\Domains\Patients\Models\Patient::where('sync_status', 'pending_delete')->count();
            $filesPending  = \Illuminate\Support\Facades\DB::table('offline_files')->whereIn('sync_status', ['pending_upload', 'failed'])->count();
            $notesPending  = \App\Domains\Patients\Models\PatientNote::whereIn('sync_status', ['pending_create', 'pending_delete'])->count();
            $patientFilesPending = \App\Domains\Media\Models\PatientFile::whereNull('remote_uuid')->where('upload_status', 'ready')->count();

            $total = $patientCreate + $patientUpdate + $patientDelete + $filesPending + $patientFilesPending + $notesPending;

            return response()->json([
                'patients' => $patientCreate + $patientUpdate,
                'deletes'  => $patientDelete,
                'files'    => $filesPending + $patientFilesPending,
                'notes'    => $notesPending,
                'total'    => $total,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['patients' => 0, 'files' => 0, 'deletes' => 0, 'notes' => 0, 'total' => 0]);
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
    Route::get('/files/{uuid}/base64', [\App\Http\Controllers\Api\FileAccessController::class, 'streamCachedBase64'])->name('files.stream_base64');
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

// ── Phase 8 — Local Bootstrap Cache Refresh (OUTSIDE auth middleware) ──
Route::post('/_native/api/bootstrap/refresh', [\App\Http\Controllers\Api\Mobile\BootstrapController::class, 'refreshCache'])
    ->name('bootstrap.refresh')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);



