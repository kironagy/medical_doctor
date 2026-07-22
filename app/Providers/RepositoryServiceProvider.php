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
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Services\Mobile\ApiService;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // API-first architecture: all repositories communicate directly with the remote API.
        // No local SQLite, no hybrid fallback, no sync services.
        // The API is the single source of truth.
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(PatientRepositoryInterface::class, ApiPatientRepository::class);
        $this->app->bind(PatientFileRepositoryInterface::class, ApiPatientFileRepository::class);
        $this->app->bind(PatientNoteRepositoryInterface::class, ApiPatientNoteRepository::class);
        $this->app->bind(PatientVisitRepositoryInterface::class, ApiPatientVisitRepository::class);

        // Singleton for API service (manages token lifecycle)
        $this->app->singleton(ApiService::class);
    }
}
