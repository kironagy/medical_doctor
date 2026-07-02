<?php

namespace App\Domains\Mobile\Services;

use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AutoLoginService
{
    protected static function isEnabled(): bool
    {
        if (app()->environment('production')) return false;

        return config('nativephp.auto_login', false)
            || env('NATIVE_AUTO_LOGIN', false)
            || (config('app.env') === 'local' && app()->runningInConsole());
    }

    public static function shouldAutoLogin(): bool
    {
        if (!static::isEnabled()) return false;
        if (Auth::check()) return false;

        $seeded = Cache::get('mobile_demo_seeded', false);
        if (!$seeded) {
            static::seedIfNeeded();
        }

        $user = User::where('email', 'doctor@demo.com')->first();
        if (!$user) return false;

        return true;
    }

    public static function autoLogin(): ?User
    {
        if (!static::isEnabled()) return null;
        if (Auth::check()) return Auth::user();

        static::seedIfNeeded();

        $user = User::where('email', 'doctor@demo.com')->first();
        if (!$user) return null;

        Auth::login($user);

        return $user;
    }

    protected static function seedIfNeeded(): void
    {
        if (Cache::get('mobile_demo_seeded', false)) return;

        $hasUsers = User::count() > 1;
        if ($hasUsers) {
            Cache::forever('mobile_demo_seeded', true);
            return;
        }

        try {
            DB::beginTransaction();
            $seeder = new \Database\Seeders\MobileDemoSeeder;
            $seeder->run();
            DB::commit();
            Cache::forever('mobile_demo_seeded', true);
        } catch (\Throwable $e) {
            DB::rollBack();
            logger()->error('Auto-seed failed', ['error' => $e->getMessage()]);
        }
    }
}
