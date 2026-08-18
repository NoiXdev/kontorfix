<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Mail, Pencil, Plus, ScrollText, ShieldCheck, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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

// --- Create ---
const dialogOpen = ref(false);
const mode = ref<'invite' | 'password'>('invite');

const form = useForm({
    name: '',
    email: '',
    organization_id: '',
    role: 'member',
    is_super_admin: false,
    password: '',
});

function setMode(next: 'invite' | 'password') {
    mode.value = next;
    if (next === 'invite') {
        form.password = '';
    }
}

function submit() {
    form.transform((data) => {
        if (mode.value === 'invite') {
            const payload = { ...data };
            delete (payload as Partial<typeof data>).password;
            return payload;
        }
        return data;
    }).post(route('admin.users.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
            mode.value = 'invite';
        },
    });
}

// Role for a newly attached additional-org membership.
const roleForNewMembership = ref('member');

// --- Edit (name / email / role / home org) ---
const editOpen = ref(false);
const editUser = ref<UserRow | null>(null);
const editForm = useForm({
    name: '',
    email: '',
    role: 'member',
    is_super_admin: false,
    organization_id: '',
});
const addOrgId = ref('');

function openEdit(user: UserRow) {
    editUser.value = user;
    editForm.clearErrors();
    editForm.name = user.name;
    editForm.email = user.email ?? '';
    editForm.role = user.role;
    editForm.is_super_admin = user.is_super_admin;
    editForm.organization_id = user.organization_id ?? '';
    addOrgId.value = '';
    roleForNewMembership.value = 'member';
    editOpen.value = true;
}

function submitEdit() {
    if (!editUser.value) {
        return;
    }
    editForm.put(route('admin.users.update', editUser.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

// Organizations still assignable to the edited user: not the home org, not already a member.
const assignableOrgs = computed(() => {
    if (!editUser.value) {
        return [];
    }
    const taken = new Set([editUser.value.organization_id, ...editUser.value.memberships.map((m) => m.id)]);
    return props.organizations.filter((o) => !taken.has(o.id));
});

function attachOrg() {
    if (!editUser.value || !addOrgId.value) {
        return;
    }
    router.post(
        route('admin.users.organizations.store', editUser.value.id),
        { organization_id: addOrgId.value, role: roleForNewMembership.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                addOrgId.value = '';
                roleForNewMembership.value = 'member';
                editOpen.value = false;
            },
        },
    );
}

function detachOrg(userId: string, orgId: string) {
    router.delete(route('admin.users.organizations.destroy', [userId, orgId]), {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
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
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Nutzer anlegen
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
                                <Button variant="ghost" size="sm" @click="openEdit(user)">
                                    <Pencil class="size-4" />
                                    Bearbeiten
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

        <!-- Create -->
        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nutzer anlegen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" placeholder="Vor- und Nachname" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">E-Mail</Label>
                        <Input id="email" type="email" v-model="form.email" placeholder="name@kunde.de" autocomplete="off" />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="organization_id">Organisation</Label>
                        <SearchableSelect id="organization_id" v-model="form.organization_id" :options="orgOptions" placeholder="Bitte wählen" />
                        <InputError :message="form.errors.organization_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">Rolle</Label>
                        <SearchableSelect id="role" v-model="form.role" :options="roleOptions" />
                        <p class="text-xs text-muted-foreground">Rolle innerhalb der Heim-Organisation.</p>
                        <InputError :message="form.errors.role" />
                    </div>

                    <label class="flex items-start gap-2 rounded-md border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
                        <input type="checkbox" v-model="form.is_super_admin" class="mt-1" />
                        <span>
                            Super-Admin
                            <span class="block text-xs text-muted-foreground">
                                Voller Zugriff auf alle Organisationen und die Instanz-Verwaltung.
                            </span>
                        </span>
                    </label>
                    <InputError :message="form.errors.is_super_admin" />

                    <div class="grid gap-2">
                        <Label>Zugang</Label>
                        <div class="flex flex-col gap-2">
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="mode"
                                    value="invite"
                                    :checked="mode === 'invite'"
                                    @change="setMode('invite')"
                                    class="mt-1"
                                />
                                <span>
                                    Einladung per E-Mail senden
                                    <span class="block text-xs text-muted-foreground">
                                        Der Nutzer bekommt eine E-Mail mit einem Link, um sein Passwort selbst zu setzen.
                                    </span>
                                </span>
                            </label>
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="mode"
                                    value="password"
                                    :checked="mode === 'password'"
                                    @change="setMode('password')"
                                    class="mt-1"
                                />
                                <span>Passwort direkt setzen</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="mode === 'password'" class="grid gap-2">
                        <Label for="password">Passwort</Label>
                        <Input id="password" type="password" v-model="form.password" autocomplete="new-password" />
                        <InputError :message="form.errors.password" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Edit -->
        <Dialog v-model:open="editOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nutzer bearbeiten</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submitEdit">
                    <div class="grid gap-2">
                        <Label for="edit_name">Name</Label>
                        <Input id="edit_name" v-model="editForm.name" autocomplete="off" />
                        <InputError :message="editForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit_email">E-Mail</Label>
                        <Input id="edit_email" type="email" v-model="editForm.email" autocomplete="off" />
                        <InputError :message="editForm.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit_org">Heim-Organisation</Label>
                        <SearchableSelect id="edit_org" v-model="editForm.organization_id" :options="orgOptions" />
                        <InputError :message="editForm.errors.organization_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit_role">Rolle</Label>
                        <SearchableSelect id="edit_role" v-model="editForm.role" :options="roleOptions" />
                        <p class="text-xs text-muted-foreground">Rolle innerhalb der Heim-Organisation.</p>
                        <InputError :message="editForm.errors.role" />
                        <InputError :message="editForm.errors.user" />
                    </div>

                    <label class="flex items-start gap-2 rounded-md border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
                        <input type="checkbox" v-model="editForm.is_super_admin" class="mt-1" />
                        <span>
                            Super-Admin
                            <span class="block text-xs text-muted-foreground">
                                Voller Zugriff auf alle Organisationen und die Instanz-Verwaltung.
                            </span>
                        </span>
                    </label>
                    <InputError :message="editForm.errors.is_super_admin" />

                    <div v-if="editUser" class="grid gap-2 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                        <Label>Weitere Organisationen</Label>
                        <p class="text-xs text-muted-foreground">Zusätzlicher Zugriff mit eigener Rolle je Organisation.</p>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="m in editUser.memberships"
                                :key="m.id"
                                class="inline-flex items-center gap-1 rounded-md border border-copper/30 bg-copper/10 px-2 py-0.5 text-xs text-copper-hi"
                            >
                                {{ m.name }}
                                <span class="rounded bg-copper/20 px-1 text-[10px] tracking-wide uppercase">{{ m.role }}</span>
                                <button type="button" class="hover:text-destructive" aria-label="Entfernen" @click="detachOrg(editUser.id, m.id)">
                                    <X class="size-3" />
                                </button>
                            </span>
                            <span v-if="editUser.memberships.length === 0" class="text-xs text-muted-foreground">Keine weiteren.</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <SearchableSelect
                                v-model="addOrgId"
                                class="flex-1"
                                :options="assignableOrgs.map((o) => ({ value: o.id, label: o.name }))"
                                placeholder="Organisation hinzufügen …"
                            />
                            <SearchableSelect v-model="roleForNewMembership" class="w-40" :options="roleOptions" />
                            <Button type="button" variant="outline" :disabled="!addOrgId" @click="attachOrg">Hinzufügen</Button>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="editOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="editForm.processing">Speichern</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
