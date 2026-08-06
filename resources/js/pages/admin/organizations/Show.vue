<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

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
    email: string | null;
    role: string;
    is_super_admin?: boolean;
}

interface MemberRow {
    id: string;
    name: string;
    email: string | null;
    role?: string;
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
    registryTypes: { global: string[]; effective: string[]; overridden: boolean };
    registries: RegistryRow[];
    users: UserRow[];
    members: MemberRow[];
    assignableUsers: MemberRow[];
    tokens: TokenRow[];
}>();

// Per-org registry types: start from the effective set, choose within the instance ceiling.
const typesForm = useForm<{ enabled_registry_types: string[] }>({
    enabled_registry_types: [...props.registryTypes.effective],
});

function toggleRegistryType(type: string, on: boolean) {
    typesForm.enabled_registry_types = on
        ? [...new Set([...typesForm.enabled_registry_types, type])]
        : typesForm.enabled_registry_types.filter((t) => t !== type);
}

function saveRegistryTypes() {
    typesForm.put(route('admin.organizations.registry-types.update', props.organization.id), { preserveScroll: true });
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Kunden', href: '/admin/organizations' },
    { title: props.organization.name, href: route('admin.organizations.show', props.organization.id) },
];

const roleOptions = [
    { value: 'admin', label: 'Admin' },
    { value: 'maintainer', label: 'Maintainer' },
    { value: 'member', label: 'Member' },
];

const addUserId = ref('');
const addRole = ref('member');

function attachMember() {
    if (!addUserId.value) {
        return;
    }
    router.post(
        route('admin.organizations.members.store', props.organization.id),
        { user_id: addUserId.value, role: addRole.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                addUserId.value = '';
                addRole.value = 'member';
            },
        },
    );
}

function detachMember(userId: string) {
    router.delete(route('admin.organizations.members.destroy', [props.organization.id, userId]), { preserveScroll: true });
}
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
                <button
                    type="button"
                    class="ml-auto text-sm text-muted-foreground underline-offset-4 hover:underline"
                    @click="router.get(route('admin.activity.index'), { subject_type: 'Organization', subject_id: props.organization.id })"
                >
                    Aktivität ansehen
                </button>
            </div>

            <section class="flex flex-col gap-3">
                <div>
                    <h2 class="text-lg font-medium">Registry-Typen</h2>
                    <p class="text-sm text-muted-foreground">
                        Welche Registry-Typen diese Organisation anbietet — nur innerhalb der instanzweit erlaubten Typen.
                        Deaktivierte Typen liefern für die Registries dieser Org kein Protokoll aus.
                    </p>
                </div>
                <form class="flex flex-wrap items-center gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border" @submit.prevent="saveRegistryTypes">
                    <label v-for="type in props.registryTypes.global" :key="type" class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            class="size-4 rounded border-input"
                            :checked="typesForm.enabled_registry_types.includes(type)"
                            @change="toggleRegistryType(type, ($event.target as HTMLInputElement).checked)"
                        />
                        <span class="font-mono">{{ type }}</span>
                    </label>
                    <span v-if="props.registryTypes.global.length === 0" class="text-sm text-muted-foreground">
                        Instanzweit sind aktuell keine Registry-Typen aktiviert.
                    </span>
                    <Button type="submit" :disabled="typesForm.processing" class="ml-auto">Speichern</Button>
                </form>
            </section>

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
                                <td class="px-4 py-3">
                                    <span class="flex items-center gap-2">
                                        {{ user.name }}
                                        <span
                                            v-if="user.is_super_admin"
                                            class="inline-flex items-center rounded-md border border-verdigris/40 bg-verdigris/15 px-2 py-0.5 text-xs font-medium text-verdigris"
                                        >
                                            Super-Admin
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ user.email ?? '—' }}</td>
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
                <div>
                    <h2 class="text-lg font-medium">Weitere Mitglieder</h2>
                    <p class="text-sm text-muted-foreground">
                        Nutzer anderer Organisationen, die zusätzlich Zugriff auf die Registries dieser Organisation haben.
                    </p>
                </div>

                <form class="flex flex-wrap items-end gap-2" @submit.prevent="attachMember">
                    <SearchableSelect
                        v-model="addUserId"
                        class="min-w-64"
                        placeholder="Nutzer hinzufügen …"
                        :options="props.assignableUsers.map((u) => ({ value: u.id, label: u.email ? `${u.name} (${u.email})` : u.name }))"
                    />
                    <SearchableSelect v-model="addRole" class="w-40" :options="roleOptions" />
                    <Button type="submit" variant="outline" :disabled="!addUserId">Hinzufügen</Button>
                </form>

                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">E-Mail</th>
                                <th class="px-4 py-3 font-medium">Rolle</th>
                                <th class="px-4 py-3 font-medium">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="member in props.members"
                                :key="member.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3">{{ member.name }}</td>
                                <td class="px-4 py-3">{{ member.email ?? '—' }}</td>
                                <td class="px-4 py-3">{{ member.role ?? 'member' }}</td>
                                <td class="px-4 py-3">
                                    <Button variant="ghost" size="icon" aria-label="Zugriff entziehen" @click="detachMember(member.id)">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="props.members.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Keine weiteren Mitglieder.</td>
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
