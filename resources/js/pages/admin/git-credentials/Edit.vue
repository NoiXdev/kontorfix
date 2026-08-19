<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import Form from './Form.vue';
import { gitCredentialFormKey, type GitCredentialFormData } from './gitCredentialForm';

interface OrganizationOption {
    id: string;
    name: string;
}

interface ProviderOption {
    value: string;
    label: string;
    default_host: string | null;
}

interface CredentialRecord {
    id: string;
    name: string;
    provider: string;
    host: string | null;
    username: string | null;
    organization_id: string | null;
}

const props = defineProps<{
    credential: CredentialRecord;
    organizations: OrganizationOption[];
    providers: ProviderOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Git-Tokens', href: route('admin.git-credentials.index') },
    { title: 'Bearbeiten', href: route('admin.git-credentials.edit', props.credential.id) },
];

const form = useForm<GitCredentialFormData>({
    name: props.credential.name,
    organization_id: props.credential.organization_id,
    provider: props.credential.provider,
    host: props.credential.host ?? '',
    username: props.credential.username ?? '',
    // Never pre-filled — the stored token itself never leaves the server (see
    // GitCredentialController::edit()). Blank keeps it; a value replaces it.
    token: '',
});

provide(gitCredentialFormKey, form);

function submit() {
    form.put(route('admin.git-credentials.update', props.credential.id));
}
</script>

<template>
    <Head title="Git-Token bearbeiten" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Git-Token bearbeiten</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :organizations="props.organizations" :providers="props.providers" mode="edit" />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.git-credentials.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
