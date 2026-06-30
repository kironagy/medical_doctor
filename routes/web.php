<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\AuthController;

Route::get('/', function () {
    return redirect('/dashboard');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

use App\Http\Controllers\PatientController;

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();
        $isSuperAdmin = $user->hasRole('super-admin');

        $stats = [
            'total_patients' => \App\Domains\Patients\Models\Patient::count(),
            'recent_files' => \App\Domains\Media\Models\PatientFile::count(),
            'active_shares' => \Illuminate\Support\Facades\DB::table('patient_shares')->count(),
            'total_doctors' => $isSuperAdmin ? \App\Domains\Users\Models\User::role('doctor')->count() : null,
            'active_doctors' => $isSuperAdmin ? \App\Domains\Users\Models\User::role('doctor')->where('status', 'active')->count() : null,
        ];

        return Inertia::render('Dashboard/Index', [
            'stats' => $stats,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    })->name('dashboard');

    Route::get('patients/shared', [PatientController::class, 'shared'])->name('patients.shared');
    Route::resource('patients', PatientController::class)->parameters([
        'patients' => 'uuid'
    ]);

    Route::post('/notes', function (\Illuminate\Http\Request $request) {
        $request->validate([
            'patient_id' => 'required',
            'category' => 'required',
            'content' => 'required'
        ]);
        
        $patient = \App\Domains\Patients\Models\Patient::findOrFail($request->patient_id);
        if ($request->user()->cannot('update', $patient)) {
            abort(403, 'Unauthorized to add notes.');
        }

        $note = \App\Domains\Patients\Models\PatientNote::create([
            'patient_id' => $request->patient_id,
            'author_id' => $request->user()->id,
            'category' => $request->category,
            'content' => $request->content
        ]);
        
        return response()->json($note);
    });

    // Admin Routes
    Route::group(['prefix' => 'admin', 'as' => 'admin.', 'middleware' => ['role:super-admin']], function () {
        Route::resource('doctors', \App\Http\Controllers\Admin\DoctorController::class);
        Route::post('doctors/{doctor}/suspend', [\App\Http\Controllers\Admin\DoctorController::class, 'suspend'])->name('doctors.suspend');
        Route::get('doctors/{doctor}/patients', [\App\Http\Controllers\Admin\DoctorController::class, 'patients'])->name('doctors.patients');
        Route::get('doctors/{doctor}/files', [\App\Http\Controllers\Admin\DoctorController::class, 'files'])->name('doctors.files');
    });

    // Settings Routes
    Route::group(['prefix' => 'settings', 'as' => 'settings.'], function () {
        Route::get('/', [\App\Http\Controllers\SettingsController::class, 'index'])->name('index');
        Route::post('/profile', [\App\Http\Controllers\SettingsController::class, 'updateProfile'])->name('profile');
        Route::delete('/avatar', [\App\Http\Controllers\SettingsController::class, 'removeAvatar'])->name('avatar.remove');
        Route::put('/password', [\App\Http\Controllers\SettingsController::class, 'updatePassword'])->name('password');
        Route::put('/preferences', [\App\Http\Controllers\SettingsController::class, 'updatePreferences'])->name('preferences');
    });

    // Internal SPA API Routes (Inherits Web Session Auth)
    Route::prefix('api/v1')->group(function () {
        Route::post('/uploads/init', [\App\Http\Controllers\Api\UploadController::class, 'init']);
        Route::post('/uploads/chunk', [\App\Http\Controllers\Api\UploadController::class, 'chunk']);
        Route::get('/uploads/status', [\App\Http\Controllers\Api\UploadController::class, 'status']);
        Route::post('/uploads/cancel', [\App\Http\Controllers\Api\UploadController::class, 'cancel']);
        Route::post('/uploads/complete', [\App\Http\Controllers\Api\UploadController::class, 'complete']);

        Route::get('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'streamDirect']);
        Route::get('/files/{uuid}/status', [\App\Http\Controllers\Api\FileAccessController::class, 'status']);
        Route::get('/files/{uuid}/thumbnail', [\App\Http\Controllers\Api\FileAccessController::class, 'thumbnailDirect']);
        Route::get('/files/{uuid}/hls/{path?}', [\App\Http\Controllers\Api\FileAccessController::class, 'serveHls'])->where('path', '.*');
        Route::delete('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'destroy']);
        Route::put('/files/{uuid}', [\App\Http\Controllers\Api\FileAccessController::class, 'update']);
        
        // Global Search API
        Route::get('/search', [\App\Http\Controllers\Api\GlobalSearchController::class, 'search']);
        
        // Sharing API
        Route::get('/doctors/search', [\App\Http\Controllers\Api\PatientShareController::class, 'searchDoctors']);
        Route::get('/patients/{patient:uuid}/shares', [\App\Http\Controllers\Api\PatientShareController::class, 'index']);
        Route::post('/patients/{patient:uuid}/shares', [\App\Http\Controllers\Api\PatientShareController::class, 'store']);
        Route::delete('/patients/{patient:uuid}/shares/{shareId}', [\App\Http\Controllers\Api\PatientShareController::class, 'destroy']);
    });
});
