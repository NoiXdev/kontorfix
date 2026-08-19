<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { computed, inject } from 'vue';
import { gitCredentialFormKey } from './gitCredentialForm';

interface OrganizationOption {
    id: string;
    name: string;
}

interface ProviderOption {
    value: string;
    label: string;
    default_host: string | null;
}

const props = defineProps<{
    organizations: OrganizationOption[];
    providers: ProviderOption[];
    mode: 'create' | 'edit';
}>();

// Provided by Create.vue / Edit.vue (see gitCredentialForm.ts) rather than passed as a prop:
// this form object is meant to be written into (`v-model="form.xxx"`), and an injected value
// is not subject to Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(gitCredentialFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via gitCredentialFormKey — see Create.vue / Edit.vue.');
}
const form = injectedForm;

const providerOptions = computed(() => props.providers.map((p) => ({ value: p.value, label: p.label })));
const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

// A credential's token is only ever sent to this host. Known providers prefill it;
// self-hosted ("generic") installations must name their host explicitly.
const defaultHost = computed(() => props.providers.find((p) => p.value === form.provider)?.default_host ?? null);
const hostPlaceholder = computed(() => defaultHost.value ?? 'z. B. git.example.com');

// The organization picker only renders while creating (never editing, where the credential's
// organization is fixed and the field isn't shown), so this always reads back a plain
// string — the fallback here never actually fires.
const createOrgId = computed({
    get: () => form.organization_id ?? '',
    set: (value: string) => (form.organization_id = value),
});
</script>

<template>
    <div class="grid gap-2">
        <Label for="cred_name">Name</Label>
        <Input id="cred_name" v-model="form.name" placeholder="z. B. GitHub Deploy" autocomplete="off" />
        <InputError :message="form.errors.name" />
    </div>

    <div v-if="mode === 'create' && orgOptions.length > 1" class="grid gap-2">
        <Label for="cred_org">Organisation</Label>
        <SearchableSelect id="cred_org" v-model="createOrgId" :options="orgOptions" />
        <InputError :message="form.errors.organization_id" />
    </div>

    <div class="grid gap-2">
        <Label for="cred_provider">Provider</Label>
        <SearchableSelect id="cred_provider" v-model="form.provider" :options="providerOptions" />
        <InputError :message="form.errors.provider" />
    </div>

    <div class="grid gap-2">
        <Label for="cred_host">Host</Label>
        <Input id="cred_host" v-model="form.host" :placeholder="hostPlaceholder" autocomplete="off" />
        <p class="text-xs text-muted-foreground">Der Token wird ausschliesslich an diesen Host gesendet.</p>
        <InputError :message="form.errors.host" />
    </div>

    <div class="grid gap-2">
        <Label for="cred_username">Benutzername <span class="text-muted-foreground">(optional)</span></Label>
        <Input id="cred_username" v-model="form.username" placeholder="leer = Provider-Standard" autocomplete="off" />
        <InputError :message="form.errors.username" />
    </div>

    <div class="grid gap-2">
        <Label for="cred_token">Token{{ mode === 'edit' ? ' (leer lassen = unverändert)' : '' }}</Label>
        <Input id="cred_token" v-model="form.token" type="password" placeholder="ghp_… / glpat-… / …" autocomplete="off" class="font-mono" />
        <InputError :message="form.errors.token" />
    </div>
</template>
