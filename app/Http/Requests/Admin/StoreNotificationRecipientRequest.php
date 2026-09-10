<?php

namespace App\Http\Requests\Admin;

use App\Enums\NotificationEvent;
use App\Services\Scope\OrgScope;
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
        // The organization the row will actually be created in — mirrors
        // NotificationRecipientController::store()'s resolveCreationOrg(null) fallback
        // chain, so the uniqueness check asks about the same organization the row lands
        // in rather than always the caller's own (which differs once an active console
        // scope points elsewhere).
        $targetOrganizationId = app(OrgScope::class)->creationOrganizationId() ?: $this->user()->organization_id;

        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('notification_recipients')->where(
                    fn ($q) => $q->where('organization_id', $targetOrganizationId),
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
