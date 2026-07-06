<?php

namespace App\Providers;

use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\Eloquent\EloquentPatientFileRepository;
use App\Repositories\Eloquent\EloquentPatientNoteRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Repositories\Eloquent\EloquentPatientVisitRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Repositories\Hybrid\HybridPatientRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Hybrid: always sync CRUD operations to production API, fall back to local when offline
        $this->app->bind(PatientRepositoryInterface::class, HybridPatientRepository::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(PatientFileRepositoryInterface::class, EloquentPatientFileRepository::class);
        $this->app->bind(PatientNoteRepositoryInterface::class, EloquentPatientNoteRepository::class);
        $this->app->bind(PatientVisitRepositoryInterface::class, EloquentPatientVisitRepository::class);
    }
}
