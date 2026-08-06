<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'upload_id' => 'required|string|size:36',
            'chunk_index' => 'required|integer|min:0',
            'chunk' => 'required|file|max:51200',
            'checksum' => 'sometimes|string|size:64',
        ];
    }
}
