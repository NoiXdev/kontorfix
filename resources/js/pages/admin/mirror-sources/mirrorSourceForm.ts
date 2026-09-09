import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

export interface MirrorSourceFormData {
    name: string;
    organization_id: string | null;
    type: string;
    url: string;
    // Blank keeps the stored token on edit; a value replaces it. Never pre-filled from the
    // server (see MirrorSourceController::edit()) — the token is write-only from the UI.
    // Also fine left blank on create: some mirrored registries need no auth at all.
    auth_token: string;
}

// Create.vue / Edit.vue own the Inertia form — they still need `form.processing` and
// `form.post(...)`/`form.put(...)` for their own submit buttons — and `provide` it under
// this key. Form.vue `inject`s it instead of receiving it as a prop, so writing into its
// fields (`v-model="form.xxx"`) is never prop mutation: the repo-wide `vue/no-mutating-props`
// rule stays at its default everywhere, including here.
export const mirrorSourceFormKey: InjectionKey<InertiaForm<MirrorSourceFormData>> = Symbol('mirror-source-form');
