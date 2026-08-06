<?php

namespace MedicalPlus\NativeFiles;

use Illuminate\Support\ServiceProvider;

class NativeFilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NativeFiles::class, fn () => new NativeFiles);
    }
}
