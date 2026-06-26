<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientFileController;
use App\Http\Controllers\PatientVisitController;
use App\Http\Controllers\PatientOverviewController;
use App\Http\Controllers\Api\V1\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\DashboardController as V1DashboardController;
use App\Http\Controllers\Api\V1\DoctorController as V1DoctorController;
use App\Http\Controllers\Api\V1\FileCategoryController as V1FileCategoryController;
use App\Http\Controllers\Api\V1\PatientController as V1PatientController;
use App\Http\Controllers\Api\V1\PatientFileController as V1PatientFileController;
use App\Http\Controllers\Api\V1\PatientVisitController as V1PatientVisitController;
use App\Http\Controllers\Api\V1\SyncController as V1SyncController;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/auth/login', [V1AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('/auth/me', [V1AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [V1AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/dashboard', V1DashboardController::class)->name('dashboard');

        Route::get('/patients/{id}/overview', [PatientOverviewController::class, 'overview']);
        Route::get('/patients/{id}/visits/paginated', [PatientOverviewController::class, 'visitsPaginated']);
        Route::get('/patients/{id}/files/paginated', [PatientOverviewController::class, 'filesPaginated']);
        Route::get('/patients/{id}/files/by-category', [PatientOverviewController::class, 'filesByCategory']);

        Route::apiResource('patients', V1PatientController::class);
        Route::apiResource('patients.files', V1PatientFileController::class)
            ->parameters(['files' => 'file'])
            ->except(['update']);
        Route::apiResource('patients.visits', V1PatientVisitController::class)
            ->parameters(['visits' => 'visit']);

        Route::apiResource('categories', V1FileCategoryController::class);

        Route::middleware('can:admin')->group(function () {
            Route::apiResource('doctors', V1DoctorController::class)
                ->parameters(['doctors' => 'doctor']);
        });

        Route::prefix('sync')->name('sync.')->group(function () {
            Route::get('/seed', [V1SyncController::class, 'seed'])->name('seed');
            Route::get('/changes', [V1SyncController::class, 'changes'])->name('changes');
            Route::post('/push', [V1SyncController::class, 'push'])->name('push');
        });
    });
});

// ── Auth API ────────────────────────────────────────────────────
Route::post('/login', [\App\Http\Controllers\AuthController::class, 'apiLogin']);

// ── Optimized Overview & Paginated APIs ───────────────────────
// IMPORTANT: These MUST be defined BEFORE resource routes with similar patterns
// to avoid being captured by {visitId} or {fileId} wildcards.
Route::get('/patients/{id}/overview', [PatientOverviewController::class, 'overview']);
Route::get('/patients/{id}/visits/paginated', [PatientOverviewController::class, 'visitsPaginated']);
Route::get('/patients/{id}/files/paginated', [PatientOverviewController::class, 'filesPaginated']);
Route::get('/patients/{id}/files/by-category', [PatientOverviewController::class, 'filesByCategory']);

// ── Patients ──────────────────────────────────────────────────
Route::get('/patients', [PatientController::class, 'index']);
Route::post('/patients', [PatientController::class, 'store']);
Route::get('/patients/{id}', [PatientController::class, 'show']);
Route::put('/patients/{id}', [PatientController::class, 'update']);
Route::delete('/patients/{id}', [PatientController::class, 'destroy']);

// ── Patient Files ─────────────────────────────────────────────
Route::get('/patients/{id}/files', [PatientFileController::class, 'index']);
Route::post('/patients/{id}/files', [PatientFileController::class, 'store']);
Route::delete('/patients/{id}/files/{fileId}', [PatientFileController::class, 'destroy']);

// ── Patient Visits ────────────────────────────────────────────
Route::get('/patients/{id}/visits', [PatientVisitController::class, 'index']);
Route::post('/patients/{id}/visits', [PatientVisitController::class, 'store']);
Route::get('/patients/{id}/visits/{visitId}', [PatientVisitController::class, 'show']);
Route::put('/patients/{id}/visits/{visitId}', [PatientVisitController::class, 'update']);
Route::delete('/patients/{id}/visits/{visitId}', [PatientVisitController::class, 'destroy']);
