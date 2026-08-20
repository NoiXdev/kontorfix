<?php

namespace App\Http\Requests\Admin;

use App\Enums\NotificationEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRecipientRequest extends FormRequest
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
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('notification_recipients')->where(
                    fn ($q) => $q->where('organization_id', $this->user()->organization_id),
                ),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'events' => ['array'],
            // Enum-backed rather than a hand-written list: adding a case to NotificationEvent
            // must not require remembering to widen a validation rule.
            'events.*' => [Rule::enum(NotificationEvent::class)],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
