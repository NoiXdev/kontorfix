<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, KeyRound, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface RobotRow {
    id: string;
    name: string;
    role: string;
    is_super_admin: boolean;
    organization: string | null;
    organization_id: string | null;
    keys_count: number;
}

const props = defineProps<{
    robots: RobotRow[];
    organizations: { id: string; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Robots', href: '/admin/robots' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

// Plaintext callout: the API key is shown only once (via flash).
const plainApiKey = computed(() => page.props.flash?.plainApiKey ?? null);
const keyCalloutDismissed = ref(false);
watch(plainApiKey, (value) => {
    if (value) {
        keyCalloutDismissed.value = false;
    }
});
const showKeyCallout = computed(() => !!plainApiKey.value && !keyCalloutDismissed.value);

const keyCopied = ref(false);
async function copyKey() {
    if (!plainApiKey.value) {
        return;
    }
    try {
        await navigator.clipboard.writeText(plainApiKey.value);
        keyCopied.value = true;
        setTimeout(() => (keyCopied.value = false), 2000);
    } catch {
        // Clipboard API not available (insecure context) — the key can be selected manually.
        keyCopied.value = false;
    }
}

const roleOptions = [
    { value: 'member', label: 'Member' },
    { value: 'maintainer', label: 'Maintainer' },
    { value: 'admin', label: 'Admin' },
];

const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

const columns: ColumnDef<RobotRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'role', label: 'Rolle' },
    { key: 'is_super_admin', label: 'Scope', sortValue: (row) => (row.is_super_admin ? 'Global' : 'Organisation') },
    { key: 'organization', label: 'Organisation' },
    { key: 'keys_count', label: 'Keys', sortAs: 'number' },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<RobotRow>({
    rows: () => props.robots,
    columns,
    searchKeys: ['name'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        role: {
            label: 'Rolle',
            options: roleOptions,
            match: (row, value) => row.role === value,
        },
        org: {
            label: 'Organisation',
            options: orgOptions.value,
            match: (row, value) => row.organization_id === value,
        },
    },
});

const createForm = useForm({
    name: '',
    organization_id: '',
    role: 'member',
    is_super_admin: false,
});

function submitCreate() {
    createForm.post(route('admin.robots.store'), {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

// One inline, expandable key form per robot.
const openKeyForm = ref<string | null>(null);
const keyForm = useForm({
    name: '',
    permission: 'read' as 'read' | 'write',
});

function toggleKeyForm(id: string) {
    openKeyForm.value = openKeyForm.value === id ? null : id;
    keyForm.reset();
    keyForm.clearErrors();
}

function submitKey(id: string) {
    keyForm.post(route('admin.robots.keys.store', id), {
        preserveScroll: true,
        onSuccess: () => {
            openKeyForm.value = null;
            keyForm.reset();
        },
    });
}

function destroyRobot(id: string) {
    router.delete(route('admin.robots.destroy', id), {
        preserveScroll: true,
        onBefore: () => confirm('Robot wirklich löschen?'),
    });
}

function roleLabel(role: string) {
    return roleOptions.find((opt) => opt.value === role)?.label ?? role;
}
</script>

<template>
    <Head title="Robots" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div>
                <h1 class="text-xl font-semibold">Robots</h1>
                <p class="mt-1 text-sm text-muted-foreground">Maschinen-/Service-Accounts für die REST-API — melden sich nur per API-Key an.</p>
            </div>

            <div v-if="showKeyCallout" class="rounded-xl border border-copper/30 bg-copper/10 p-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1 space-y-2">
                        <p class="font-medium text-copper-hi">Neuer API-Key erstellt</p>
                        <p class="rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-sm break-all select-all">
                            {{ plainApiKey }}
                        </p>
                        <p class="text-sm text-muted-foreground">Dieser Key wird nur einmal angezeigt. Bewahre ihn sicher auf.</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button variant="outline" size="sm" @click="copyKey">
                            <Copy class="size-4" />
                            {{ keyCopied ? 'Kopiert!' : 'Kopieren' }}
                        </Button>
                        <Button variant="ghost" size="sm" @click="keyCalloutDismissed = true">Schließen</Button>
                    </div>
                </div>
            </div>

            <form
                class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end dark:border-sidebar-border"
                @submit.prevent="submitCreate"
            >
                <div class="grid gap-2">
                    <Label for="robot_name">Name</Label>
                    <Input id="robot_name" v-model="createForm.name" placeholder="CI-Runner" autocomplete="off" />
                    <InputError :message="createForm.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="robot_org">Organisation</Label>
                    <SearchableSelect
                        id="robot_org"
                        v-model="createForm.organization_id"
                        placeholder="Bitte wählen"
                        :options="props.organizations.map((o) => ({ value: o.id, label: o.name }))"
                    />
                    <InputError :message="createForm.errors.organization_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="robot_role">Rolle</Label>
                    <SearchableSelect id="robot_role" v-model="createForm.role" :options="roleOptions" />
                    <InputError :message="createForm.errors.role" />
                </div>

                <Button type="submit" :disabled="createForm.processing">
                    <Plus class="size-4" />
                    Robot anlegen
                </Button>

                <label class="flex items-start gap-2 text-sm sm:col-span-full">
                    <Switch v-model="createForm.is_super_admin" class="mt-1" />
                    <span>
                        Global (Super-Admin)
                        <span class="block text-xs text-muted-foreground">
                            Statt auf die gewählte Organisation begrenzt, erhält der Robot vollen Zugriff auf alle Organisationen und die
                            Instanz-Verwaltung.
                        </span>
                    </span>
                </label>
                <InputError :message="createForm.errors.is_super_admin" class="sm:col-span-full" />
            </form>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Robots angelegt.">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.role.value"
                        :options="roleOptions"
                        placeholder="Rolle"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('role', String(v))"
                    />
                    <SearchableSelect
                        :model-value="table.filterValues.org.value"
                        :options="orgOptions"
                        placeholder="Organisation"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('org', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <template v-for="robot in rows" :key="robot.id">
                        <tr class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
                            <td class="px-4 py-3 font-medium">{{ robot.name }}</td>
                            <td class="px-4 py-3">{{ roleLabel(robot.role) }}</td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="robot.is_super_admin"
                                    class="inline-flex items-center rounded-md border border-verdigris/40 bg-verdigris/15 px-2 py-0.5 text-xs font-medium text-verdigris"
                                >
                                    Global
                                </span>
                                <span v-else class="text-xs text-muted-foreground">Organisation</span>
                            </td>
                            <td class="px-4 py-3">{{ robot.organization ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ robot.keys_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1">
                                    <Button variant="ghost" size="sm" @click="toggleKeyForm(robot.id)">
                                        <KeyRound class="size-4" />
                                        Key ausstellen
                                    </Button>
                                    <Button variant="ghost" size="icon" aria-label="Robot löschen" @click="destroyRobot(robot.id)">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="openKeyForm === robot.id" class="border-b border-sidebar-border/70 bg-muted/30 dark:border-sidebar-border">
                            <td colspan="6" class="px-4 py-3">
                                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitKey(robot.id)">
                                    <div class="grid gap-2">
                                        <Label :for="`key_name_${robot.id}`">Key-Name</Label>
                                        <Input :id="`key_name_${robot.id}`" v-model="keyForm.name" placeholder="deploy-key" autocomplete="off" />
                                        <InputError :message="keyForm.errors.name" />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label :for="`key_perm_${robot.id}`">Recht</Label>
                                        <SearchableSelect
                                            :id="`key_perm_${robot.id}`"
                                            v-model="keyForm.permission"
                                            :options="[
                                                { value: 'read', label: 'Lesen' },
                                                { value: 'write', label: 'Schreiben' },
                                            ]"
                                        />
                                        <InputError :message="keyForm.errors.permission" />
                                    </div>
                                    <Button type="submit" :disabled="keyForm.processing">Key erstellen</Button>
                                    <Button type="button" variant="ghost" @click="toggleKeyForm(robot.id)">Abbrechen</Button>
                                </form>
                            </td>
                        </tr>
                    </template>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
