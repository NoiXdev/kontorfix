import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

export interface TokenFormData {
    name: string;
    organization_id: string;
    group_id: string;
    ability: 'read' | 'publish';
}

// Create.vue owns the Inertia form — it still needs `form.processing` and `form.post(...)`
// for its own submit button — and `provide`s it under this key. Form.vue `inject`s it
// instead of receiving it as a prop, so writing into its fields (`v-model="form.xxx"`) is
// never prop mutation: the repo-wide `vue/no-mutating-props` rule stays at its default
// everywhere, including here.
export const tokenFormKey: InjectionKey<InertiaForm<TokenFormData>> = Symbol('token-form');
