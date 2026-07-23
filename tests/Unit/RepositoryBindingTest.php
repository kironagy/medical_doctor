<?php

namespace Tests\Unit;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Providers\RepositoryServiceProvider;
use App\Repositories\PatientRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_repository_binding(): void
    {
        $provider = new RepositoryServiceProvider($this->app);
        $provider->register();

        $repository = $this->app->make(PatientRepositoryInterface::class);

        $this->assertInstanceOf(PatientRepository::class, $repository);
    }

    public function test_file_cache_repository_binding(): void
    {
        $provider = new RepositoryServiceProvider($this->app);
        $provider->register();

        $repository = $this->app->make(\App\Contracts\Repositories\FileCacheRepositoryInterface::class);

        $this->assertInstanceOf(\App\Repositories\FileCacheRepository::class, $repository);
    }
}
