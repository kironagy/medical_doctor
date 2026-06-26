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
});
