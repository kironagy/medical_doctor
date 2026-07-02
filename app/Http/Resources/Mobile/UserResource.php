<?php

namespace App\Http\Resources\Mobile;

use App\Domains\Users\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'phone' => $this->phone,
            'address' => $this->address,
            'specialization' => $this->specialization,
            'code' => $this->code,
            'status' => $this->status,
            'avatar_url' => $this->avatar_url,
            'preferences' => $this->preferences,
            'client_updated_at' => $this->client_updated_at ? Carbon::parse($this->client_updated_at)->toISOString() : null,
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toISOString() : null,
            'updated_at' => $this->updated_at ? Carbon::parse($this->updated_at)->toISOString() : null,
        ];
    }
}
