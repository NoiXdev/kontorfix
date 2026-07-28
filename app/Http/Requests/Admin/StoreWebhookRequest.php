<?php

namespace App\Http\Requests\Admin;

use App\Enums\WebhookEvent;
use App\Services\Upstream\UrlSafety;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'url' => [
                'required', 'string', 'max:500', 'url:https,http', 'starts_with:https://,http://',
                // Immediate feedback for obviously internal targets (private/reserved IPs,
                // loopback, link-local, cloud metadata). The authoritative check happens
                // at delivery time in DeliverWebhook — here it's just early feedback for the admin UI.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! UrlSafety::isSafeResolving($value)) {
                        $fail('Ziel-URL nicht erlaubt (interne/reservierte Adresse).');
                    }
                },
            ],
            'secret' => ['nullable', 'string', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::enum(WebhookEvent::class)],
        ];
    }
}
