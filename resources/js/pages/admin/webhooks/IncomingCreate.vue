<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import IncomingForm from './IncomingForm.vue';
import { incomingWebhookFormKey, type IncomingWebhookFormData } from './webhookForm';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Webhooks', href: route('admin.webhooks.index') },
    { title: 'Eingehenden Endpunkt anlegen', href: route('admin.incoming-webhooks.create') },
];

const form = useForm<IncomingWebhookFormData>({
    name: '',
    provider: 'github',
});

provide(incomingWebhookFormKey, form);

function submit() {
    form.post(route('admin.incoming-webhooks.store'));
}
</script>

<template>
    <Head title="Eingehenden Endpunkt anlegen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Eingehenden Endpunkt anlegen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <IncomingForm />

                    <p class="text-xs text-muted-foreground">
                        Nach dem Anlegen werden URL und Secret einmalig angezeigt — trage beides im Git-Host als Webhook ein.
                    </p>

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
