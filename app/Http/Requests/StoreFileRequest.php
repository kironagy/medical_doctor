<?php

namespace App\Http\Requests;

use App\Services\Upload\UploadValidationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5242880', function ($attribute, $value, $fail) {
                if ($value && !UploadValidationService::isFileAllowed($value)) {
                    $fail("Unsupported file type or extension for file: " . $value->getClientOriginalName());
                }
            }],
            'title' => 'sometimes|nullable|string|max:255',
            'desc' => 'sometimes|nullable|string|max:1000',
            'category' => 'sometimes|nullable|string|max:100',
            'date' => 'sometimes|nullable|date',
        ];
    }
}
