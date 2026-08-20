<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import StatusPill from '@/components/kontorfix/StatusPill.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SearchableSelect } from '@/components/ui/searchable-select';
import AppLayout from '@/layouts/AppLayout.vue';
import { useOperatorChannel, type PackagePayload } from '@/composables/useOperatorChannel';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface PackageRow {
    id: string;
    type: 'composer' | 'npm' | 'python';
    source_mode: 'publish' | 'git';
    name: string;
    sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
    sync_error: string | null;
    groups_count: number;
    synced_at: string | null;
    is_abandoned: boolean;
}

interface GroupOption {
    id: string;
    name: string;
    slug: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
}

interface Filters {
    q: string | null;
    type: string | null;
    status: string | null;
    group: string | null;
    sort: string | null;
    direction: 'asc' | 'desc';
}

const props = defineProps<{
    packages: Paginated<PackageRow>;
    groups: GroupOption[];
    filters: Filters;
    registryTypes: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pakete', href: '/admin/packages' }];

// Type metadata (labels) comes from the shared single source.
const { options: typeOptionsFor } = useRegistryTypes();
// Only instance-enabled types are offered in the filter.
const typeOptions = computed(() => typeOptionsFor(props.registryTypes));

const filterQ = ref(props.filters.q ?? '');
const filterType = ref(props.filters.type ?? '');
const filterStatus = ref(props.filters.status ?? '');
const filterGroup = ref(props.filters.group ?? '');

const hasActiveFilters = computed(() => filterQ.value !== '' || filterType.value !== '' || filterStatus.value !== '' || filterGroup.value !== '');

function applyFilters() {
    // Sort/direction are not among the refs this filter bar owns — they live in `table`
    // below — so they are read back from the current URL rather than reset, or changing a
    // filter while a sort is active would silently drop it back to the default order.
    const current = new URLSearchParams(window.location.search);

    router.get(
        route('admin.packages.index'),
        {
            q: filterQ.value,
            type: filterType.value,
            status: filterStatus.value,
            group: filterGroup.value,
            sort: current.get('sort') || undefined,
            direction: current.get('direction') || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
watch(filterQ, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 250);
});
watch([filterType, filterStatus, filterGroup], applyFilters);

function resetFilters() {
    filterQ.value = '';
    filterType.value = '';
    filterStatus.value = '';
    filterGroup.value = '';
}

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

// Live hint for sync events via the operator channel.
const liveHint = ref<{ message: string; failed: boolean } | null>(null);
let hintTimer: ReturnType<typeof setTimeout> | undefined;

function showHint(message: string, failed: boolean) {
    liveHint.value = { message, failed };
    clearTimeout(hintTimer);
    hintTimer = setTimeout(() => (liveHint.value = null), 5000);
}

function applyStatus(p: PackagePayload) {
    const row = props.packages.data.find((pkg) => pkg.id === p.id);
    if (row) {
        row.sync_status = p.sync_status as PackageRow['sync_status'];
        row.sync_error = p.error ?? null;
    }
}

// The composable decides whether this account may subscribe at all.
useOperatorChannel({
    onSynced: (p) => {
        applyStatus(p);
        showHint(`${p.name} synchronisiert`, false);
    },
    onFailed: (p) => {
        applyStatus(p);
        showHint(`${p.name}: Sync fehlgeschlagen`, true);
    },
});

function destroyPackage(id: string) {
    router.delete(route('admin.packages.destroy', id), {
        onBefore: () => confirm('Paket wirklich löschen?'),
    });
}

// This listing paginates (25/page), so sorting the current page client-side would only
// reorder the 25 rows already on it — page 2 would keep names that belong on page 1. The
// column whitelist and the actual ordering live in PackageController::index (server-side);
// mode: 'server' tells useTableState to hand back `packages.data` untouched instead of
// re-sorting it, and defaultSort only reflects the server's answer so the header arrow is
// never a promise the client can't keep.
const columns: ColumnDef<PackageRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Typ' },
    { key: 'sync_status', label: 'Status' },
    { key: 'groups_count', label: 'Gruppen' },
    { key: 'synced_at', label: 'Zuletzt synchronisiert' },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<PackageRow>({
    rows: () => props.packages.data,
    columns,
    mode: 'server',
    defaultSort: props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction } : undefined,
});
</script>

<template>
    <Head title="Pakete" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div
                v-if="liveHint"
                class="fixed top-16 right-4 z-50 rounded-md border px-4 py-2 text-sm shadow-lg"
                :class="
                    liveHint.failed
                        ? 'border-destructive/30 bg-destructive/10 text-destructive'
                        : 'border-verdigris/30 bg-verdigris/15 text-verdigris'
                "
            >
                {{ liveHint.message }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Pakete</h1>
                <Button as-child>
                    <Link :href="route('admin.packages.create')">
                        <Plus class="size-4" />
                        Paket hinzufügen
                    </Link>
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Input
                    v-model="filterQ"
                    type="search"
                    placeholder="Name suchen…"
                    autocomplete="off"
                    class="h-10 w-full sm:w-64"
                    aria-label="Nach Name suchen"
                />
                <SearchableSelect
                    v-model="filterType"
                    class="w-40"
                    placeholder="Alle Typen"
                    :options="[{ value: '', label: 'Alle Typen' }, ...typeOptions]"
                />
                <SearchableSelect
                    v-model="filterStatus"
                    class="w-40"
                    placeholder="Alle Status"
                    :options="[
                        { value: '', label: 'Alle Status' },
                        { value: 'pending', label: 'pending' },
                        { value: 'syncing', label: 'syncing' },
                        { value: 'synced', label: 'synced' },
                        { value: 'failed', label: 'failed' },
                    ]"
                />
                <SearchableSelect
                    v-model="filterGroup"
                    class="w-52"
                    placeholder="Alle Registries"
                    :options="[{ value: '', label: 'Alle Registries' }, ...props.groups.map((g) => ({ value: g.id, label: g.name }))]"
                />
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    @click="resetFilters"
                >
                    Zurücksetzen
                </button>
            </div>

            <DataTable :columns="columns" :state="table" :show-filter-bar="false" empty-message="Noch keine Pakete angelegt.">
                <template #default="{ rows }">
                    <tr
                        v-for="pkg in rows"
                        :key="pkg.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3 font-mono">
                            <div class="flex items-center gap-2">
                                <Link :href="route('admin.packages.show', pkg.id)" class="hover:underline">{{ pkg.name }}</Link>
                                <span
                                    v-if="pkg.is_abandoned"
                                    class="inline-flex items-center rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 font-sans text-xs font-medium text-amber-700 dark:text-amber-400"
                                >
                                    verwaist
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3"><TypeBadge :type="pkg.type" /></td>
                        <td class="px-4 py-3">
                            <span :title="pkg.sync_status === 'failed' ? (pkg.sync_error ?? undefined) : undefined">
                                <StatusPill :status="pkg.sync_status" />
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ pkg.groups_count }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ pkg.synced_at ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Button variant="ghost" size="icon" @click="destroyPackage(pkg.id)" aria-label="Paket löschen">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
