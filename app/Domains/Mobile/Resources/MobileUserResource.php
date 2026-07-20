<?php

namespace App\Domains\Mobile\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roleNames = $this->getRoleNames();
        $role = $roleNames->isNotEmpty() ? $roleNames->first() : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'specialization' => $this->specialization,
            'code' => $this->code,
            'avatar_url' => $this->avatar_url,
            'role' => $role,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'preferences' => $this->preferences,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
