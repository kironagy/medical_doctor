<?php

namespace App\Providers;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\Api\ApiPatientFileRepository;
use App\Repositories\Api\ApiPatientNoteRepository;
use App\Repositories\Api\ApiPatientRepository;
use App\Repositories\Api\ApiPatientVisitRepository;
use App\Repositories\Api\ApiUserRepository;
use App\Repositories\Eloquent\EloquentPatientFileRepository;
use App\Repositories\Eloquent\EloquentPatientNoteRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Repositories\Eloquent\EloquentPatientVisitRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $nativeAppId = env('NATIVEPHP_APP_ID');
        $isNative = $nativeAppId !== null;

        Log::debug('[RepositoryServiceProvider] NATIVEPHP_APP_ID=' . ($nativeAppId ?? 'null') . ' → ' . ($isNative ? 'BINDING API REPOS' : 'BINDING ELOQUENT REPOS'));

        $this->app->bind(PatientRepositoryInterface::class, $isNative
            ? ApiPatientRepository::class
            : EloquentPatientRepository::class);

        $this->app->bind(UserRepositoryInterface::class, $isNative
            ? ApiUserRepository::class
            : EloquentUserRepository::class);

        $this->app->bind(PatientFileRepositoryInterface::class, $isNative
            ? ApiPatientFileRepository::class
            : EloquentPatientFileRepository::class);

        $this->app->bind(PatientNoteRepositoryInterface::class, $isNative
            ? ApiPatientNoteRepository::class
            : EloquentPatientNoteRepository::class);

        $this->app->bind(PatientVisitRepositoryInterface::class, $isNative
            ? ApiPatientVisitRepository::class
            : EloquentPatientVisitRepository::class);
    }
}
