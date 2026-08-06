<?php

namespace MedicalPlus\NativeFiles\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array serve(string $absolutePath, ?string $token = null)
 * @method static array save(string $url, string $fileName, ?string $mime = null, ?string $cookie = null)
 * @method static array saveBytes(string $base64, string $fileName, ?string $mime = null)
 */
class NativeFiles extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MedicalPlus\NativeFiles\NativeFiles::class;
    }
}
