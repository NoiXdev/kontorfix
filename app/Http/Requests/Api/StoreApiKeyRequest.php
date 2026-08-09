<?php

namespace App\Http\Requests\Api;

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by the password.confirm-gated web form and by `POST /api/v1/me/api-keys`, which
 * cannot carry that gate: `RequirePassword` reads a session key and `/api/v1` is stateless.
 *
 * The consequence is that a leaked `write` key mints its own successors, so rotating the
 * compromised key does not end the compromise — the holder keeps re-growing the list, and
 * for a robot account nobody can even enumerate the result (no route lists another user's
 * keys). The route is a documented self-service feature and a robot's only way to rotate
 * its own credential, so it is not withdrawn; instead a successor is bounded by its parent:
 * never a wider permission, never a longer life.
 *
 * The first version of that rule left the whole hole open in its most common shape: a
 * parent with `expires_at = null` returned early, so it minted null-expiry successors and
 * the chain never ended. The reasoning was that bounding it needs `api_key_max_ttl_days`,
 * and defaulting THAT to something finite would silently expire the automation of every
 * existing install. True of the ceiling — it applies to keys a human mints in the console —
 * and not true here: a successor is a key being created right now, by the route that exists
 * so a robot can rotate its credential. Giving it a finite life touches nothing that
 * already exists. So an unbounded parent falls back to `api_key_successor_max_ttl_days`
 * (90 days by default, 0 to opt out), and the chain has to be renewed rather than inherited.
 *
 * The human path is untouched: `clampToParentKey()` only fires when a key was presented as
 * the credential for this request, so the console form behind `password.confirm` may still
 * mint a perpetual key. That is the boundary — the tier that proved a password may, a key
 * minting its own successor may not.
 */
class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'permission' => ['required', Rule::enum(ApiKeyPermission::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->clampToCeiling($validator);
            $this->clampToParentKey($validator);
        });
    }

    /** The instance-wide ceiling, if the operator set one. */
    private function clampToCeiling(Validator $validator): void
    {
        $days = (int) config('kontorfix.api_key_max_ttl_days', 0);

        if ($days <= 0) {
            return;
        }

        $latest = now()->addDays($days);

        if ($this->date('expires_at') === null || $this->date('expires_at')->gt($latest)) {
            $validator->errors()->add(
                'expires_at',
                "Ein API-Key darf höchstens {$days} Tage gültig sein (spätestens {$latest->toDateString()}).",
            );
        }
    }

    /**
     * A key presented as the credential for this request is the successor's parent. The
     * successor may be neither wider nor longer-lived than it.
     */
    private function clampToParentKey(Validator $validator): void
    {
        $parent = $this->attributes->get('apiKey');

        if (! $parent instanceof ApiKey) {
            return;
        }

        if ($this->enum('permission', ApiKeyPermission::class) === ApiKeyPermission::Write
            && $parent->permission !== ApiKeyPermission::Write) {
            $validator->errors()->add(
                'permission',
                'Ein API-Key kann keinen Key mit weitergehenden Rechten ausstellen.',
            );
        }

        $ceiling = $parent->expires_at;
        $message = 'Ein mit einem API-Key ausgestellter Key darf nicht länger gültig sein als der ausstellende Key (spätestens '
            .$ceiling?->toDateString().').';

        if ($ceiling === null) {
            // A perpetual parent was a blank cheque: it minted perpetual successors, so
            // revoking the leaked key ended nothing. Fall back to the successor ceiling.
            $days = (int) config('kontorfix.api_key_successor_max_ttl_days', 90);

            if ($days <= 0) {
                return;
            }

            $ceiling = now()->addDays($days);
            $message = "Ein mit einem API-Key ausgestellter Key braucht ein Ablaufdatum von höchstens {$days} Tagen "
                ."(spätestens {$ceiling->toDateString()}) — auch wenn der ausstellende Key unbegrenzt gültig ist.";
        }

        if ($this->date('expires_at') === null || $this->date('expires_at')->gt($ceiling)) {
            $validator->errors()->add('expires_at', $message);
        }
    }
}
