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

const props = defineProps<{
    organizations: OrganizationOption[];
    providers: ProviderOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Git-Tokens', href: route('admin.git-credentials.index') },
    { title: 'Hinzufügen', href: route('admin.git-credentials.create') },
];

const form = useForm<GitCredentialFormData>({
    name: '',
    organization_id: props.organizations[0]?.id ?? '',
    provider: 'github',
    host: '',
    username: '',
    token: '',
});

provide(gitCredentialFormKey, form);

function submit() {
    form.post(route('admin.git-credentials.store'));
}
</script>

<template>
    <Head title="Git-Token hinterlegen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Git-Token hinterlegen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :organizations="props.organizations" :providers="props.providers" mode="create" />

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
