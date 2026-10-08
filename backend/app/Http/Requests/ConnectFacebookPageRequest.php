<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ConnectFacebookPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $token = $this->input('page_access_token');

        if (is_string($token)) {
            $this->merge(['page_access_token' => trim($token)]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'page_access_token' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page_access_token.required' => 'Enter a Facebook Page Access Token.',
            'page_access_token.string' => 'The Facebook Page Access Token must be text.',
            'page_access_token.max' => 'The Facebook Page Access Token is too long.',
        ];
    }
}
