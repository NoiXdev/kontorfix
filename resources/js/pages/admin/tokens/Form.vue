<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { computed, inject, watch } from 'vue';
import { tokenFormKey } from './tokenForm';

interface OrganizationOption {
    id: string;
    name: string;
}

interface GroupOption {
    id: string;
    name: string;
    organization_id: string;
}

const props = defineProps<{
    organizations: OrganizationOption[];
    groups: GroupOption[];
}>();

// Provided by Create.vue (see tokenForm.ts) rather than passed as a prop: this form object is
// meant to be written into (`v-model="form.xxx"`), and an injected value is not subject to
// Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(tokenFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via tokenFormKey — see Create.vue.');
}
const form = injectedForm;

const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));
const filteredGroups = computed(() => props.groups.filter((g) => g.organization_id === form.organization_id));

watch(
    () => form.organization_id,
    () => {
        form.group_id = '';
    },
);
</script>

<template>
    <div class="grid gap-2">
        <Label for="name">Name</Label>
        <Input id="name" v-model="form.name" placeholder="kadenz-ci" autocomplete="off" />
        <InputError :message="form.errors.name" />
    </div>

    <div class="grid gap-2">
        <Label for="organization_id">Organisation</Label>
        <SearchableSelect id="organization_id" v-model="form.organization_id" placeholder="Bitte wählen" :options="orgOptions" />
        <InputError :message="form.errors.organization_id" />
    </div>

    <div class="grid gap-2">
        <Label for="group_id">Gruppe</Label>
        <SearchableSelect
            id="group_id"
            v-model="form.group_id"
            :options="[{ value: '', label: 'Alle Gruppen' }, ...filteredGroups.map((g) => ({ value: g.id, label: g.name }))]"
        />
        <InputError :message="form.errors.group_id" />
    </div>

    <div class="grid gap-2">
        <Label for="ability">Recht</Label>
        <SearchableSelect
            id="ability"
            v-model="form.ability"
            :options="[
                { value: 'read', label: 'Lesen' },
                { value: 'publish', label: 'Veröffentlichen' },
            ]"
        />
        <InputError :message="form.errors.ability" />
    </div>
</template>
