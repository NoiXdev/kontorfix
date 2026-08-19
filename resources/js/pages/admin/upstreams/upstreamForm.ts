import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

export interface UpstreamFormData {
    group_id: string;
    type: 'composer' | 'npm' | 'python';
    url: string;
    policy: 'proxy' | 'strict';
    // Blank keeps the stored token on edit; a value replaces it. Never pre-filled from the
    // server (see UpstreamController::edit()) — matching the dialog this replaces.
    auth_token: string;
    remove_auth_token: boolean;
    // `string | number`: `Input.vue` doesn't implement `v-model.number` coercion (it never
    // reads `modelModifiers`), so the field is always a string once the user edits it — the
    // `number` default reflects only the initial value. Laravel's `integer` rule accepts
    // either form the same way, so this changes nothing about what gets submitted.
    priority: string | number;
    allowed_packages_text: string;
}

// Create.vue / Edit.vue own the Inertia form — they still need `form.processing` and
// `form.post(...)`/`form.put(...)` for their own submit buttons — and `provide` it under
// this key. Form.vue `inject`s it instead of receiving it as a prop, so writing into its
// fields (`v-model="form.xxx"`) is never prop mutation: the repo-wide `vue/no-mutating-props`
// rule stays at its default everywhere, including here.
export const upstreamFormKey: InjectionKey<InertiaForm<UpstreamFormData>> = Symbol('upstream-form');

/**
 * The store/update payload shape is identical for create and edit (the dialog this
 * replaces used one `submit()` for both), so both pages' `form.transform()` share this
 * rather than duplicating the mapping.
 */
export function buildUpstreamPayload(data: UpstreamFormData) {
    return {
        group_id: data.group_id,
        type: data.type,
        url: data.url,
        policy: data.policy,
        auth_token: data.auth_token || null,
        remove_auth_token: data.remove_auth_token,
        priority: data.priority,
        allowed_packages:
            data.policy === 'strict'
                ? data.allowed_packages_text
                      .split('\n')
                      .map((n) => n.trim())
                      .filter((n) => n.length > 0)
                : [],
    };
}
