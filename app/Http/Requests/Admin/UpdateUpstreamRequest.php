<?php

namespace App\Http\Requests\Admin;

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Models\Upstream;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUpstreamRequest extends FormRequest
{
    /**
     * Authorization cannot be left to the controller here, the way it is for every other
     * request in this namespace: `withValidator()` below reads the route-bound upstream's
     * `auth_token`, and Laravel resolves the FormRequest before the controller's
     * `assertAdministersOrg` ever runs. A foreign tenant would get a 422 naming
     * `auth_token` when the upstream holds a mirror credential and a 403 when it does
     * not — one bit about someone else's upstream, for free.
     */
    public function authorize(): bool
    {
        $upstream = $this->route('upstream');

        return $upstream instanceof Upstream
            && $this->user()?->administers($upstream->group?->organization_id) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PackageType::class)],
            'url' => ['required', 'string', 'max:500', 'url:https,http', 'starts_with:https://,http://'],
            'policy' => ['required', Rule::enum(UpstreamPolicy::class)],
            // Blank keeps the stored token; a value replaces it. `remove_auth_token`
            // explicitly clears it (a private upstream becoming public).
            'auth_token' => ['nullable', 'string', 'max:500'],
            'remove_auth_token' => ['sometimes', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'enabled' => ['sometimes', 'boolean'],
            'allowed_packages' => ['array'],
            'allowed_packages.*' => ['string', 'max:190'],
        ];
    }

    /**
     * The stored mirror credential belongs to the host it was entered for. Moving the
     * upstream to a different host while keeping that token would hand the secret to the
     * new host on the next metadata request — a credential nobody can read back through
     * the application. Rather than clearing it silently (which turns an exfiltration
     * attempt into an unexplained sync failure), the move is refused: the operator must
     * supply a token for the new host or drop the old one explicitly.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $upstream = $this->route('upstream');
            if (! $upstream instanceof Upstream || $upstream->auth_token === null) {
                return;
            }

            $keepsToken = ! $this->filled('auth_token') && ! $this->boolean('remove_auth_token');
            if ($keepsToken && ! $this->sameHost((string) $this->input('url'), (string) $upstream->url)) {
                $validator->errors()->add(
                    'auth_token',
                    'Beim Wechsel des Upstream-Hosts muss der gespeicherte Token neu gesetzt oder entfernt werden.',
                );
            }
        });
    }

    /**
     * Host plus port, matching how UpstreamClient decides whether a request still
     * belongs to the same upstream. The scheme is deliberately ignored so upgrading an
     * existing upstream from http to https stays frictionless.
     */
    private function sameHost(string $a, string $b): bool
    {
        return $this->hostKey($a) === $this->hostKey($b);
    }

    private function hostKey(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);

        return $port !== null ? $host.':'.$port : $host;
    }
}
