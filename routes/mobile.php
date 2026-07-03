<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\DashboardController;
use App\Http\Controllers\Mobile\PatientController;
use App\Http\Controllers\Mobile\VisitController;
use App\Http\Controllers\Mobile\NoteController;
use App\Http\Controllers\Mobile\FileController;
use App\Http\Controllers\Mobile\ProfileController;
use App\Http\Controllers\Mobile\SearchController;

// Guest routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login');

// Authenticated routes
Route::middleware('mobile.auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Patients
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    Route::get('/patients/create', [PatientController::class, 'create'])->name('patients.create');
    Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
    Route::get('/patients/{uuid}', [PatientController::class, 'show'])->name('patients.show');
    Route::get('/patients/{uuid}/edit', [PatientController::class, 'edit'])->name('patients.edit');
    Route::put('/patients/{uuid}', [PatientController::class, 'update'])->name('patients.update');
    Route::delete('/patients/{uuid}', [PatientController::class, 'destroy'])->name('patients.destroy');

    // Visits
    Route::post('/patients/{uuid}/visits', [VisitController::class, 'store'])->name('visits.store');
    Route::delete('/patients/{uuid}/visits/{visitId}', [VisitController::class, 'destroy'])->name('visits.destroy');

    // Notes
    Route::post('/patients/{uuid}/notes', [NoteController::class, 'store'])->name('notes.store');
    Route::put('/patients/{uuid}/notes/{noteUuid}', [NoteController::class, 'update'])->name('notes.update');
    Route::delete('/patients/{uuid}/notes/{noteUuid}', [NoteController::class, 'destroy'])->name('notes.destroy');

    // Files
    Route::get('/files/{fileUuid}/download', [FileController::class, 'download'])->name('files.download');
    Route::post('/patients/{uuid}/files', [FileController::class, 'store'])->name('files.store');
    Route::delete('/files/{fileUuid}', [FileController::class, 'destroy'])->name('files.destroy');

    // Profile
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

    // Search
    Route::get('/search', [SearchController::class, 'search'])->name('search');
});
