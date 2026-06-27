<?php

namespace App\Sync\Handlers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class UserSyncHandler extends BaseSyncHandler
{
    protected string $modelClass = User::class;

    /**
     * Validate the user payload.
     */
    public function validate(array $payload, string $operation, ?Model $model = null): array
    {
        $rules = [
            'uuid' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'client_updated_at' => ['nullable', 'string'],
        ];

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        return [];
    }

    /**
     * Custom transform to hash raw passwords and strip empty password values.
     */
    public function transform(array $payload, string $operation): array
    {
        $cleaned = $this->cleanPayload($payload);

        if (!empty($cleaned['password'])) {
            $pass = $cleaned['password'];
            // bcrypt strings start with $2y$, $2a$, or $2b$
            $isHashed = str_starts_with($pass, '$2y$') || str_starts_with($pass, '$2a$') || str_starts_with($pass, '$2b$');
            if (!$isHashed) {
                $cleaned['password'] = Hash::make($pass);
            }
        } else {
            // Unset empty/omitted passwords so we do not overwrite existing passwords with null
            unset($cleaned['password']);
        }

        return $cleaned;
    }

    /**
     * Intercept apply to handle custom skip rules when password is omitted and user doesn't exist.
     */
    public function apply(string $operation, array $payload, ?string $uuid = null): array
    {
        $action = strtolower($operation);
        $uuid = $uuid ?: ($payload['uuid'] ?? null);
        $email = $payload['email'] ?? null;

        if (!$uuid) {
            $err = 'User sync failed: missing UUID.';
            Log::warning($err, ['payload' => $payload]);
            return ['uuid' => null, 'status' => 'failed', 'error' => $err, 'id' => null];
        }

        // Find existing user by UUID or Email
        $model = $this->findModelByUuid($uuid);
        if (!$model && $email) {
            $model = User::where('email', $email)->first();
        }

        $hasPassword = !empty($payload['password']);

        // Check user creation constraint
        if (!$model && !$hasPassword) {
            $msg = "Skipped user sync: user [{$email}] does not exist locally/remotely and password is excluded from sync.";
            Log::info($msg, [
                'uuid' => $uuid,
                'email' => $email
            ]);
            return [
                'uuid' => $uuid,
                'status' => 'skipped',
                'error' => $msg,
                'id' => null
            ];
        }

        // Normal base handler apply
        return parent::apply($operation, $payload, $uuid);
    }
}
