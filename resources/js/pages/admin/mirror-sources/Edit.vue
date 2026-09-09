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

interface MirrorSourceRecord {
    id: string;
    name: string;
    type: string;
    url: string;
    organization_id: string | null;
}

const props = defineProps<{
    source: MirrorSourceRecord;
    organizations: OrganizationOption[];
    types: TypeOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Mirror-Quellen', href: route('admin.mirror-sources.index') },
    { title: 'Bearbeiten', href: route('admin.mirror-sources.edit', props.source.id) },
];

const form = useForm<MirrorSourceFormData>({
    name: props.source.name,
    organization_id: props.source.organization_id,
    type: props.source.type,
    url: props.source.url,
    // Never pre-filled — the stored token itself never leaves the server (see
    // MirrorSourceController::edit()). Blank keeps it; a value replaces it.
    auth_token: '',
});

provide(mirrorSourceFormKey, form);

function submit() {
    form.put(route('admin.mirror-sources.update', props.source.id));
}
</script>

<template>
    <Head title="Quelle bearbeiten" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Quelle bearbeiten</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :organizations="props.organizations" :types="props.types" mode="edit" />

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
