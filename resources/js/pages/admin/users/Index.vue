<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Mail, Pencil, Plus, ScrollText, ShieldCheck, Trash2, X } from 'lucide-vue-next';
import { computed } from 'vue';

interface Membership {
    id: string;
    name: string;
    role: string;
}

interface UserRow {
    id: string;
    name: string;
    email: string | null;
    role: string;
    is_super_admin: boolean;
    organization_id: string | null;
    organization: string | null;
    memberships: Membership[];
}

interface OrganizationOption {
    id: string;
    name: string;
}

const props = defineProps<{
    users: UserRow[];
    organizations: OrganizationOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Nutzer', href: '/admin/users' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const roleOptions = [
    { value: 'admin', label: 'Admin' },
    { value: 'maintainer', label: 'Maintainer' },
    { value: 'member', label: 'Member' },
];

const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

const columns: ColumnDef<UserRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'E-Mail' },
    { key: 'organization', label: 'Organisation' },
    { key: 'memberships', label: 'Weitere Orgs', sortable: false },
    { key: 'role', label: 'Rolle' },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

// Real role values come from UserRole (app/Enums/UserRole.php) as put into `role` by
// UserController::index: admin, maintainer, member — reusing roleOptions above rather
// than inventing a second admin/member-only list.
const table = useTableState<UserRow>({
    rows: () => props.users,
    columns,
    searchKeys: ['name', 'email'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        org: {
            label: 'Organisation',
            options: orgOptions.value,
            match: (row, value) => row.organization_id === value,
        },
        role: {
            label: 'Rolle',
            options: roleOptions,
            match: (row, value) => row.role === value,
        },
    },
});

function detachOrg(userId: string, orgId: string) {
    router.delete(route('admin.users.organizations.destroy', [userId, orgId]), { preserveScroll: true });
}

function sendInvite(id: string) {
    router.post(route('admin.users.invite', id), {}, { preserveScroll: true });
}

function changeRole(id: string, role: string) {
    router.put(route('admin.users.update', id), { role }, { preserveScroll: true });
}

function destroyUser(id: string) {
    router.delete(route('admin.users.destroy', id), {
        onBefore: () => confirm('Nutzer wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Nutzer" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Nutzer</h1>
                <Button as-child>
                    <Link :href="route('admin.users.create')">
                        <Plus class="size-4" />
                        Nutzer anlegen
                    </Link>
                </Button>
            </div>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Nutzer angelegt.">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.org.value"
                        :options="orgOptions"
                        placeholder="Organisation"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('org', String(v))"
                    />
                    <SearchableSelect
                        :model-value="table.filterValues.role.value"
                        :options="roleOptions"
                        placeholder="Rolle"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('role', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <tr
                        v-for="user in rows"
                        :key="user.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3 font-medium">
                            <span class="flex items-center gap-2">
                                {{ user.name }}
                                <span
                                    v-if="user.is_super_admin"
                                    class="inline-flex items-center gap-1 rounded-md border border-verdigris/40 bg-verdigris/15 px-2 py-0.5 text-xs font-medium text-verdigris"
                                >
                                    <ShieldCheck class="size-3" />
                                    Super-Admin
                                </span>
                            </span>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ user.email ?? '—' }}</td>
                        <td class="px-4 py-3">{{ user.organization ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="m in user.memberships"
                                    :key="m.id"
                                    class="inline-flex items-center gap-1 rounded-md border border-copper/30 bg-copper/10 px-2 py-0.5 text-xs text-copper-hi"
                                >
                                    {{ m.name }}
                                    <span class="rounded bg-copper/20 px-1 text-[10px] tracking-wide uppercase">{{ m.role }}</span>
                                    <button
                                        type="button"
                                        class="hover:text-destructive"
                                        aria-label="Organisation entfernen"
                                        @click="detachOrg(user.id, m.id)"
                                    >
                                        <X class="size-3" />
                                    </button>
                                </span>
                                <span v-if="user.memberships.length === 0" class="text-xs text-muted-foreground">—</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <SearchableSelect
                                :model-value="user.role"
                                :options="roleOptions"
                                @update:model-value="(v) => changeRole(user.id, String(v))"
                            />
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="route('admin.users.edit', user.id)">
                                        <Pencil class="size-4" />
                                        Bearbeiten
                                    </Link>
                                </Button>
                                <Button variant="ghost" size="icon" @click="sendInvite(user.id)" aria-label="Einladung senden">
                                    <Mail class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Aktivität ansehen"
                                    @click="router.get(route('admin.activity.index'), { causer: user.id })"
                                >
                                    <ScrollText class="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon" @click="destroyUser(user.id)" aria-label="Nutzer löschen">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
