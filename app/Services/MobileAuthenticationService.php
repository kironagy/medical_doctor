<?php

namespace App\Services;

use App\Models\SyncState;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class MobileAuthenticationService
{
    public function __construct(
        private readonly MobileApiClient $api,
        private readonly OfflineSyncEngine $sync,
    ) {
    }

    public function login(string $email, string $password, bool $remember = false): array
    {
        try {
            $response = $this->api->login($email, $password);
            $user = $this->cacheOnlineUser($response['user'] ?? [], $password);
            $token = $response['access_token'] ?? null;

            if ($token) {
                $this->setState('api_token', $token);

                try {
                    $this->sync->initialSeed($token);
                    $this->sync->sync($token);
                } catch (Throwable $exception) {
                    Log::warning('mobile_auth.post_login_sync_failed', [
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            Auth::login($user, $remember);

            Log::info('mobile_auth.online_login_success', [
                'user_id' => $user->id,
                'email' => $user->email,
                'api_base' => config('mobile.api_url'),
            ]);

            return [
                'success' => true,
                'mode' => 'online',
                'user' => $user,
                'access_token' => $token,
            ];
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            if ($status >= 400 && $status < 500) {
                Log::warning('mobile_auth.online_credentials_rejected', [
                    'email' => $email,
                    'status' => $status,
                ]);

                return [
                    'success' => false,
                    'mode' => 'online',
                    'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
                    'status' => $status,
                ];
            }

            return $this->offlineLogin($email, $password, $remember, $exception);
        } catch (ConnectionException $exception) {
            return $this->offlineLogin($email, $password, $remember, $exception);
        } catch (Throwable $exception) {
            return $this->offlineLogin($email, $password, $remember, $exception);
        }
    }

    private function cacheOnlineUser(array $userData, string $password): User
    {
        $email = $userData['email'] ?? null;

        if (! $email) {
            throw new \RuntimeException('Remote login response did not include a user email.');
        }

        $attributes = [
            'name' => $userData['name'] ?? $email,
            'password' => Hash::make($password),
            'role' => $userData['role'] ?? 'doctor',
            'phone' => $userData['phone'] ?? null,
            'specialization' => $userData['specialization'] ?? null,
            'client_updated_at' => now(),
        ];

        if (! empty($userData['uuid'])) {
            $attributes['uuid'] = $userData['uuid'];
        }

        return User::updateOrCreate(['email' => $email], $attributes);
    }

    private function offlineLogin(string $email, string $password, bool $remember, Throwable $cause): array
    {
        Log::warning('mobile_auth.online_unavailable_trying_offline', [
            'email' => $email,
            'exception' => $cause::class,
            'message' => $cause->getMessage(),
        ]);

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            Log::warning('mobile_auth.offline_login_failed', [
                'email' => $email,
                'user_found' => (bool) $user,
            ]);

            return [
                'success' => false,
                'mode' => 'offline',
                'message' => 'لا يوجد اتصال بالخادم ولا توجد بيانات دخول محلية صالحة لهذا المستخدم.',
                'status' => 503,
            ];
        }

        Auth::login($user, $remember);

        Log::info('mobile_auth.offline_login_success', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return [
            'success' => true,
            'mode' => 'offline',
            'user' => $user,
            'access_token' => $this->state('api_token'),
        ];
    }

    private function state(string $key): mixed
    {
        return SyncState::find($key)?->value['data'] ?? null;
    }

    private function setState(string $key, mixed $value): void
    {
        SyncState::updateOrCreate(['key' => $key], ['value' => ['data' => $value]]);
    }
}
