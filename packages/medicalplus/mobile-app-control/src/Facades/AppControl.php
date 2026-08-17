<?php

namespace MedicalPlus\AppControl\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void exit()
 */
class AppControl extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MedicalPlus\AppControl\AppControl::class;
    }
}
