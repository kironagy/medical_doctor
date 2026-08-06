<?php

namespace Tests\Feature;

use App\Domains\Media\Services\UploadService;
use App\Services\Upload\UploadValidationService;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

class UploadStructureTest extends TestCase
{
    public function test_chunk_routes_registered_once()
    {
        $chunkUris = [
            'api/v1/chunk/init',
            'api/v1/chunk/chunk',
            'api/v1/chunk/complete',
            'api/v1/chunk/{uuid}/cancel',
            'api/v1/chunk/{uuid}/status',
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($chunkUris as $uri) {
            $matching = $routes->filter(fn ($r) => $r->uri() === $uri);
            $this->assertCount(1, $matching, "URI {$uri} is registered {$matching->count()} times (expected 1)");
        }
    }

    public function test_upload_routes_registered_once()
    {
        $uploadUris = [
            'api/v1/patients/{patientUuid}/files',
            '_native/api/offline/uploads',
        ];

        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($uploadUris as $uri) {
            $matching = $routes->filter(fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods()));
            $this->assertCount(1, $matching, "Upload URI {$uri} is registered {$matching->count()} times (expected 1)");
        }
    }

    public function test_upload_controllers_use_form_requests()
    {
        $controllers = [
            \App\Http\Controllers\Api\ChunkUploadController::class,
            \App\Http\Controllers\Api\UploadController::class,
            \App\Http\Controllers\Api\Mobile\FileController::class,
        ];

        foreach ($controllers as $class) {
            $reflector = new ReflectionClass($class);
            $filename = $reflector->getFileName();
            $content = file_get_contents($filename);

            $this->assertStringNotContainsString(
                '$request->validate(',
                $content,
                "Controller {$class} still contains inline \$request->validate() call"
            );
        }
    }

    public function test_allowlist_is_single_source()
    {
        $reflector = new ReflectionClass(UploadService::class);
        $constants = $reflector->getConstants();

        $this->assertArrayHasKey('SAFE_EXTENSIONS', $constants);
        $this->assertEquals(
            UploadValidationService::SAFE_EXTENSIONS,
            $constants['SAFE_EXTENSIONS'],
            'UploadService SAFE_EXTENSIONS does not match UploadValidationService::SAFE_EXTENSIONS'
        );
    }

    public function test_allowlist_mime_and_extension_agree()
    {
        $extensions = UploadValidationService::SAFE_EXTENSIONS;
        $mimes = UploadValidationService::ALLOWED_MIMES;

        $this->assertContains('3gp', $extensions);
        $this->assertContains('heif', $extensions);
        $this->assertContains('m4v', $extensions);
        $this->assertContains('wmv', $extensions);
        $this->assertContains('flv', $extensions);

        $this->assertContains('video/3gpp', $mimes);
        $this->assertContains('image/heif', $mimes);
        $this->assertContains('video/x-m4v', $mimes);
        $this->assertContains('video/x-ms-wmv', $mimes);
        $this->assertContains('video/x-flv', $mimes);
    }

    public function test_no_dead_upload_routes()
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $uris = $routes->map(fn ($r) => $r->uri())->toArray();

        $this->assertContains('api/v1/chunk/init', $uris);
        $this->assertContains('api/v1/chunk/chunk', $uris);
        $this->assertContains('api/v1/chunk/complete', $uris);
        $this->assertContains('_native/api/offline/uploads', $uris);
    }
}
