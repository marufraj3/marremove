<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DiscoverManagedFacebookPagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isModerationAdmin();
    }

    protected function prepareForValidation(): void
    {
        $token = $this->input('user_access_token');

        if (is_string($token)) {
            $this->merge(['user_access_token' => trim($token)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'user_access_token' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_access_token.required' => 'Enter a Facebook User Access Token.',
            'user_access_token.string' => 'The Facebook User Access Token must be text.',
            'user_access_token.max' => 'The Facebook User Access Token is too long.',
        ];
    }
}
