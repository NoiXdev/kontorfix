import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

export interface RecipientFormData {
    email: string;
    name: string;
    events: string[];
    enabled: boolean;
}

// Create.vue owns the Inertia form — it still needs `form.processing` and `form.post(...)`
// for its own submit button — and `provide`s it under this key. Form.vue `inject`s it
// instead of receiving it as a prop, so writing into its fields (`v-model="form.xxx"`) is
// never prop mutation: the repo-wide `vue/no-mutating-props` rule stays at its default
// everywhere, including here. Mirrors webhookForm.ts's webhookFormKey.
export const recipientFormKey: InjectionKey<InertiaForm<RecipientFormData>> = Symbol('notification-recipient-form');
