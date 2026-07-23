<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\DoctorController;

// ── Session Restore (must be outside auth middleware) ─────────────────
// Validates a Sanctum Bearer token and creates a new web session.
// Used by the frontend on app restart when the WebView lost its cookies.
Route::post('/api/session/restore', function (\Illuminate\Http\Request $request) {
    $token = $request->bearerToken();
    if (!$token) {
        return response()->json(['error' => 'No token provided'], 401);
    }

    try {
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (!$accessToken || !$accessToken->tokenable) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        $user = $accessToken->tokenable;
        if (!$user) {
            return response()->json(['error' => 'User not found'], 401);
        }

        // Log the user in via web session
        \Illuminate\Support\Facades\Auth::login($user);
        $request->session()->regenerate();

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
// Same rationale as api/v1 routes above — controller-level auth.
// 🚫 CSRF excluded — same reasoning.
Route::prefix('_native/api/offline')->name('offline.')->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->group(function () {
    Route::post('/uploads', [\App\Http\Controllers\Api\OfflineUploadController::class, 'store'])->name('uploads.store');
    Route::get('/uploads', [\App\Http\Controllers\Api\OfflineUploadController::class, 'index'])->name('uploads.index');
    Route::get('/uploads/{uuid}/status', [\App\Http\Controllers\Api\OfflineUploadController::class, 'status'])->name('uploads.status');
    Route::post('/uploads/{uuid}/retry', [\App\Http\Controllers\Api\OfflineUploadController::class, 'retry'])->name('uploads.retry');
    Route::delete('/uploads/{uuid}', [\App\Http\Controllers\Api\OfflineUploadController::class, 'destroy'])->name('uploads.destroy');
});

// ── Phase 6 — Local File Cache (OUTSIDE auth middleware) ────────────
// Same rationale — controller-level auth, offline-friendly.
// 🚫 CSRF excluded — same reasoning.
Route::prefix('_native/cache')->name('cache.')->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
])->group(function () {
    Route::get('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'streamCached'])->name('files.stream');
    Route::get('/files/{uuid}/status', [\App\Http\Controllers\Api\FileAccessController::class, 'cacheStatus'])->name('files.status');
    Route::post('/files/{uuid}/cache', [\App\Http\Controllers\Api\FileAccessController::class, 'cacheFile'])->name('files.cache');
    Route::delete('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'removeCached'])->name('files.remove');
    Route::delete('/patient/{patientUuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'removePatientCached'])->name('patient.remove');
});
