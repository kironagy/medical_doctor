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
        $isNative = env('NATIVEPHP_APP_ID') !== null;

        Log::debug('[RepositoryServiceProvider] ' . ($isNative ? 'BINDING HYBRID REPOS (NativePHP)' : 'BINDING ELOQUENT REPOS (Website)'));

        $this->app->bind(PatientRepositoryInterface::class, $isNative
            ? \App\Repositories\Hybrid\HybridPatientRepository::class
            : EloquentPatientRepository::class);

        $this->app->bind(UserRepositoryInterface::class, $isNative
            ? \App\Repositories\Hybrid\HybridUserRepository::class
            : EloquentUserRepository::class);

        $this->app->bind(PatientFileRepositoryInterface::class, $isNative
            ? \App\Repositories\Hybrid\HybridPatientFileRepository::class
            : EloquentPatientFileRepository::class);

        $this->app->bind(PatientNoteRepositoryInterface::class, $isNative
            ? \App\Repositories\Hybrid\HybridPatientNoteRepository::class
            : EloquentPatientNoteRepository::class);

        $this->app->bind(PatientVisitRepositoryInterface::class, $isNative
            ? \App\Repositories\Hybrid\HybridPatientVisitRepository::class
            : EloquentPatientVisitRepository::class);
    }
}
