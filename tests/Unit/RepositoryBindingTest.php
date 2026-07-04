<?php

namespace Tests\Unit;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Providers\RepositoryServiceProvider;
use App\Repositories\Eloquent\EloquentPatientRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_repository_uses_eloquent_binding_for_all_clients(): void
    {
        putenv('NATIVEPHP_APP_ID=native-test');

        $provider = new RepositoryServiceProvider($this->app);
        $provider->register();

        $repository = $this->app->make(PatientRepositoryInterface::class);

        $this->assertInstanceOf(EloquentPatientRepository::class, $repository);

        putenv('NATIVEPHP_APP_ID');
    }
}
