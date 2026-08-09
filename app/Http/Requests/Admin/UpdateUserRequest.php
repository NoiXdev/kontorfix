<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Rules\UniqueEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Only normalise the super-admin flag when the form actually submits it, so a
        // role-only dropdown change doesn't silently clear it.
        if ($this->has('is_super_admin')) {
            $this->merge(['is_super_admin' => $this->boolean('is_super_admin')]);
        }

        // See StoreUserRequest: one address is one identity, whatever case it arrives in.
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : null;

        // Roles are per-organization — no operator-org restriction. Global reach is the
        // separate super-admin flag.
        return [
            // `sometimes` keeps partial updates working (e.g. a role-only change from a
            // dropdown) while the full edit form still validates name/email/home org.
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'email' => [
                'sometimes', 'required', 'email', 'max:190',
                new UniqueEmail($userId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_super_admin' => ['sometimes', 'boolean'],
            'organization_id' => ['sometimes', 'required', 'uuid', 'exists:organizations,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->movesRecoveryAddress() && ! $this->couldCarryTheGate()) {
                $validator->errors()->add(
                    'email',
                    'Die E-Mail-Adresse kann über einen API-Key nicht geändert werden — das Passwort lässt sich hier nicht erneut bestätigen. Nutze dafür die Weboberfläche.',
                );
            }
        });
    }

    /**
     * Whether this request would point an account's password-reset channel somewhere else.
     *
     * Same "unreadable counts as a change" rule as ConfirmPasswordOnEmailChange: a payload
     * the comparison cannot read must never resolve to "unchanged" on a gate's side of the
     * decision.
     */
    private function movesRecoveryAddress(): bool
    {
        $target = $this->route('user');

        if (! $target instanceof User || ! $this->has('email')) {
            return false;
        }

        $submitted = $this->input('email');

        if (! is_string($submitted)) {
            return true;
        }

        return Str::lower(trim($submitted)) !== Str::lower((string) $target->email);
    }

    /**
     * Whether a password confirmation could exist for this request at all.
     *
     * `PUT /api/v1/users/{user}` shares this form request with the web directory, and it is
     * reachable with nothing but a leaked super-admin `write` API key: AuthenticateApiKey
     * admits any non-GET and calls Auth::setUser(), and RequirePassword reads a *session*
     * key, so ConfirmPasswordOnEmailChange can never engage there. That combination turns a
     * revocable key into permanent ownership of any account on the instance — move the
     * address, request a reset, and the attacker still holds the account after the key is
     * revoked, because what they hold now is the password.
     *
     * There is no honest gate to put on a stateless surface, so the field is refused there
     * instead. Everything else about the endpoint is unchanged; roles, the super-admin flag
     * and the home organization stay writable, because none of them survives revocation.
     * Session-bearing requests fall through to the middleware, which is the real gate.
     */
    private function couldCarryTheGate(): bool
    {
        return $this->hasSession() && ! $this->attributes->has('apiKey');
    }
}
