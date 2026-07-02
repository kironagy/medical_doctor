<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Mobile\AuthController;
use App\Http\Controllers\Api\Mobile\SyncController;
use App\Http\Controllers\Api\Mobile\MediaSyncController;
use App\Http\Controllers\Api\Mobile\ChunkSyncController;

Route::prefix('mobile/v1')->group(function () {

    // Auth (no token required)
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);

    // Protected sync endpoints
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/device/register', [AuthController::class, 'registerDevice']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Delta sync — get only what changed since last sync
        Route::post('sync/pull', [SyncController::class, 'pull']);
        Route::post('sync/push', [SyncController::class, 'push']);
        Route::get('sync/status', [SyncController::class, 'status']);

        // Specific entity sync
        Route::get('sync/patients', [SyncController::class, 'patients']);
        Route::get('sync/patients/{patient}', [SyncController::class, 'patient']);
        Route::get('sync/patients/{patient}/files', [SyncController::class, 'patientFiles']);
        Route::get('sync/patients/{patient}/visits', [SyncController::class, 'patientVisits']);
        Route::get('sync/patients/{patient}/notes', [SyncController::class, 'patientNotes']);
        Route::get('sync/categories', [SyncController::class, 'categories']);

        // Media sync — chunked download for offline viewing
        Route::get('media/{file}/metadata', [MediaSyncController::class, 'metadata']);
        Route::get('media/{file}/download', [MediaSyncController::class, 'download']);
        Route::get('media/{file}/thumbnail', [MediaSyncController::class, 'thumbnail']);

        // Chunked upload from mobile
        Route::post('chunk/init', [ChunkSyncController::class, 'init']);
        Route::post('chunk/{session}/upload', [ChunkSyncController::class, 'upload']);
        Route::post('chunk/{session}/complete', [ChunkSyncController::class, 'complete']);
        Route::get('chunk/{session}/status', [ChunkSyncController::class, 'status']);
    });
});
