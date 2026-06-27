<?php

namespace App\Sync\Handlers;

use App\Models\FileCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class CategorySyncHandler extends BaseSyncHandler
{
    protected string $modelClass = FileCategory::class;

    /**
     * Validate category payload.
     */
    public function validate(array $payload, string $operation, ?Model $model = null): array
    {
        $rules = [
            'uuid' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:50'],
            'client_updated_at' => ['nullable', 'string'],
        ];

        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        return [];
    }

    /**
     * Transform category payload.
     */
    public function transform(array $payload, string $operation): array
    {
        return $this->cleanPayload($payload);
    }
}
