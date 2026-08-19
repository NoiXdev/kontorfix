<script setup lang="ts">
import ActivityTimeline from '@/components/kontorfix/ActivityTimeline.vue';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useActivityQuery, type ActivityFilters } from '@/composables/useActivityQuery';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ArrowDownWideNarrow, ArrowUpNarrowWide } from 'lucide-vue-next';
import { computed } from 'vue';

interface Activity {
    id: number;
    log_name: string | null;
    event: string | null;
    description: string;
    subject_type: string | null;
    subject_id: string | null;
    subject_label: string | null;
    causer: string | null;
    changes: Record<string, unknown>;
    created_at: string | null;
    created_at_exact: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    activities: Paginated<Activity>;
    filters: ActivityFilters;
    logNames: string[];
    pageSizes: number[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Aktivität', href: '/admin/activity' }];

// Every control on this page writes into the query string and nothing else — no local
// filtering. `useActivityQuery` builds those parameters (and is where they are tested);
// the page only renders the current values and hands changes back.
const { logFilter, direction, perPage, setLog, toggleDirection, setPerPage, clearScope } = useActivityQuery(() => props.filters);

const scoped = computed(() => props.filters.subject_id || props.filters.causer);

const logOptions = [{ value: '', label: 'Alle Bereiche' }, ...props.logNames.map((n) => ({ value: n, label: n }))];

// The options come from the server's whitelist, so the selector cannot offer a size the
// controller would reject and silently replace with the default.
const sizeOptions = computed(() => props.pageSizes.map((size) => ({ value: size, label: `${size} pro Seite` })));

const directionLabel = computed(() => (direction.value === 'desc' ? 'Neueste zuerst' : 'Älteste zuerst'));
</script>

<template>
    <Head title="Aktivität" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Aktivitätsprotokoll</h1>
                    <p class="text-sm text-muted-foreground">Wer hat was geändert — über Organisationen, Registries, Pakete und Nutzer.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <SearchableSelect
                        :model-value="logFilter"
                        class="w-48"
                        :options="logOptions"
                        @update:model-value="setLog"
                    />

                    <!--
                        What is left of the sortable column headers the timeline replaced: the
                        day grouping only reads in chronological order, so the direction is the
                        part of that sorting still worth reaching. It writes `sort`/`direction`,
                        the same query parameters the headers wrote.
                    -->
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-input px-3 py-1.5 text-sm hover:bg-muted/50"
                        :title="direction === 'desc' ? 'Nach Datum sortiert, neueste zuerst' : 'Nach Datum sortiert, älteste zuerst'"
                        :aria-label="`Sortierrichtung umschalten — aktuell: ${directionLabel}`"
                        @click="toggleDirection"
                    >
                        <component :is="direction === 'desc' ? ArrowDownWideNarrow : ArrowUpNarrowWide" class="size-4 text-muted-foreground" />
                        {{ directionLabel }}
                    </button>

                    <SearchableSelect
                        :model-value="perPage"
                        class="w-36"
                        :options="sizeOptions"
                        @update:model-value="setPerPage"
                    />

                    <button v-if="scoped" type="button" class="text-sm text-muted-foreground underline-offset-4 hover:underline" @click="clearScope">
                        Filter zurücksetzen
                    </button>
                </div>
            </div>

            <div v-if="scoped" class="rounded-md border border-copper/30 bg-copper/10 px-3 py-2 text-sm text-copper-hi">
                Gefiltert auf ein bestimmtes Objekt / einen Nutzer.
            </div>

            <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <ActivityTimeline :activities="props.activities.data" show-subject />
            </div>

            <!-- Pagination -->
            <div v-if="props.activities.links.length > 3" class="flex flex-wrap gap-1">
                <button
                    v-for="(link, i) in props.activities.links"
                    :key="i"
                    type="button"
                    :disabled="!link.url"
                    class="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
                    :class="link.active ? 'border-copper/40 bg-copper/10 font-medium' : 'border-input text-muted-foreground hover:bg-muted/50'"
                    @click="link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
                >
                    <span v-html="link.label" />
                </button>
            </div>
        </div>
    </AppLayout>
</template>
