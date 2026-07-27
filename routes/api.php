<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\FileAccessController;
use App\Http\Controllers\Api\Mobile\PatientController;
use App\Http\Controllers\Api\Mobile\VisitController;
use App\Http\Controllers\Api\Mobile\NoteController;
use App\Http\Controllers\Api\Mobile\FileController;
use App\Http\Controllers\Api\Mobile\DashboardController;
use App\Http\Controllers\Api\Mobile\DoctorController;
use App\Http\Controllers\Api\Mobile\ShareController;
use App\Http\Controllers\Api\Mobile\SearchController;
use App\Http\Controllers\Api\CategoryFileController;

Route::get('/files/{uuid}/stream', [FileAccessController::class, 'streamDirect'])
    ->middleware('signed')
    ->name('files.stream');

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // Category files endpoint — conditional auth (same pattern as mobile routes below)
    // On SQLite (embedded Laravel / offline), auth is skipped entirely.
    // On MySQL (production server), accept BOTH:
    //   - Sanctum Bearer token (used by mobile sync engine / ApiService)
    //   - Web session auth  (used by the website when it calls /mobile/ routes)
    // Uses inline config() check — more reliable than env('NATIVEPHP_APP_ID').
    Route::get('/patients/{patientUuid}/categories/{slug}/files', [CategoryFileController::class, 'files'])
        ->middleware(config('database.default') === 'sqlite' ? [] : ['auth:sanctum,web']);

    // ── Mobile API ────────────────────────────────────────────────────
    // On the PRODUCTION server, these routes require authentication via
    // EITHER a Sanctum Bearer token (mobile sync engine) OR a web session
    // cookie (website). The middleware string 'auth:sanctum,web' tries
    // Sanctum first, then falls back to the web session guard.
    //
    // On the Embedded Laravel (NativePHP / SQLite), NO authentication is
    // applied — the local application is a single-user device that doesn't
    // need token or session auth. ApiService manages the production API
    // token for SyncEngine requests to the production server.
    //
    // 🔐 ROBUST DETECTION: We detect embedded Laravel by checking the
    // database driver. SQLite = embedded (no auth), MySQL = production (auth).
    // This is more reliable than env('NATIVEPHP_APP_ID') which can be
    // accidentally set on the production server (breaking all mobile auth).
    $isEmbeddedLaravel = config('database.default') === 'sqlite';
    $mobileMiddleware = [];
    if (!$isEmbeddedLaravel) {
        // On production: accept Sanctum token OR web session
        $mobileMiddleware[] = 'auth:sanctum,web';
    }

    Route::prefix('mobile')->middleware($mobileMiddleware)->group(function () {
        // Dashboard
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // Patients
        Route::get('/patients', [PatientController::class, 'index']);
        Route::get('/patients/{uuid}', [PatientController::class, 'show']);
        Route::post('/patients', [PatientController::class, 'store']);
        Route::put('/patients/{uuid}', [PatientController::class, 'update']);
        Route::delete('/patients/{uuid}', [PatientController::class, 'destroy']);

        // Visits
        Route::get('/patients/{uuid}/visits', [VisitController::class, 'index']);
        Route::post('/patients/{uuid}/visits', [VisitController::class, 'store']);
        Route::put('/patients/{uuid}/visits/{visitId}', [VisitController::class, 'update']);
        Route::delete('/patients/{uuid}/visits/{visitId}', [VisitController::class, 'destroy']);

        // Notes
        Route::get('/patients/{uuid}/notes', [NoteController::class, 'index']);
        Route::post('/patients/{uuid}/notes', [NoteController::class, 'store']);
        Route::put('/patients/{uuid}/notes/{noteUuid}', [NoteController::class, 'update']);
        Route::delete('/patients/{uuid}/notes/{noteUuid}', [NoteController::class, 'destroy']);

        // Files
        Route::get('/patients/{uuid}/files', [FileController::class, 'index']);
        Route::post('/patients/{uuid}/files', [FileController::class, 'store']);
        Route::put('/files/{fileUuid}', [FileController::class, 'update']);
        Route::delete('/files/{fileUuid}', [FileController::class, 'destroy']);
        Route::get('/files/{fileUuid}', [FileController::class, 'show']);
        Route::get('/files/{fileUuid}/stream', [FileController::class, 'stream'])->name('mobile.files.stream');
        Route::get('/files/{fileUuid}/thumbnail', [FileController::class, 'thumbnail'])->name('mobile.files.thumbnail');

        // Doctors
        Route::get('/doctors', [DoctorController::class, 'index']);
        Route::get('/doctors/search', [DoctorController::class, 'search']);
        Route::get('/doctors/{doctorId}', [DoctorController::class, 'show']);

        // Sharing
        Route::get('/patients/{uuid}/shares', [ShareController::class, 'index']);
        Route::post('/patients/{uuid}/shares', [ShareController::class, 'store']);
        Route::delete('/patients/{uuid}/shares/{shareId}', [ShareController::class, 'destroy']);

        // Search
        Route::get('/search', [SearchController::class, 'search']);

        // Profile
        Route::put('/profile', [DoctorController::class, 'updateProfile']);
        Route::put('/profile/password', [DoctorController::class, 'updatePassword']);

        // Resumable Uploads
        Route::post('/uploads/start', [\App\Http\Controllers\Api\UploadsController::class, 'start']);
        Route::post('/uploads/chunk', [\App\Http\Controllers\Api\UploadsController::class, 'chunk']);
        Route::get('/uploads/{id}/status', [\App\Http\Controllers\Api\UploadsController::class, 'status']);
        Route::post('/uploads/{id}/resume', [\App\Http\Controllers\Api\UploadsController::class, 'resume']);
        Route::post('/uploads/{id}/finish', [\App\Http\Controllers\Api\UploadsController::class, 'finish']);
        Route::delete('/uploads/{id}', [\App\Http\Controllers\Api\UploadsController::class, 'destroy']);
    });
});

