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
 * What this deliberately does NOT close: a parent key with no expiry at all still mints
 * successors with no expiry. Bounding that needs `api_key_max_ttl_days`, which is an
 * operator decision — defaulting it to something finite would silently expire the
 * automation of every existing install.
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

        if ($parent->expires_at === null) {
            return;
        }

        if ($this->date('expires_at') === null || $this->date('expires_at')->gt($parent->expires_at)) {
            $validator->errors()->add(
                'expires_at',
                'Ein mit einem API-Key ausgestellter Key darf nicht länger gültig sein als der ausstellende Key (spätestens '.$parent->expires_at->toDateString().').',
            );
        }
    }
}
