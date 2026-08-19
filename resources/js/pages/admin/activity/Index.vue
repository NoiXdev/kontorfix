<script setup lang="ts">
import ActivityTimeline from '@/components/kontorfix/ActivityTimeline.vue';
import { SearchableSelect } from '@/components/ui/searchable-select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

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

interface Filters {
    log: string | null;
    subject_type: string | null;
    subject_id: string | null;
    causer: string | null;
    sort: string | null;
    direction: 'asc' | 'desc';
}

const props = defineProps<{
    activities: Paginated<Activity>;
    filters: Filters;
    logNames: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Aktivität', href: '/admin/activity' }];

const logFilter = ref(props.filters.log ?? '');

// Sort/direction are not among the refs this filter bar owns — the controller's whitelist
// decides them and they arrive in the URL — so they are read back from the current URL
// rather than reset, or changing the log-name filter while a sort is active would silently
// drop it back to the default order.
function currentSort(): { sort: string | undefined; direction: string | undefined } {
    const current = new URLSearchParams(window.location.search);
    return { sort: current.get('sort') || undefined, direction: current.get('direction') || undefined };
}

// Preserve any subject/causer scoping while changing the log-name filter.
watch(logFilter, (value) => {
    router.get(
        route('admin.activity.index'),
        {
            log: value || undefined,
            subject_type: props.filters.subject_type || undefined,
            subject_id: props.filters.subject_id || undefined,
            causer: props.filters.causer || undefined,
            ...currentSort(),
        },
        { preserveState: true, replace: true },
    );
});

const scoped = computed(() => props.filters.subject_id || props.filters.causer);

function clearScope() {
    router.get(route('admin.activity.index'), { log: logFilter.value || undefined, ...currentSort() }, { preserveState: true });
}

const logOptions = [{ value: '', label: 'Alle Bereiche' }, ...props.logNames.map((n) => ({ value: n, label: n }))];
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
                <div class="flex items-center gap-2">
                    <SearchableSelect v-model="logFilter" class="w-48" :options="logOptions" />
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
