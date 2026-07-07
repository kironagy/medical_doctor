<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChunkUploadController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\FileAccessController;
use App\Http\Controllers\Api\Mobile\FileController as MobileFileController;
use App\Http\Controllers\Api\CategoryFileController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\PatientController as WebPatientController;
use App\Http\Controllers\Api\V1\PatientController as ApiPatientController;
use App\Http\Controllers\Api\V1\PatientVisitController;
use App\Http\Controllers\Api\V1\FileCategoryController;
use App\Http\Controllers\Api\V1\DoctorController;
use App\Http\Controllers\Api\V1\SyncController;


// Auth endpoint (public)
Route::post('/v1/login', [AuthController::class, 'login'])->name('api.v1.auth.login');

// Version 1 API
Route::prefix('v1')->name('api.v1.')->group(function () {
    // Protected by auth
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Patients
        Route::apiResource('patients', ApiPatientController::class);

        // Patient Files
        // Category files
        Route::get('/patients/{patientUuid}/categories/{slug}/files', [CategoryFileController::class, 'files']);

        // Direct upload (non-chunked)
        Route::post('/patients/{patientUuid}/files', [UploadController::class, 'store']);

        // Chunked upload endpoints
        Route::post('/chunk/init', [ChunkUploadController::class, 'init']);
        Route::post('/chunk/chunk', [ChunkUploadController::class, 'chunk']);
        Route::post('/chunk/complete', [ChunkUploadController::class, 'complete']);
        Route::post('/chunk/{uuid}/cancel', [ChunkUploadController::class, 'cancel']);
        Route::get('/chunk/{uuid}/status', [ChunkUploadController::class, 'status']);

        // File access
        Route::get('/files/{uuid}', [FileAccessController::class, 'streamDirect'])->name('files.stream');
        Route::get('/files/{uuid}/signed-url', [FileAccessController::class, 'generateSignedUrl']);
        Route::get('/files/{uuid}/thumbnail', [FileAccessController::class, 'thumbnailDirect']);
        Route::delete('/files/{uuid}', [FileAccessController::class, 'destroy']);
        Route::put('/files/{uuid}', [FileAccessController::class, 'update']);

        // Visits
        Route::apiResource('patients.visits', PatientVisitController::class)
            ->parameters(['visits' => 'visit']);

        // Categories
        Route::apiResource('categories', FileCategoryController::class);

        // Doctor management (admin)
        Route::middleware('can:admin')->group(function () {
            Route::apiResource('doctors', DoctorController::class)
                ->parameters(['doctors' => 'doctor']);
        });

        // Sync endpoints
        Route::prefix('sync')->name('sync.')->group(function () {
            Route::get('/seed', [SyncController::class, 'seed'])->name('seed');
            Route::get('/changes', [SyncController::class, 'changes'])->name('changes');
            Route::post('/push', [SyncController::class, 'push'])->name('push');
            Route::post('/now', [SyncController::class, 'triggerNow'])->name('now');
            Route::get('/status/{uuid}', [SyncController::class, 'status'])->name('status');
            Route::get('/logs', function () {
                if (config('database.default') !== 'sqlite') {
                    return response()->json([]);
                }
                $logs = \App\Models\SyncQueueItem::orderByDesc('id')->limit(50)->get();
                return response()->json($logs);
            })->name('logs');
        });

        // Additional endpoints from v4 that might be needed
        Route::get('/patients/{id}/overview', [\App\Http\Controllers\PatientOverviewController::class, 'overview']);
        Route::get('/patients/{id}/visits/paginated', [\App\Http\Controllers\PatientOverviewController::class, 'visitsPaginated']);
        Route::get('/patients/{id}/files/paginated', [\App\Http\Controllers\PatientOverviewController::class, 'filesPaginated']);
        Route::get('/patients/{id}/files/by-category', [\App\Http\Controllers\PatientOverviewController::class, 'filesByCategory']);
        // Route::post('/notes', [\App\Http\Controllers\PatientController::class, 'storeNote']);
    });
});

// Web convenience routes (non-versioned) - keep existing
Route::get('/patients', [WebPatientController::class, 'index']);
Route::post('/patients', [WebPatientController::class, 'store']);
Route::get('/patients/{id}', [WebPatientController::class, 'show']);
Route::put('/patients/{id}', [WebPatientController::class, 'update']);
Route::delete('/patients/{id}', [WebPatientController::class, 'destroy']);

Route::get('/patients/{id}/files', [\App\Http\Controllers\PatientFileController::class, 'index']);
Route::post('/patients/{id}/files', function () {}); // placeholder if needed
Route::delete('/patients/{id}/files/{fileId}', [\App\Http\Controllers\PatientFileController::class, 'destroy']);

Route::get('/patients/{id}/visits', [PatientVisitController::class, 'index']);
Route::post('/patients/{id}/visits', [PatientVisitController::class, 'store']);
Route::get('/patients/{id}/visits/{visitId}', [PatientVisitController::class, 'show']);
Route::put('/patients/{id}/visits/{visitId}', [PatientVisitController::class, 'update']);
Route::delete('/patients/{id}/visits/{visitId}', [PatientVisitController::class, 'destroy']);
