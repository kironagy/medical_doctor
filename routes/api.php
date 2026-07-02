<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\FileAccessController;
use App\Http\Controllers\AuthController as WebAuthController;
use App\Http\Middleware\MobileApiLogger;

Route::get('/files/{uuid}/stream', [FileAccessController::class, 'streamDirect'])
    ->middleware('signed')
    ->name('files.stream');

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// NativePHP local endpoints to store auth data
Route::post('/native/auth/store', [WebAuthController::class, 'storeNativeAuth']);

// Mobile sync API routes — loaded here so route:cache includes them reliably
Route::prefix('mobile/v1')->middleware(MobileApiLogger::class)->group(function () {
    require __DIR__.'/mobile.php';
});
