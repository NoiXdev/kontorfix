<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { provide, ref } from 'vue';
import Form from './Form.vue';
import { userFormKey, type UserFormData } from './userForm';

interface OrganizationOption {
    id: string;
    name: string;
}

const props = defineProps<{
    organizations: OrganizationOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Nutzer', href: '/admin/users' },
    { title: 'Anlegen', href: route('admin.users.create') },
];

const roleOptions = [
    { value: 'admin', label: 'Admin' },
    { value: 'maintainer', label: 'Maintainer' },
    { value: 'member', label: 'Member' },
];

const form = useForm<UserFormData>({
    name: '',
    email: '',
    organization_id: '',
    role: 'member',
    is_super_admin: false,
    password: '',
});

provide(userFormKey, form);

const accessMode = ref<'invite' | 'password'>('invite');

function submit() {
    form.transform((data) => {
        if (accessMode.value === 'invite') {
            const payload = { ...data };
            delete (payload as Partial<typeof data>).password;
            return payload;
        }
        return data;
    }).post(route('admin.users.store'));
}
</script>

<template>
    <Head title="Nutzer anlegen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Nutzer anlegen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form :organizations="props.organizations" :role-options="roleOptions" mode="create" v-model:access-mode="accessMode" />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.users.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
