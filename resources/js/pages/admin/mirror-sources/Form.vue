<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { computed, inject } from 'vue';
import { mirrorSourceFormKey } from './mirrorSourceForm';

interface OrganizationOption {
    id: string;
    name: string;
}

interface TypeOption {
    value: string;
    label: string;
}

const props = defineProps<{
    organizations: OrganizationOption[];
    types: TypeOption[];
    mode: 'create' | 'edit';
}>();

// Provided by Create.vue / Edit.vue (see mirrorSourceForm.ts) rather than passed as a prop:
// this form object is meant to be written into (`v-model="form.xxx"`), and an injected value
// is not subject to Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(mirrorSourceFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via mirrorSourceFormKey — see Create.vue / Edit.vue.');
}
const form = injectedForm;

const typeOptions = computed(() => props.types.map((t) => ({ value: t.value, label: t.label })));
const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

// The organization picker only renders while creating (never editing, where the source's
// organization is fixed and the field isn't shown), so this always reads back a plain
// string — the fallback here never actually fires.
const createOrgId = computed({
    get: () => form.organization_id ?? '',
    set: (value: string) => (form.organization_id = value),
});
</script>

<template>
    <div class="grid gap-2">
        <Label for="mirror_name">Name</Label>
        <Input id="mirror_name" v-model="form.name" placeholder="z. B. Packagist Spiegel" autocomplete="off" />
        <InputError :message="form.errors.name" />
    </div>

    <div v-if="mode === 'create' && orgOptions.length > 1" class="grid gap-2">
        <Label for="mirror_org">Organisation</Label>
        <SearchableSelect id="mirror_org" v-model="createOrgId" :options="orgOptions" />
        <InputError :message="form.errors.organization_id" />
    </div>

    <div class="grid gap-2">
        <Label for="mirror_type">Typ</Label>
        <SearchableSelect id="mirror_type" v-model="form.type" :options="typeOptions" />
        <InputError :message="form.errors.type" />
    </div>

    <div class="grid gap-2">
        <Label for="mirror_url">Registry-URL</Label>
        <Input id="mirror_url" v-model="form.url" placeholder="https://repo.example.com" autocomplete="off" class="font-mono" />
        <InputError :message="form.errors.url" />
    </div>

    <div class="grid gap-2">
        <Label for="mirror_token">Token{{ mode === 'edit' ? ' (leer lassen = unverändert)' : ' (optional)' }}</Label>
        <Input id="mirror_token" v-model="form.auth_token" type="password" placeholder="leer = keine Authentifizierung" autocomplete="off" class="font-mono" />
        <InputError :message="form.errors.auth_token" />
    </div>
</template>
