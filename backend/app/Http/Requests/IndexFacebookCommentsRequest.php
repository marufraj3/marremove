<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class IndexFacebookCommentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'decision' => ['nullable', 'in:keep,review,hide,delete,pending'],
            'category' => ['nullable', 'string', 'max:64'],
            'method' => ['nullable', 'in:manual,ai,fallback'],
            'action_status' => ['nullable', 'in:pending,processing,completed,failed,skipped'],
            'min_confidence' => ['nullable', 'numeric', 'between:0,1'],
            'max_confidence' => ['nullable', 'numeric', 'between:0,1', 'gte:min_confidence'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
