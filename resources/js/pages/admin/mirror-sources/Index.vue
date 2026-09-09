<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import FlashToast from '@/components/kontorfix/FlashToast.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface MirrorSourceRow {
    id: string;
    name: string;
    type: string;
    url: string;
    organization: string | null;
    organization_id: string | null;
    packages_count: number | null;
    last_used_at: string | null;
    // Raw ISO timestamp, sort-only — `last_used_at` is a relative string ("vor 3 Tagen")
    // that Date.parse cannot read.
    last_used_at_iso: string | null;
}

const props = defineProps<{
    sources: MirrorSourceRow[];
    organizations: { id: string; name: string }[];
    types: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mirror-Quellen', href: '/admin/mirror-sources' }];

const typeOptions = computed(() => props.types.map((t) => ({ value: t.value, label: t.label })));
const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

function typeLabel(value: string): string {
    return props.types.find((t) => t.value === value)?.label ?? value;
}

const columns: ColumnDef<MirrorSourceRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Typ' },
    { key: 'organization', label: 'Organisation' },
    { key: 'packages_count', label: 'Pakete', sortAs: 'number' },
    { key: 'last_used_at', label: 'Zuletzt genutzt', sortAs: 'date', sortValue: (row) => row.last_used_at_iso },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<MirrorSourceRow>({
    rows: () => props.sources,
    columns,
    searchKeys: ['name'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        type: {
            label: 'Typ',
            options: typeOptions.value,
            match: (row, value) => row.type === value,
        },
        org: {
            label: 'Organisation',
            options: orgOptions.value,
            match: (row, value) => row.organization_id === value,
        },
    },
});

function destroySource(id: string) {
    router.delete(route('admin.mirror-sources.destroy', id), {
        preserveScroll: true,
        onBefore: () => confirm('Mirror-Quelle wirklich löschen? Zugewiesene Pakete verlieren ihre Quelle und schlagen bei der nächsten Synchronisierung fehl.'),
    });
}
</script>

<template>
    <Head title="Mirror-Quellen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <FlashToast />

            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Mirror-Quellen</h1>
                    <p class="text-sm text-muted-foreground">
                        Wiederverwendbare Zeiger auf fremde Composer-, npm- oder Python-Registries, aus denen Pakete gespiegelt werden.
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('admin.mirror-sources.create')">
                        <Plus class="size-4" />
                        Quelle anlegen
                    </Link>
                </Button>
            </div>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Mirror-Quellen angelegt." search-placeholder="Suchen…">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.type.value"
                        :options="typeOptions"
                        placeholder="Typ"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('type', String(v))"
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
                    <tr v-for="source in rows" :key="source.id" class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
                        <td class="px-4 py-3 font-medium">{{ source.name }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground uppercase">{{
                                typeLabel(source.type)
                            }}</span>
                        </td>
                        <td class="px-4 py-3">{{ source.organization ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ source.packages_count ?? 0 }} Pakete</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ source.last_used_at ?? 'nie' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <Button as-child variant="ghost" size="icon" aria-label="Bearbeiten">
                                    <Link :href="route('admin.mirror-sources.edit', source.id)"><Pencil class="size-4" /></Link>
                                </Button>
                                <Button variant="ghost" size="icon" aria-label="Löschen" @click="destroySource(source.id)">
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
