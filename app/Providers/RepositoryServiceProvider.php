<?php

namespace App\Providers;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Contracts\Repositories\FileCacheRepositoryInterface;
use App\Contracts\Repositories\OfflineFileRepositoryInterface;
use App\Contracts\Repositories\PatientFileRepositoryInterface;
use App\Contracts\Repositories\PatientNoteRepositoryInterface;
use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Contracts\Repositories\PatientVisitRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Repositories\CategoryRepository;
use App\Repositories\Eloquent\EloquentCategoryRepository;
use App\Repositories\Eloquent\EloquentPatientFileRepository;
use App\Repositories\Eloquent\EloquentPatientNoteRepository;
use App\Repositories\Eloquent\EloquentPatientRepository;
use App\Repositories\Eloquent\EloquentPatientVisitRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Repositories\FileCacheRepository;
use App\Repositories\OfflineFileRepository;
use App\Repositories\PatientRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 5: offline-aware CRUD with local-first reads, API writes with fallback
        $this->app->bind(PatientRepositoryInterface::class, PatientRepository::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(PatientFileRepositoryInterface::class, EloquentPatientFileRepository::class);
        $this->app->bind(PatientNoteRepositoryInterface::class, EloquentPatientNoteRepository::class);
        $this->app->bind(PatientVisitRepositoryInterface::class, EloquentPatientVisitRepository::class);

        // Phase 6: offline file cache
        $this->app->bind(FileCacheRepositoryInterface::class, FileCacheRepository::class);

        // Phase 7: offline file uploads (local-first, sync when online)
        $this->app->bind(OfflineFileRepositoryInterface::class, OfflineFileRepository::class);

        // Phase 8: offline category cache (API-first, local fallback)
        $this->app->bind(CategoryRepositoryInterface::class, CategoryRepository::class);
    }
}
