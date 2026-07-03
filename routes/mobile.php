<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MobileAppController;

Route::get('/{any?}', [MobileAppController::class, 'index'])
    ->where('any', '.*')
    ->name('mobile.app');
