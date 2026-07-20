<?php

namespace App\Domains\Mobile\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'color' => $this->color,
            'is_custom' => $this->is_custom ?? false,
        ];
    }
}
