<script setup lang="ts">
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

interface RegistryRow {
    id: string;
    name: string;
    slug: string;
    packages_count: number;
    domains: string[];
}

interface UserRow {
    id: string;
    name: string;
    email: string;
    role: string;
}

interface TokenRow {
    id: string;
    name: string;
    ability: string;
    group: string | null;
}

const props = defineProps<{
    organization: {
        id: string;
        name: string;
        slug: string;
        is_operator: boolean;
    };
    registries: RegistryRow[];
    users: UserRow[];
    tokens: TokenRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Kunden', href: '/admin/organizations' },
    { title: props.organization.name, href: route('admin.organizations.show', props.organization.id) },
];
</script>

<template>
    <Head :title="props.organization.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold">{{ props.organization.name }}</h1>
                <span class="font-mono text-xs text-muted-foreground">{{ props.organization.slug }}</span>
                <span
                    :class="
                        cn(
                            'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                            props.organization.is_operator
                                ? 'border-copper/30 bg-copper/15 text-copper-hi'
                                : 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
                        )
                    "
                >
                    {{ props.organization.is_operator ? 'Betreiber' : 'Kunde' }}
                </span>
            </div>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Registries</h2>
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Slug</th>
                                <th class="px-4 py-3 font-medium">Pakete</th>
                                <th class="px-4 py-3 font-medium">Domains</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="registry in props.registries"
                                :key="registry.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3">{{ registry.name }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ registry.slug }}</td>
                                <td class="px-4 py-3">{{ registry.packages_count }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ registry.domains.join(', ') || '—' }}</td>
                            </tr>
                            <tr v-if="props.registries.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Keine Registries.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Nutzer</h2>
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">E-Mail</th>
                                <th class="px-4 py-3 font-medium">Rolle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in props.users"
                                :key="user.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3">{{ user.name }}</td>
                                <td class="px-4 py-3">{{ user.email }}</td>
                                <td class="px-4 py-3">{{ user.role }}</td>
                            </tr>
                            <tr v-if="props.users.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">Keine Nutzer.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Tokens</h2>
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Berechtigung</th>
                                <th class="px-4 py-3 font-medium">Registry</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="token in props.tokens"
                                :key="token.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3">{{ token.name }}</td>
                                <td class="px-4 py-3">{{ token.ability }}</td>
                                <td class="px-4 py-3">{{ token.group ?? '—' }}</td>
                            </tr>
                            <tr v-if="props.tokens.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">Keine Tokens.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
