<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide } from 'vue';
import Form from './Form.vue';
import { userFormKey, type UserFormData } from './userForm';

interface OrganizationOption {
    id: string;
    name: string;
}

interface Membership {
    id: string;
    name: string;
    role: string;
}

interface UserRecord {
    id: string;
    name: string;
    email: string | null;
    role: string;
    is_super_admin: boolean;
    organization_id: string | null;
    memberships: Membership[];
}

const props = defineProps<{
    user: UserRecord;
    organizations: OrganizationOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Nutzer', href: '/admin/users' },
    { title: 'Bearbeiten', href: route('admin.users.edit', props.user.id) },
];

const roleOptions = [
    { value: 'admin', label: 'Admin' },
    { value: 'maintainer', label: 'Maintainer' },
    { value: 'member', label: 'Member' },
];

const form = useForm<UserFormData>({
    name: props.user.name,
    email: props.user.email ?? '',
    role: props.user.role,
    is_super_admin: props.user.is_super_admin,
    organization_id: props.user.organization_id ?? '',
});

provide(userFormKey, form);

function submit() {
    form.put(route('admin.users.update', props.user.id));
}
</script>

<template>
    <Head title="Nutzer bearbeiten" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Nutzer bearbeiten</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form
                        :organizations="props.organizations"
                        :role-options="roleOptions"
                        mode="edit"
                        :user-id="props.user.id"
                        :memberships="props.user.memberships"
                        :home-organization-id="props.user.organization_id"
                    />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.users.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
