import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

export type WebhookEventKey = 'package.synced' | 'sync.failed' | 'version.released';

export interface WebhookFormData {
    url: string;
    secret: string;
    events: WebhookEventKey[];
}

// Create.vue owns the Inertia form — it still needs `form.processing` and `form.post(...)`
// for its own submit button — and `provide`s it under this key. Form.vue `inject`s it
// instead of receiving it as a prop, so writing into its fields (`v-model="form.xxx"`) is
// never prop mutation: the repo-wide `vue/no-mutating-props` rule stays at its default
// everywhere, including here.
export const webhookFormKey: InjectionKey<InertiaForm<WebhookFormData>> = Symbol('webhook-form');

// The event picker stays a checkbox group, not a Switch: it's a multi-select over
// `form.events`, and a Switch says "on or off" about one thing, not "which of these".
export const webhookEventOptions: { value: WebhookEventKey; label: string }[] = [
    { value: 'package.synced', label: 'Paket synchronisiert' },
    { value: 'sync.failed', label: 'Sync fehlgeschlagen' },
    { value: 'version.released', label: 'Version veröffentlicht' },
];

export type IncomingWebhookProvider = 'github' | 'gitlab' | 'gitea' | 'bitbucket';

export interface IncomingWebhookFormData {
    name: string;
    provider: IncomingWebhookProvider;
}

// Distinct key, distinct provide/inject pair from webhookFormKey above: the incoming
// endpoint is an unrelated create flow (different fields, different controller action,
// different meaning), not a mode of the outgoing webhook form.
export const incomingWebhookFormKey: InjectionKey<InertiaForm<IncomingWebhookFormData>> = Symbol('incoming-webhook-form');

export const incomingWebhookProviderOptions: { value: IncomingWebhookProvider; label: string }[] = [
    { value: 'github', label: 'GitHub' },
    { value: 'gitlab', label: 'GitLab' },
    { value: 'gitea', label: 'Gitea' },
    { value: 'bitbucket', label: 'Bitbucket' },
];
