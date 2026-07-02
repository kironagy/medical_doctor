<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\FileAccessController;
use App\Http\Controllers\AuthController as WebAuthController;
use App\Http\Middleware\MobileApiLogger;
use App\Domains\Mobile\Services\ProductionApiService;
use App\Domains\Mobile\Services\MobileSyncService;

Route::get('/files/{uuid}/stream', [FileAccessController::class, 'streamDirect'])
    ->middleware('signed')
    ->name('files.stream');

// Diagnostics endpoint for NativePHP
Route::get('/native/diagnostics', function (Request $request) {
    $logger = Log::channel('mobile-api');
    $logger->info('=== DIAGNOSTICS ENDPOINT HIT ===');

    $api = new ProductionApiService();
    $token = MobileSyncService::getStoredToken();
    $user = MobileSyncService::getStoredUser();

    $diagnostics = [
        'api_base_url' => config('nativephp.production_api_url'),
        'app_environment' => app()->environment(),
        'is_nativephp' => app()->environment('nativephp'),
        'has_token' => !is_null($token),
        'user' => $user,
        'online_check' => $api->isOnline(),
    ];

    $logger->info('Diagnostics data', $diagnostics);

    return response()->json($diagnostics);
});

// NativePHP authentication endpoints
Route::get('/native/auth/check', [WebAuthController::class, 'checkNativeAuth']);
Route::post('/native/auth/store', [WebAuthController::class, 'storeNativeAuth']);
Route::post('/native/auth/offline', [WebAuthController::class, 'offlineNativeLogin']);

Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Mobile sync API routes — loaded here so route:cache includes them reliably
Route::prefix('mobile/v1')->middleware(MobileApiLogger::class)->group(function () {
    require __DIR__.'/mobile.php';
});
