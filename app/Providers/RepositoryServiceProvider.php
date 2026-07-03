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
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $useApi = env('NATIVEPHP_APP_ID') !== null;

        $this->app->bind(PatientRepositoryInterface::class, $useApi
            ? ApiPatientRepository::class
            : EloquentPatientRepository::class);

        $this->app->bind(UserRepositoryInterface::class, $useApi
            ? ApiUserRepository::class
            : EloquentUserRepository::class);

        $this->app->bind(PatientFileRepositoryInterface::class, $useApi
            ? ApiPatientFileRepository::class
            : EloquentPatientFileRepository::class);

        $this->app->bind(PatientNoteRepositoryInterface::class, $useApi
            ? ApiPatientNoteRepository::class
            : EloquentPatientNoteRepository::class);

        $this->app->bind(PatientVisitRepositoryInterface::class, $useApi
            ? ApiPatientVisitRepository::class
            : EloquentPatientVisitRepository::class);
    }
}
