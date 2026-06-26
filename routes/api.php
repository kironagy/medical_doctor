<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientFileController;
use App\Http\Controllers\PatientVisitController;
use App\Http\Controllers\PatientOverviewController;

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
