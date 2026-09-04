<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import SharedBadge from '@/components/kontorfix/SharedBadge.vue';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { badgesFor, lapsedNote, registryMarker, type PortalRegistryEntry } from './portalPackages';

interface RegistryEntry extends PortalRegistryEntry {
    id: string;
    name: string;
    slug: string;
}

interface PackageRow {
    id: string;
    name: string;
    type: string;
    description: string | null;
    shared: boolean;
    // Usable at all — in force in at least one of the registries below. NOT the same
    // question as an entry's own `in_force`; see `portalPackages.ts`.
    in_force: boolean;
    registries: RegistryEntry[];
}

const props = defineProps<{
    // The organization the URL addresses, not the viewer's own — an operator looking at a
    // customer's portal has to keep navigating inside that customer's portal.
    orgSlug: string;
    packages: PackageRow[];
}>();

// The PackageType enum's own labels, shared from the backend. Not a table here: the console
// names ecosystems in one place.
const { label: typeLabel, options: typeOptions } = useRegistryTypes();

const typeFilterOptions = computed(() => typeOptions([...new Set(props.packages.map((p) => p.type))]));

// Prefix 'pkg', the same one portal/Registry.vue gives its package table, so search and type
// filtering read the same query keys and behave identically on both portal surfaces.
const columns: ColumnDef<PackageRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Typ' },
    { key: 'description', label: 'Beschreibung' },
    { key: 'registries', label: 'Registries', sortable: false },
];

const table = useTableState<PackageRow>({
    rows: () => props.packages,
    columns,
    prefix: 'pkg',
    searchKeys: ['name', 'description'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        type: {
            label: 'Typ',
            options: typeFilterOptions.value,
            match: (row, value) => row.type === value,
        },
    },
});

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pakete', href: route('portal.packages.index', props.orgSlug) }];
</script>

<template>
    <Head title="Pakete" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Pakete</h1>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Pakete verfügbar." search-placeholder="Name suchen…">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.type.value"
                        :options="typeFilterOptions"
                        placeholder="Alle Typen"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('type', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <template v-for="pkg in rows" :key="pkg.id">
                        <!-- The separator belongs to the LAST row of this package's block, so the
                             main row gives it up whenever the note follows it. Keeping it here
                             would put the red 404 explanation below a separator, where it reads as
                             belonging to the next package — the same rule admin/groups/Show.vue
                             follows, and for the same reason: an outage warning attributed to the
                             wrong package is worse than none. -->
                        <tr :class="pkg.in_force ? 'border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border' : ''">
                            <td class="px-4 py-3 font-mono">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ pkg.name }}</span>
                                    <!-- One decision, in the tested module: which markers this row
                                         carries and in what order. `geteilt` renders the shared
                                         component the whole console uses, so the customer sees the
                                         marker their operator sees. -->
                                    <template v-for="badge in badgesFor(pkg)" :key="badge">
                                        <SharedBadge v-if="badge === 'geteilt'" />
                                        <span
                                            v-else
                                            class="inline-flex items-center rounded-md border border-destructive/30 bg-destructive/10 px-2 py-0.5 font-sans text-xs font-medium text-destructive"
                                        >
                                            {{ badge }}
                                        </span>
                                    </template>
                                </div>
                            </td>
                            <td class="px-4 py-3">{{ typeLabel(pkg.type) }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ pkg.description ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <!-- Marked per registry, not per row: a package still served by
                                         one registry is in force and carries no badge above, and
                                         this is then the only place the customer learns that the
                                         other registry stopped serving it.

                                         The name of a lapsed one is shown but NOT linked, the rule
                                         admin/groups/Show.vue states for a row action its caller may
                                         not use: RegistryController::showPackage() answers 404 for an
                                         assignment that has lapsed, so linking it would offer the
                                         customer a dead end and then walk them into it. They are
                                         still told which registry it was. -->
                                    <span v-for="reg in pkg.registries" :key="reg.id" class="inline-flex items-center gap-1">
                                        <Link
                                            v-if="reg.in_force"
                                            :href="route('portal.registries.package', [props.orgSlug, reg.id, pkg.id])"
                                            class="hover:underline"
                                        >
                                            {{ reg.name }}
                                        </Link>
                                        <span v-else>{{ reg.name }}</span>
                                        <span v-if="registryMarker(reg)" class="text-xs text-destructive">({{ registryMarker(reg) }})</span>
                                    </span>
                                    <span v-if="pkg.registries.length === 0" class="text-muted-foreground">—</span>
                                </div>
                            </td>
                        </tr>
                        <!-- Shown only where the package is served by none of them — where the row
                             itself has lapsed. A package still live in another registry is usable,
                             and the marker beside the lapsed link is what that case needs. -->
                        <tr v-if="!pkg.in_force" class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
                            <td colspan="4" class="px-4 pb-3 text-xs text-destructive">{{ lapsedNote() }}</td>
                        </tr>
                    </template>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
