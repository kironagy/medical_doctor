<?php

namespace App\Sync\Handlers;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class PatientSyncHandler extends BaseSyncHandler
{
    protected string $modelClass = Patient::class;

    /**
     * Validate patient payload.
     */
    public function validate(array $payload, string $operation, ?Model $model = null): array
    {
        if (strtolower($operation) === 'delete') {
            $validator = Validator::make($payload, [
                'uuid' => ['required', 'string'],
            ]);
            return $validator->fails() ? $validator->errors()->toArray() : [];
        }

        $rules = [
            'uuid' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'diagnosis' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'max:20'],
            'client_updated_at' => ['nullable', 'string'],
        ];

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        // Check unique code constraint manually before database query to avoid integrity exceptions
        $uuid = $payload['uuid'] ?? null;
        $code = $payload['code'] ?? null;
        if ($code && $uuid) {
            $duplicateExists = Patient::where('code', $code)
                ->where('uuid', '!=', $uuid)
                ->exists();

            if ($duplicateExists) {
                return ['code' => ["The patient code [{$code}] is already in use by another patient record."]];
            }
        }

        return [];
    }

    /**
     * Transform patient payload.
     */
    public function transform(array $payload, string $operation): array
    {
        return $this->cleanPayload($payload);
    }
}
