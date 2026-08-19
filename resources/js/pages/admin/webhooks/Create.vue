<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import Form from './Form.vue';
import { webhookFormKey, type WebhookFormData } from './webhookForm';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Webhooks', href: route('admin.webhooks.index') },
    { title: 'Hinzufügen', href: route('admin.webhooks.create') },
];

const form = useForm<WebhookFormData>({
    url: '',
    secret: '',
    events: [],
});

provide(webhookFormKey, form);

function submit() {
    form.transform((data) => ({ url: data.url, secret: data.secret || null, events: data.events })).post(route('admin.webhooks.store'));
}
</script>

<template>
    <Head title="Webhook hinzufügen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Webhook hinzufügen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.webhooks.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
