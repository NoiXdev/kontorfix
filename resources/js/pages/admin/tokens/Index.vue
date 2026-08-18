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
import { Copy, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface TokenRow {
    id: string;
    name: string;
    organization: string | null;
    organization_id: string | null;
    group: string | null;
    group_id: string | null;
    ability: 'read' | 'publish';
    last_used_at: string | null;
    // Raw ISO timestamp, sort-only — `last_used_at` is a relative string ("vor 3 Tagen")
    // that Date.parse cannot read.
    last_used_at_iso: string | null;
    expires_at: string | null;
}

interface OrganizationOption {
    id: string;
    name: string;
}

interface GroupOption {
    id: string;
    name: string;
    organization_id: string;
}

const props = defineProps<{
    tokens: TokenRow[];
    organizations: OrganizationOption[];
    groups: GroupOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tokens', href: '/admin/tokens' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const plainTextToken = computed(() => page.props.flash?.plainTextToken ?? null);

const tokenCalloutDismissed = ref(false);
watch(plainTextToken, (value) => {
    if (value) {
        tokenCalloutDismissed.value = false;
    }
});

const showTokenCallout = computed(() => !!plainTextToken.value && !tokenCalloutDismissed.value);

const copied = ref(false);

async function copyToken() {
    if (!plainTextToken.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(plainTextToken.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard API not available (e.g. insecure context) — the token can be
        // selected, the user can copy it manually.
        copied.value = false;
    }
}

const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));
const groupOptions = computed(() => props.groups.map((g) => ({ value: g.id, label: g.name })));
const abilityOptions = [
    { value: 'read', label: 'Lesen' },
    { value: 'publish', label: 'Veröffentlichen' },
];

const columns: ColumnDef<TokenRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'organization', label: 'Organisation' },
    { key: 'group', label: 'Gruppe' },
    { key: 'ability', label: 'Recht' },
    { key: 'last_used_at', label: 'Zuletzt genutzt', sortAs: 'date', sortValue: (row) => row.last_used_at_iso },
    { key: 'expires_at', label: 'Läuft ab', sortAs: 'date' },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<TokenRow>({
    rows: () => props.tokens,
    columns,
    searchKeys: ['name'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        org: {
            label: 'Organisation',
            options: orgOptions.value,
            match: (row, value) => row.organization_id === value,
        },
        group: {
            label: 'Gruppe',
            options: groupOptions.value,
            match: (row, value) => row.group_id === value,
        },
        ability: {
            label: 'Recht',
            options: abilityOptions,
            match: (row, value) => row.ability === value,
        },
    },
});

const dialogOpen = ref(false);

const form = useForm({
    name: '',
    organization_id: '',
    group_id: '',
    ability: 'read' as 'read' | 'publish',
});

const filteredGroups = computed(() => props.groups.filter((g) => g.organization_id === form.organization_id));

watch(
    () => form.organization_id,
    () => {
        form.group_id = '';
    },
);

function submit() {
    form.post(route('admin.tokens.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

function abilityLabel(ability: 'read' | 'publish') {
    return ability === 'publish' ? 'Veröffentlichen' : 'Lesen';
}

function destroyToken(id: string) {
    router.delete(route('admin.tokens.destroy', id), {
        onBefore: () => confirm('Token wirklich widerrufen?'),
    });
}
</script>

<template>
    <Head title="Tokens" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div v-if="showTokenCallout" class="rounded-xl border border-copper/30 bg-copper/10 p-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1 space-y-2">
                        <p class="font-medium text-copper-hi">Neuer Token erstellt</p>
                        <p class="rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-sm break-all select-all">
                            {{ plainTextToken }}
                        </p>
                        <p class="text-sm text-muted-foreground">Dieser Token wird nur einmal angezeigt. Bewahre ihn sicher auf.</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button variant="outline" size="sm" @click="copyToken">
                            <Copy class="size-4" />
                            {{ copied ? 'Kopiert!' : 'Kopieren' }}
                        </Button>
                        <Button variant="ghost" size="sm" @click="tokenCalloutDismissed = true"> Schließen </Button>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Tokens</h1>
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Token erstellen
                </Button>
            </div>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Tokens erstellt.">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.org.value"
                        :options="orgOptions"
                        placeholder="Organisation"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('org', String(v))"
                    />
                    <SearchableSelect
                        :model-value="table.filterValues.group.value"
                        :options="groupOptions"
                        placeholder="Gruppe"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('group', String(v))"
                    />
                    <SearchableSelect
                        :model-value="table.filterValues.ability.value"
                        :options="abilityOptions"
                        placeholder="Recht"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('ability', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <tr
                        v-for="token in rows"
                        :key="token.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3 font-mono">{{ token.name }}</td>
                        <td class="px-4 py-3">{{ token.organization ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ token.group ?? 'Alle Gruppen' }}</td>
                        <td class="px-4 py-3">{{ abilityLabel(token.ability) }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ token.last_used_at ?? 'nie' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ token.expires_at ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Button variant="ghost" size="icon" @click="destroyToken(token.id)" aria-label="Token widerrufen">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Token erstellen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" placeholder="kadenz-ci" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="organization_id">Organisation</Label>
                        <SearchableSelect
                            id="organization_id"
                            v-model="form.organization_id"
                            placeholder="Bitte wählen"
                            :options="props.organizations.map((o) => ({ value: o.id, label: o.name }))"
                        />
                        <InputError :message="form.errors.organization_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="group_id">Gruppe</Label>
                        <SearchableSelect
                            id="group_id"
                            v-model="form.group_id"
                            :options="[{ value: '', label: 'Alle Gruppen' }, ...filteredGroups.map((g) => ({ value: g.id, label: g.name }))]"
                        />
                        <InputError :message="form.errors.group_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ability">Recht</Label>
                        <SearchableSelect
                            id="ability"
                            v-model="form.ability"
                            :options="[
                                { value: 'read', label: 'Lesen' },
                                { value: 'publish', label: 'Veröffentlichen' },
                            ]"
                        />
                        <InputError :message="form.errors.ability" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="form.processing">Erstellen</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
