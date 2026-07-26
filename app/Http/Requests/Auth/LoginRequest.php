<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => __('Email address'),
            'password' => __('Password'),
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => __('The email address field is required.'),
            'email.email' => __('The email address must be a valid email address.'),
            'password.required' => __('The password field is required.'),
        ];
    }
}
