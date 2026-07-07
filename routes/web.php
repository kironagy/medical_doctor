<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// ─── Auth Routes ───────────────────────────────────────────────
Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout',[AuthController::class, 'logout'])->name('logout');

// ─── Protected Routes ──────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        if (auth()->user()->role === 'admin') {
            return redirect('/admin');
        }
        return view('index');
    });

    Route::get('/patient/{id}', function ($id) {
        if (auth()->user()->role === 'admin') {
            return redirect('/admin');
        }
        $categories = \App\Models\FileCategory::all();
        return view('show', compact('categories'));
    });

    Route::middleware('can:admin')->group(function () {
        Route::get('/admin', [\App\Http\Controllers\AdminController::class, 'index'])->name('admin.index');
        
        Route::post('/admin/doctors', [\App\Http\Controllers\AdminController::class, 'storeDoctor']);
        Route::put('/admin/doctors/{id}', [\App\Http\Controllers\AdminController::class, 'updateDoctor']);
        Route::delete('/admin/doctors/{id}', [\App\Http\Controllers\AdminController::class, 'destroyDoctor']);

        Route::post('/admin/categories', [\App\Http\Controllers\AdminController::class, 'storeCategory']);
        Route::put('/admin/categories/{id}', [\App\Http\Controllers\AdminController::class, 'updateCategory']);
        Route::delete('/admin/categories/{id}', [\App\Http\Controllers\AdminController::class, 'destroyCategory']);
    });

    // Upload & File API (under auth)
    Route::prefix('api/v1')->group(function () {
        Route::post('/chunk/init', [\App\Http\Controllers\Api\ChunkUploadController::class, 'init']);
        Route::post('/chunk/chunk', [\App\Http\Controllers\Api\ChunkUploadController::class, 'chunk']);
        Route::post('/chunk/complete', [\App\Http\Controllers\Api\ChunkUploadController::class, 'complete']);
        Route::post('/chunk/{uuid}/cancel', [\App\Http\Controllers\Api\ChunkUploadController::class, 'cancel']);
        Route::get('/chunk/{uuid}/status', [\App\Http\Controllers\Api\ChunkUploadController::class, 'status']);

        Route::post('/patients/{patientUuid}/files', [\App\Http\Controllers\Api\UploadController::class, 'store']);

        Route::get('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'streamDirect'])->name('api.files.stream');
        Route::get('/files/{uuid}/signed-url', [\App\Http\Controllers\Api\FileAccessController::class, 'generateSignedUrl']);
        Route::get('/files/{uuid}/thumbnail', [\App\Http\Controllers\Api\FileAccessController::class, 'thumbnailDirect']);
        Route::delete('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'destroy']);
        Route::put('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'update']);

        Route::get('/patients/{patientUuid}/categories/{slug}/files', [\App\Http\Controllers\Api\CategoryFileController::class, 'files']);
    });
});
