<?php

namespace Tests\Unit;

use App\Contracts\Repositories\PatientRepositoryInterface;
use App\Providers\RepositoryServiceProvider;
use App\Repositories\Hybrid\HybridPatientRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_repository_uses_hybrid_binding(): void
    {
        putenv('NATIVEPHP_RUNNING=true');
        $_ENV['NATIVEPHP_RUNNING'] = 'true';

        $provider = new RepositoryServiceProvider($this->app);
        $provider->register();

        $repository = $this->app->make(PatientRepositoryInterface::class);

        $this->assertInstanceOf(HybridPatientRepository::class, $repository);

        putenv('NATIVEPHP_RUNNING');
        unset($_ENV['NATIVEPHP_RUNNING']);
    }
}
