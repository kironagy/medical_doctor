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
use App\Repositories\Hybrid\HybridPatientFileRepository;
use App\Repositories\Hybrid\HybridPatientNoteRepository;
use App\Repositories\Hybrid\HybridPatientRepository;
use App\Repositories\Hybrid\HybridPatientVisitRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $isNative = (bool) env('NATIVEPHP_APP_ID');

        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);

        if ($isNative) {
            // In NativePHP (mobile): use Hybrid repos for offline support + sync
            $this->app->bind(PatientRepositoryInterface::class, HybridPatientRepository::class);
            $this->app->bind(PatientFileRepositoryInterface::class, HybridPatientFileRepository::class);
            $this->app->bind(PatientNoteRepositoryInterface::class, HybridPatientNoteRepository::class);
            $this->app->bind(PatientVisitRepositoryInterface::class, HybridPatientVisitRepository::class);
        } else {
            // On the web server: use Eloquent directly (no local SQLite cache needed)
            $this->app->bind(PatientRepositoryInterface::class, EloquentPatientRepository::class);
            $this->app->bind(PatientFileRepositoryInterface::class, EloquentPatientFileRepository::class);
            $this->app->bind(PatientNoteRepositoryInterface::class, EloquentPatientNoteRepository::class);
            $this->app->bind(PatientVisitRepositoryInterface::class, EloquentPatientVisitRepository::class);
        }
    }
}
