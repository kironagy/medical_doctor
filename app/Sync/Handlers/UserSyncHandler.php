<?php

namespace App\Sync\Handlers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * UserSyncHandler
 *
 * Users are NOT ordinary sync entities. This handler implements a fully
 * standalone apply() that never relies on BaseSyncHandler::apply() or
 * Model::create(), both of which would fail the NOT NULL password constraint.
 *
 * Rules:
 *  - New users without a password are SKIPPED (not failed).
 *  - Existing users are always updated via a safe UPDATE statement.
 *  - Password is NEVER overwritten unless explicitly provided.
 *  - Delete operations soft-delete the user record.
 *  - Every operation is wrapped in a DB::transaction().
 */
class UserSyncHandler extends BaseSyncHandler
{
    protected string $modelClass = User::class;

    // ─── Validation ───────────────────────────────────────────────────────────

    public function validate(array $payload, string $operation, ?Model $model = null): array
    {
        if (strtolower($operation) === 'delete') {
            $validator = Validator::make($payload, [
                'uuid' => ['required', 'string'],
            ]);
            return $validator->fails() ? $validator->errors()->toArray() : [];
        }

        $validator = Validator::make($payload, [
            'uuid'           => ['required', 'string'],
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'max:255'],
            'role'           => ['nullable', 'string', 'max:50'],
            'phone'          => ['nullable', 'string', 'max:50'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'client_updated_at' => ['nullable', 'string'],
        ]);

        return $validator->fails() ? $validator->errors()->toArray() : [];
    }

    // ─── Transform ────────────────────────────────────────────────────────────

    public function transform(array $payload, string $operation): array
    {
        $cleaned = $this->cleanPayload($payload);

        if (!empty($cleaned['password'])) {
            $pass = $cleaned['password'];
            // Detect already-hashed bcrypt strings
            $isHashed = str_starts_with($pass, '$2y$')
                     || str_starts_with($pass, '$2a$')
                     || str_starts_with($pass, '$2b$');
            $cleaned['password'] = $isHashed ? $pass : Hash::make($pass);
        } else {
            // Never overwrite existing password with null/empty
            unset($cleaned['password']);
        }

        return $cleaned;
    }

    // ─── Apply — Fully Standalone ─────────────────────────────────────────────

    /**
     * Standalone apply() — does NOT call parent::apply().
     *
     * This method guarantees that:
     * 1. New users without a password are safely SKIPPED.
     * 2. Existing users are updated via DB::table() to avoid mass-assignment
     *    and Eloquent auto-hashing conflicts.
     * 3. The password column is never touched unless explicitly provided.
     * 4. All writes are wrapped in a DB::transaction().
     */
    public function apply(string $operation, array $payload, ?string $uuid = null): array
    {
        $action = strtolower($operation);
        $uuid   = $uuid ?: ($payload['uuid'] ?? null);
        $email  = $payload['email'] ?? null;

        if (!$uuid) {
            $err = 'User sync failed: missing UUID.';
            Log::warning($err, ['payload' => $payload]);
            return ['uuid' => null, 'status' => 'failed', 'error' => $err, 'id' => null];
        }

        // ── 1. Validate ──────────────────────────────────────────────────────
        $errors = $this->validate($payload, $action);
        if (!empty($errors)) {
            $err = 'User sync validation failed: ' . json_encode($errors, JSON_UNESCAPED_UNICODE);
            Log::warning($err, ['uuid' => $uuid]);
            return ['uuid' => $uuid, 'status' => 'failed', 'error' => $err, 'id' => null];
        }

        try {
            // ── 2. Locate existing user by UUID or email ─────────────────────
            $model = $this->findModelByUuid($uuid);
            if (!$model && $email) {
                $model = User::where('email', $email)->first();
            }

            $hasPassword = !empty($payload['password']);

            // ── 3. Delete ────────────────────────────────────────────────────
            if ($action === 'delete') {
                return DB::transaction(function () use ($model, $uuid) {
                    if ($model) {
                        $model->delete();
                        Log::info('User sync: soft-deleted.', ['uuid' => $uuid, 'id' => $model->id]);
                        return ['uuid' => $uuid, 'status' => 'deleted', 'error' => null, 'id' => $model->id];
                    }
                    // Already gone — idempotent delete is OK
                    return ['uuid' => $uuid, 'status' => 'deleted', 'error' => null, 'id' => null];
                });
            }

            // ── 4. New user without password — skip safely ───────────────────
            if (!$model && !$hasPassword) {
                $msg = "User sync skipped: user [{$email}] does not exist and no password was provided.";
                Log::info($msg, ['uuid' => $uuid, 'email' => $email]);
                return ['uuid' => $uuid, 'status' => 'skipped', 'error' => $msg, 'id' => null];
            }

            // ── 5. Transform payload ─────────────────────────────────────────
            $transformed = $this->transform($payload, $action);

            // ── 6. Conflict resolution ───────────────────────────────────────
            if ($model && !$this->resolveConflict($model, $transformed)) {
                return [
                    'uuid'   => $uuid,
                    'status' => 'conflict_server_won',
                    'error'  => null,
                    'id'     => $model->id,
                ];
            }

            // ── 7. Upsert — safe DB::table() so password is never auto-hashed ─
            return DB::transaction(function () use ($model, $transformed, $uuid, $email) {
                if ($model) {
                    // Normalize datetime fields to MySQL-compatible format.
                    // DB::table() bypasses Eloquent casting, so raw ISO strings
                    // (e.g. "2026-06-27T17:00:00.000000Z") must be converted
                    // to MySQL's "YYYY-MM-DD HH:MM:SS" format.
                    if (!empty($transformed['client_updated_at'])) {
                        $transformed['client_updated_at'] = \Illuminate\Support\Carbon::parse($transformed['client_updated_at'])->toDateTimeString();
                    }

                    // UPDATE — never touch password unless included in $transformed
                    DB::table('users')
                        ->where('id', $model->id)
                        ->update($transformed + ['updated_at' => now()]);

                    Log::info('User sync: updated.', ['uuid' => $uuid, 'id' => $model->id]);
                    return ['uuid' => $uuid, 'status' => 'applied', 'error' => null, 'id' => $model->id];
                }

                // INSERT — only reached when a password IS included
                $transformed['uuid']              = $uuid;
                $transformed['email']             ??= $email;
                $transformed['email_verified_at'] ??= now();
                $transformed['created_at']        = now();
                $transformed['updated_at']        = now();

                $id = DB::table('users')->insertGetId($transformed);

                Log::info('User sync: created.', ['uuid' => $uuid, 'id' => $id, 'email' => $email]);
                return ['uuid' => $uuid, 'status' => 'applied', 'error' => null, 'id' => $id];
            });

        } catch (Throwable $throwable) {
            $err = "User sync exception [{$uuid}]: " . $throwable->getMessage();
            Log::error($err, [
                'uuid'  => $uuid,
                'email' => $email,
                'trace' => $throwable->getTraceAsString(),
            ]);
            return ['uuid' => $uuid, 'status' => 'failed', 'error' => $throwable->getMessage(), 'id' => null];
        }
    }
}
