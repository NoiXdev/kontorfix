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

interface UpstreamRecord {
    id: string;
    group_id: string;
    type: 'composer' | 'npm' | 'python';
    url: string;
    policy: 'proxy' | 'strict';
    priority: number;
    has_auth: boolean;
    allowed_packages: string[];
}

const props = defineProps<{
    upstream: UpstreamRecord;
    groups: GroupOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Upstreams', href: route('admin.upstreams.index') },
    { title: 'Bearbeiten', href: route('admin.upstreams.edit', props.upstream.id) },
];

const form = useForm<UpstreamFormData>({
    group_id: props.upstream.group_id,
    type: props.upstream.type,
    url: props.upstream.url,
    policy: props.upstream.policy,
    // Never pre-filled — the stored token itself never leaves the server (see
    // UpstreamController::edit()). Blank keeps it; a value replaces it.
    auth_token: '',
    remove_auth_token: false,
    priority: props.upstream.priority,
    allowed_packages_text: props.upstream.allowed_packages.join('\n'),
});

provide(upstreamFormKey, form);

function submit() {
    form.transform(buildUpstreamPayload).put(route('admin.upstreams.update', props.upstream.id));
}
</script>

<template>
    <Head title="Upstream bearbeiten" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Upstream bearbeiten</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :groups="props.groups" mode="edit" :has-auth="props.upstream.has_auth" />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.upstreams.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
