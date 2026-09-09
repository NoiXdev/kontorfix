<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import Form from './Form.vue';
import { mirrorSourceFormKey, type MirrorSourceFormData } from './mirrorSourceForm';

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
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Mirror-Quellen', href: route('admin.mirror-sources.index') },
    { title: 'Anlegen', href: route('admin.mirror-sources.create') },
];

const form = useForm<MirrorSourceFormData>({
    name: '',
    organization_id: props.organizations[0]?.id ?? '',
    type: props.types[0]?.value ?? '',
    url: '',
    auth_token: '',
});

provide(mirrorSourceFormKey, form);

function submit() {
    form.post(route('admin.mirror-sources.store'));
}
</script>

<template>
    <Head title="Quelle anlegen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Quelle anlegen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :organizations="props.organizations" :types="props.types" mode="create" />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.mirror-sources.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
