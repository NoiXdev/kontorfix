<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import PortalHeader from '@/components/kontorfix/PortalHeader.vue';
import PortalSetupBand from '@/components/kontorfix/PortalSetupBand.vue';
import type { PortalLastUsedToken, PortalSetupRegistry, PortalSetupState } from '@/components/kontorfix/portalSetupBand';
import SharedBadge from '@/components/kontorfix/SharedBadge.vue';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { badgesFor, licenceNote, noteFor, registryMarker, SHARED_BADGE_TITLE, type PortalRegistryEntry } from './portalPackages';

interface RegistryEntry extends PortalRegistryEntry {
    // The id, not the slug: every portal registry URL is built from the id
    // (`portal.registries.package`), so the slug would be a field nothing reads.
    id: string;
    name: string;
}

interface PackageRow {
    id: string;
    name: string;
    type: string;
    description: string | null;
    shared: boolean;
    // The newest version this package has, whatever registry it came from — the package's
    // own latest release, not a per-registry answer. `portal/Registry.vue` shows the same
    // field inside one registry.
    latest_version: string | null;
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
    // The entry band's payload. It is NOT derived from `packages`: the band has to render for
    // a customer whose package list is empty — a registry handed over before anything is
    // assigned to it — and that is exactly the moment the portal exists for.
    registries: PortalSetupRegistry[];
    setupState: PortalSetupState;
    lastUsedToken: PortalLastUsedToken | null;
    // The ecosystems this organization may serve — `RegistryTypeService::effectiveFor()`,
    // the same answer portal/Registry.vue's Einrichtung tab is built from. Named
    // `setupTypes` and not `types` because this page already carries a `type` per package
    // row, and the two answer different questions: what is IN the registry, and what the
    // organization is PERMITTED to serve.
    setupTypes: string[];
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
    { key: 'latest_version', label: 'Letzte Version' },
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
            <PortalHeader />

            <!-- The page's h1 comes FIRST, before the band's own h2. It used to follow it,
                 which opened this landing page at heading level 2 and left the h1 below a
                 section it does not head — the one heading order a screen reader's outline
                 cannot repair. Every other portal page puts its h1 at the top of the content
                 for the same reason. -->
            <h1 class="text-xl font-semibold">Pakete</h1>

            <!-- ABOVE the list, because setting up a tool is due before installing anything
                 from it — and because the customer arrives here, not on a page of its own.
                 It shrinks to one line once a token of this organization has been used. -->
            <PortalSetupBand
                :org-slug="props.orgSlug"
                :registries="props.registries"
                :setup-state="props.setupState"
                :last-used-token="props.lastUsedToken"
                :types="props.setupTypes"
            />

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
                        <tr :class="noteFor(pkg) ? '' : 'border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border'">
                            <td class="px-4 py-3 font-mono">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ pkg.name }}</span>
                                    <!-- One decision, in the tested module: which markers this row
                                         carries and in what order. `geteilt` renders the shared
                                         component the whole console uses, so the customer sees the
                                         marker their operator sees. -->
                                    <template v-for="badge in badgesFor(pkg)" :key="badge">
                                        <!-- The badge is the console's, the tooltip is not: what a
                                             shared package means to its operator is a capability the
                                             customer does not have. The text lives in the module, so
                                             that it is a string something can test. -->
                                        <SharedBadge v-if="badge === 'geteilt'" :title="SHARED_BADGE_TITLE" />
                                        <!-- v-else-if, not v-else: a catch-all would render any badge
                                             added later in destructive red, which is the wrong default
                                             for a marker that is not a fault. An unhandled value
                                             renders nothing, and nothing is visible. -->
                                        <span
                                            v-else-if="badge === 'abgelaufen'"
                                            class="inline-flex items-center rounded-md border border-destructive/30 bg-destructive/10 px-2 py-0.5 font-sans text-xs font-medium text-destructive"
                                        >
                                            {{ badge }}
                                        </span>
                                    </template>
                                </div>
                            </td>
                            <td class="px-4 py-3">{{ typeLabel(pkg.type) }}</td>
                            <td class="px-4 py-3 font-mono text-muted-foreground">{{ pkg.latest_version ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ pkg.description ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <!-- Marked per registry, not per row: a package still served by
                                         one registry is in force and carries no badge above, and
                                         this is then the only place the customer learns that the
                                         other registry stopped serving it.

                                         A lapsed entry stays LINKED, like the one on
                                         portal/Registry.vue. It was not, on the rule
                                         admin/groups/Show.vue states for a row action its caller may
                                         not use — but the premise of that rule went away:
                                         RegistryController::showPackage() no longer answers 404 for a
                                         lapsed assignment, it SERVES it with `in_force: false` and
                                         the explanation (PortalLapsedAssignmentTest). So the link is
                                         no longer a dead end; it is the way to the one page written
                                         to tell this customer why their build fails, and the page
                                         they land on is the one page that must not withhold it. -->
                                    <span v-for="reg in pkg.registries" :key="reg.id" class="inline-flex items-center gap-1">
                                        <Link :href="route('portal.registries.package', [props.orgSlug, reg.id, pkg.id])" class="hover:underline">
                                            {{ reg.name }}
                                        </Link>
                                        <span v-if="registryMarker(reg)" class="text-xs text-destructive">({{ registryMarker(reg) }})</span>
                                        <!-- Task 9: display only, next to the highest version this registry's own
                                             licence admits — no action, no self-service upgrade. Independent of
                                             `registryMarker` above: a lapsed registry and a licence-bounded one
                                             are different facts and can both be true of the same entry. -->
                                        <span v-if="licenceNote(reg)" class="text-xs text-copper-hi">({{ licenceNote(reg) }})</span>
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <!-- One note row for both cases, because a package can be unusable OR merely
                             lapsed in some of the customer's registries, and the second used to say
                             nothing at all: the customer whose build resolves against exactly that
                             registry was the one person told only a date in brackets. `noteFor` picks
                             which sentence; red only where nothing serves the package any more, since
                             a partially lapsed package still installs everywhere else. -->
                        <tr v-if="noteFor(pkg)" class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
                            <td colspan="5" class="px-4 pb-3 text-xs" :class="pkg.in_force ? 'text-copper-hi' : 'text-destructive'">
                                {{ noteFor(pkg) }}
                            </td>
                        </tr>
                    </template>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
