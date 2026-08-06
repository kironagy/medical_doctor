<?php

namespace MedicalPlus\NativeFiles;

use Illuminate\Support\ServiceProvider;

class NativeFilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NativeFiles::class, fn () => new NativeFiles);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // The post_compile hook in nativephp.json invokes this by its
            // signature, so it has to be a registered artisan command.
            $this->commands([
                Commands\PostCompileCommand::class,
            ]);
        }
    }
}
