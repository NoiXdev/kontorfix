<?php

namespace App\Http\Requests\Settings;

use App\Http\Middleware\ConfirmPasswordUnlessSubmitted;
use App\Rules\CurrentPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        //
        // Optional only when the caller has just proved themselves at the confirmation
        // screen — the route's ConfirmPasswordUnlessSubmitted sends them there when they
        // submit no password, which is the only way an account whose owner never knew one
        // can ever switch its second factor off. requiredIf is implicit, so it still runs
        // when the value is null and `nullable` has skipped everything else: if the route
        // ever lost that middleware, this falls back to demanding the password rather than
        // to accepting nothing.
        return ['password' => [
            'nullable',
            'string',
            Rule::requiredIf(fn (): bool => ! ConfirmPasswordUnlessSubmitted::confirmedRecently($this)),
            new CurrentPassword,
        ]];
    }
}
