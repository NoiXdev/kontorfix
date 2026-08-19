import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

// `password` is optional because the edit form never carries it — the edit dialog never
// managed passwords, only create did, and moving the markup must not add a field the
// controller never validated for updates.
export interface UserFormData {
    name: string;
    email: string;
    organization_id: string;
    role: string;
    is_super_admin: boolean;
    password?: string;
}

// Create.vue / Edit.vue own the Inertia form — they still need `form.processing` and
// `form.post(...)`/`form.put(...)` for their own submit buttons — and `provide` it under this
// key. Form.vue `inject`s it instead of receiving it as a prop, so writing into its fields
// (`v-model="form.name"`) is never prop mutation: the repo-wide `vue/no-mutating-props` rule
// stays at its default everywhere, including here.
export const userFormKey: InjectionKey<InertiaForm<UserFormData>> = Symbol('user-form');
