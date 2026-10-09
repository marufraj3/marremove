<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ImportSelectedManagedFacebookPagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isModerationAdmin();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'import_id' => ['required', 'uuid'],
            'facebook_page_ids' => ['required', 'array', 'min:1', 'max:100'],
            'facebook_page_ids.*' => ['required', 'string', 'max:128', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'import_id.required' => 'Find the Pages first, then choose which ones to connect.',
            'import_id.uuid' => 'This Page list is invalid. Find the Pages again.',
            'facebook_page_ids.required' => 'Select at least one Facebook Page to connect.',
            'facebook_page_ids.min' => 'Select at least one Facebook Page to connect.',
            'facebook_page_ids.max' => 'Select no more than 100 Pages at once.',
            'facebook_page_ids.*.distinct' => 'A Facebook Page can only be selected once.',
        ];
    }
}
