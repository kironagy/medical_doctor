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
use App\Http\Controllers\Api\Mobile\BootstrapController;
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
    // On SQLite (embedded Laravel / offline), auth:sanctum is skipped.
    // On MySQL (production server), auth:sanctum is enforced.
    // Uses inline config() check instead of $isEmbeddedLaravel variable because
    // that variable is defined later in this file (in the mobile routes section).
    Route::get('/patients/{patientUuid}/categories/{slug}/files', [CategoryFileController::class, 'files'])
        ->middleware(config('database.default') === 'sqlite' ? [] : ['auth:sanctum']);

    // ── Mobile API ────────────────────────────────────────────────────
    // On the PRODUCTION server, these routes require auth:sanctum (Bearer token).
    // On the Embedded Laravel (NativePHP), NO authentication is applied — the
    // local application is a single-user device that doesn't need token auth.
    // ApiService manages the production API token for SyncEngine requests.
    //
    // 🔐 ROBUST AUTH CHECK: We detect embedded Laravel by checking the
    // database driver. SQLite = embedded (no auth), MySQL = production (auth).
    // This is more reliable than env('NATIVEPHP_APP_ID') which can be
    // accidentally set on the production server (breaking all mobile auth).
    // When config is cached, config() is used — env() would be null.
    $isEmbeddedLaravel = config('database.default') === 'sqlite';
    $mobileMiddleware = [];
    if (!$isEmbeddedLaravel) {
        $mobileMiddleware[] = 'auth:sanctum';
    }

    Route::prefix('mobile')->middleware($mobileMiddleware)->group(function () {
        // Dashboard
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // Bootstrap — cache all master data after login (for offline operation)
        // Called immediately after first successful login to prime the local cache.
        // Returns categories, visit types, user profile for offline form population.
        Route::get('/bootstrap', [BootstrapController::class, 'data']);
        Route::post('/bootstrap/refresh', [BootstrapController::class, 'refreshCache']);

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
    });

    // ── Chunked Upload Endpoints ──────────────────────────────────────────
    // Single source of truth for /api/v1/chunk/*
    // Production (MySQL): auth:sanctum + throttle:300,1
    // Embedded (SQLite): unauthenticated for single-user device
    // Throttle raised from 60,1: a single 50 MB manual sync at 2 MB chunks is
    // already 27 requests, and one sync run pushes many files back-to-back.
    $chunkMiddleware = $isEmbeddedLaravel ? [] : ['auth:sanctum', 'throttle:300,1'];
    Route::prefix('chunk')->middleware($chunkMiddleware)->group(function () {
        Route::post('/init', [\App\Http\Controllers\Api\ChunkUploadController::class, 'init']);
        Route::post('/chunk', [\App\Http\Controllers\Api\ChunkUploadController::class, 'chunk']);
        Route::post('/complete', [\App\Http\Controllers\Api\ChunkUploadController::class, 'complete']);
        Route::post('/{uuid}/cancel', [\App\Http\Controllers\Api\ChunkUploadController::class, 'cancel']);
        Route::get('/{uuid}/status', [\App\Http\Controllers\Api\ChunkUploadController::class, 'status']);
    });
});

