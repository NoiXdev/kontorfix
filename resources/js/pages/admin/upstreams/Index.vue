<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface UpstreamRow {
    id: string;
    group: string | null;
    group_id: string;
    type: 'composer' | 'npm' | 'python';
    url: string;
    policy: 'proxy' | 'strict';
    priority: number;
    enabled: boolean;
    has_auth: boolean;
    allowed_packages: string[];
}

interface GroupOption {
    id: string;
    name: string;
}

const props = defineProps<{
    upstreams: UpstreamRow[];
    groups: GroupOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Upstreams', href: '/admin/upstreams' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

// Upstream types are the registry types — from the single source of truth.
const { options: registryTypeOptions } = useRegistryTypes();
const typeOptions = computed(() => registryTypeOptions());

const groupOptions = computed(() => props.groups.map((g) => ({ value: g.id, label: g.name })));

const policyOptions = [
    { value: 'proxy', label: 'Proxy' },
    { value: 'strict', label: 'Strict' },
];

// Derived from the existing `enabled` prop — labels for the two values that
// boolean already takes, not a separate controller-supplied option list.
const activeOptions = [
    { value: 'yes', label: 'Ja' },
    { value: 'no', label: 'Nein' },
];

const columns: ColumnDef<UpstreamRow>[] = [
    { key: 'group', label: 'Gruppe' },
    { key: 'type', label: 'Typ' },
    { key: 'url', label: 'URL' },
    { key: 'policy', label: 'Policy' },
    { key: 'priority', label: 'Priorität', sortAs: 'number' },
    { key: 'enabled', label: 'Aktiv', sortValue: (row) => (row.enabled ? 'Ja' : 'Nein') },
    { key: 'has_auth', label: 'Auth', sortable: false },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<UpstreamRow>({
    rows: () => props.upstreams,
    columns,
    searchKeys: ['url'],
    defaultSort: { key: 'url', direction: 'asc' },
    filters: {
        group: {
            label: 'Gruppe',
            options: groupOptions.value,
            match: (row, value) => row.group_id === value,
        },
        type: {
            label: 'Typ',
            options: typeOptions.value,
            match: (row, value) => row.type === value,
        },
        policy: {
            label: 'Policy',
            options: policyOptions,
            match: (row, value) => row.policy === value,
        },
        active: {
            label: 'Aktiv',
            options: activeOptions,
            match: (row, value) => (value === 'yes' ? row.enabled : !row.enabled),
        },
    },
});

function policyLabel(policy: 'proxy' | 'strict') {
    return policy === 'strict' ? 'Strict' : 'Proxy';
}

function policyClasses(policy: 'proxy' | 'strict') {
    return policy === 'strict'
        ? 'bg-amber-500/15 text-amber-600 border-amber-500/30 dark:text-amber-400'
        : 'bg-emerald-500/15 text-emerald-600 border-emerald-500/30 dark:text-emerald-400';
}

function destroyUpstream(id: string) {
    router.delete(route('admin.upstreams.destroy', id), {
        onBefore: () => confirm('Upstream wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Upstreams" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Upstreams</h1>
                <Button as-child>
                    <Link :href="route('admin.upstreams.create')">
                        <Plus class="size-4" />
                        Upstream hinzufügen
                    </Link>
                </Button>
            </div>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Upstreams angelegt." search-placeholder="Suchen…">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.group.value"
                        :options="groupOptions"
                        placeholder="Gruppe"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('group', String(v))"
                    />
                    <SearchableSelect
                        :model-value="table.filterValues.type.value"
                        :options="typeOptions"
                        placeholder="Typ"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('type', String(v))"
                    />
                    <SearchableSelect
                        :model-value="table.filterValues.policy.value"
                        :options="policyOptions"
                        placeholder="Policy"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('policy', String(v))"
                    />
                    <SearchableSelect
                        :model-value="table.filterValues.active.value"
                        :options="activeOptions"
                        placeholder="Aktiv"
                        class="w-32"
                        @update:model-value="(v) => table.setFilter('active', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <tr
                        v-for="upstream in rows"
                        :key="upstream.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3">{{ upstream.group ?? '—' }}</td>
                        <td class="px-4 py-3"><TypeBadge :type="upstream.type" /></td>
                        <td class="px-4 py-3 font-mono text-xs">{{ upstream.url }}</td>
                        <td class="px-4 py-3">
                            <span
                                :class="
                                    cn(
                                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                        policyClasses(upstream.policy),
                                    )
                                "
                            >
                                {{ policyLabel(upstream.policy) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ upstream.priority }}</td>
                        <td class="px-4 py-3">
                            <span
                                :class="
                                    cn(
                                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                        upstream.enabled
                                            ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                            : 'border-border bg-muted text-muted-foreground',
                                    )
                                "
                            >
                                {{ upstream.enabled ? 'Ja' : 'Nein' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="upstream.has_auth"
                                class="inline-flex items-center rounded-md border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                            >
                                Token
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <Button as-child variant="ghost" size="icon" aria-label="Upstream bearbeiten">
                                    <Link :href="route('admin.upstreams.edit', upstream.id)">
                                        <Pencil class="size-4" />
                                    </Link>
                                </Button>
                                <Button variant="ghost" size="icon" @click="destroyUpstream(upstream.id)" aria-label="Upstream löschen">
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
