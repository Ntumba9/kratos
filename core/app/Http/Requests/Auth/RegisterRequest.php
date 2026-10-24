<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Emails are stored lower-cased (see User::email()), so normalise before
     * the uniqueness check runs.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'max:254', 'email:rfc,strict', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'organisation_name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
