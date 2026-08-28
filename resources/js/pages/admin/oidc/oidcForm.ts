import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

export type OidcRole = 'member' | 'maintainer' | 'admin';

export interface OidcFormData {
    name: string;
    slug: string;
    client_id: string;
    client_secret: string;
    issuer: string;
    authorization_endpoint: string;
    token_endpoint: string;
    userinfo_endpoint: string;
    jwks_uri: string;
    scopes: string;
    enabled: boolean;
    allow_registration: boolean;
    trusts_email_claim: boolean;
    default_organization_id: string;
    default_role: OidcRole;
}

// Create.vue owns the Inertia form — it still needs `form.processing` and `form.post(...)`
// for its own submit button — and `provide`s it under this key. Form.vue `inject`s it
// instead of receiving it as a prop, so writing into its fields (`v-model="form.xxx"`) is
// never prop mutation: the repo-wide `vue/no-mutating-props` rule stays at its default
// everywhere, including here.
export const oidcFormKey: InjectionKey<InertiaForm<OidcFormData>> = Symbol('oidc-form');
