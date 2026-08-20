<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import Form from './Form.vue';
import { recipientFormKey, type RecipientFormData } from './recipientForm';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Benachrichtigungsempfänger', href: route('admin.notification-recipients.index') },
    { title: 'Hinzufügen', href: route('admin.notification-recipients.create') },
];

const form = useForm<RecipientFormData>({
    email: '',
    name: '',
    events: [],
    enabled: true,
});

provide(recipientFormKey, form);

function submit() {
    form.transform((data) => ({ email: data.email, name: data.name || null, events: data.events, enabled: data.enabled })).post(
        route('admin.notification-recipients.store'),
    );
}
</script>

<template>
    <Head title="Empfänger hinzufügen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Empfänger hinzufügen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.notification-recipients.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
