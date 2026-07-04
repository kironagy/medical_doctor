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

        // Category files endpoint
        Route::get('/patients/{patientUuid}/categories/{slug}/files', [CategoryFileController::class, 'files']);

        // Mobile API endpoints
        Route::prefix('mobile')->group(function () {
            // Dashboard
            Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

            // Patients
            Route::get('/patients', [PatientController::class, 'index']);
            Route::post('/patients', [PatientController::class, 'store']);
            Route::get('/patients/{uuid}', [PatientController::class, 'show']);
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

            // Chunk Uploads
            Route::post('/chunk/init', [\App\Http\Controllers\Api\ChunkUploadController::class, 'init']);
            Route::post('/chunk/chunk', [\App\Http\Controllers\Api\ChunkUploadController::class, 'chunk']);
            Route::post('/chunk/complete', [\App\Http\Controllers\Api\ChunkUploadController::class, 'complete']);
            Route::post('/chunk/{uuid}/cancel', [\App\Http\Controllers\Api\ChunkUploadController::class, 'cancel']);
            Route::get('/chunk/{uuid}/status', [\App\Http\Controllers\Api\ChunkUploadController::class, 'status']);
        });
    });
});

Route::post('/native/sync', function () {
    return response()->json(['error' => 'Not available'], 403);
});
