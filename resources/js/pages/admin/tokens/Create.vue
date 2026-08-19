<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import Form from './Form.vue';
import { tokenFormKey, type TokenFormData } from './tokenForm';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tokens', href: route('admin.tokens.index') },
    { title: 'Erstellen', href: route('admin.tokens.create') },
];

const form = useForm<TokenFormData>({
    name: '',
    organization_id: '',
    group_id: '',
    ability: 'read',
});

provide(tokenFormKey, form);

function submit() {
    form.post(route('admin.tokens.store'));
}
</script>

<template>
    <Head title="Token erstellen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Token erstellen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :organizations="props.organizations" :groups="props.groups" />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.tokens.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Erstellen</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
