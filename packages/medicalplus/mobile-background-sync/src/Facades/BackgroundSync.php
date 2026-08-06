<?php

namespace MedicalPlus\BackgroundSync\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void start(string $title, string $message = '')
 * @method static void progress(string $message, ?int $percent = null)
 * @method static void stop(?string $message = null)
 */
class BackgroundSync extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MedicalPlus\BackgroundSync\BackgroundSync::class;
    }
}
