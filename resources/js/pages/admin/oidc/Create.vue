<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import Form from './Form.vue';
import { oidcFormKey, type OidcFormData } from './oidcForm';

interface OrganizationOption {
    id: string;
    name: string;
}

const props = defineProps<{
    organizations: OrganizationOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'OIDC-Provider', href: route('admin.oidc.index') },
    { title: 'Hinzufügen', href: route('admin.oidc.create') },
];

const form = useForm<OidcFormData>({
    name: '',
    slug: '',
    client_id: '',
    client_secret: '',
    issuer: '',
    authorization_endpoint: '',
    token_endpoint: '',
    userinfo_endpoint: '',
    jwks_uri: '',
    scopes: 'openid email profile',
    enabled: false,
    allow_registration: false,
    trusts_email_claim: false,
    default_organization_id: '',
    default_role: 'member',
});

provide(oidcFormKey, form);

function submit() {
    form.transform((data) => ({
        ...data,
        userinfo_endpoint: data.userinfo_endpoint || null,
        default_organization_id: data.default_organization_id || null,
    })).post(route('admin.oidc.store'));
}
</script>

<template>
    <Head title="OIDC-Provider hinzufügen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">OIDC-Provider hinzufügen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :organizations="props.organizations" />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.oidc.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
