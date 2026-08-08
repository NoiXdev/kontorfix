<?php

namespace App\Http\Requests\Settings;

use App\Rules\CurrentPassword;
use Illuminate\Foundation\Http\FormRequest;

class DisableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // Not the framework's `current_password`: that rule is unthrottled and unlogged,
        // so it was the way around the metered comparison on `POST /confirm-password`.
        return ['password' => ['required', new CurrentPassword]];
    }
}
