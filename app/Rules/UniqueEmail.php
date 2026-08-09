<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Case-insensitive uniqueness for `users.email`.
 *
 * `unique:users,email` compares the column as stored, which is case-sensitive — so with a
 * legacy `Root@firma.de` row present it happily accepts a second account at
 * `root@firma.de`. `OidcUserResolver` then matches BOTH on `lower(email)` and picks one.
 * The two accounts are indistinguishable in every listing, which is the identity-confusion
 * primitive, and it is only the database index that makes the state impossible — an index
 * that cannot always be installed on an existing instance (see EmailUniquenessIndex). This
 * is the half that works everywhere, including where the index had to fall back.
 */
class UniqueEmail implements ValidationRule
{
    public function __construct(private ?string $ignoreUserId = null) {}

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $taken = User::query()
            ->whereRaw('lower(email) = ?', [Str::lower(trim($value))])
            ->when($this->ignoreUserId !== null, fn ($query) => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail('Diese E-Mail-Adresse wird bereits verwendet.');
        }
    }
}
