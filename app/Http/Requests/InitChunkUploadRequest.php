<?php

namespace App\Http\Requests;

use App\Services\Upload\UploadValidationService;
use Illuminate\Foundation\Http\FormRequest;

class InitChunkUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file_name' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1|max:5368709120',
            'mime_type' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (!UploadValidationService::isMimeAllowed($value)) {
                    $fail("Unsupported file type: {$value}");
                }
            }],
            'patient_id' => 'required',
            'chunk_size' => 'sometimes|integer|min:1048576|max:52428800',
            'metadata' => 'sometimes|array',
            'metadata.title' => 'sometimes|nullable|string|max:255',
            'metadata.desc' => 'sometimes|nullable|string|max:1000',
            'metadata.category' => 'sometimes|nullable|string|max:100',
            'metadata.date' => 'sometimes|nullable|date',
        ];
    }
}
