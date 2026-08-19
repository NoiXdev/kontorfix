<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import Form from './Form.vue';
import { buildUpstreamPayload, upstreamFormKey, type UpstreamFormData } from './upstreamForm';

interface GroupOption {
    id: string;
    name: string;
}

const props = defineProps<{
    groups: GroupOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Upstreams', href: route('admin.upstreams.index') },
    { title: 'Hinzufügen', href: route('admin.upstreams.create') },
];

const form = useForm<UpstreamFormData>({
    group_id: '',
    type: 'composer',
    url: '',
    policy: 'proxy',
    auth_token: '',
    remove_auth_token: false,
    priority: 0,
    allowed_packages_text: '',
});

provide(upstreamFormKey, form);

function submit() {
    form.transform(buildUpstreamPayload).post(route('admin.upstreams.store'));
}
</script>

<template>
    <Head title="Upstream hinzufügen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Upstream hinzufügen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :groups="props.groups" mode="create" />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.upstreams.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
