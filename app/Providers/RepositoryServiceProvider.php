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
        Log::debug('[RepositoryServiceProvider] BINDING HYBRID REPOS (runtime network detection)');

        $this->app->bind(PatientRepositoryInterface::class, \App\Repositories\Hybrid\HybridPatientRepository::class);
        $this->app->bind(UserRepositoryInterface::class, \App\Repositories\Hybrid\HybridUserRepository::class);
        $this->app->bind(PatientFileRepositoryInterface::class, \App\Repositories\Hybrid\HybridPatientFileRepository::class);
        $this->app->bind(PatientNoteRepositoryInterface::class, \App\Repositories\Hybrid\HybridPatientNoteRepository::class);
        $this->app->bind(PatientVisitRepositoryInterface::class, \App\Repositories\Hybrid\HybridPatientVisitRepository::class);
    }
}
