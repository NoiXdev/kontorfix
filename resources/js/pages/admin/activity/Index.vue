<script setup lang="ts">
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

const props = defineProps<{
    activities: Paginated<Activity>;
    filters: { log: string | null; subject_type: string | null; subject_id: string | null; causer: string | null };
    logNames: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Aktivität', href: '/admin/activity' }];

const logFilter = ref(props.filters.log ?? '');

// Preserve any subject/causer scoping while changing the log-name filter.
watch(logFilter, (value) => {
    router.get(
        route('admin.activity.index'),
        {
            log: value || undefined,
            subject_type: props.filters.subject_type || undefined,
            subject_id: props.filters.subject_id || undefined,
            causer: props.filters.causer || undefined,
        },
        { preserveState: true, replace: true },
    );
});

const scoped = computed(() => props.filters.subject_id || props.filters.causer);

function clearScope() {
    router.get(route('admin.activity.index'), { log: logFilter.value || undefined }, { preserveState: true });
}

const logOptions = [{ value: '', label: 'Alle Bereiche' }, ...props.logNames.map((n) => ({ value: n, label: n }))];

function pretty(value: unknown): string {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

function hasChanges(a: Activity): boolean {
    return a.changes && Object.keys(a.changes).length > 0;
}
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

            <div class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Zeit</th>
                            <th class="px-4 py-3 font-medium">Bereich</th>
                            <th class="px-4 py-3 font-medium">Ereignis</th>
                            <th class="px-4 py-3 font-medium">Objekt</th>
                            <th class="px-4 py-3 font-medium">Von</th>
                            <th class="px-4 py-3 font-medium">Änderungen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in props.activities.data" :key="a.id" class="border-b border-sidebar-border/70 align-top last:border-0 dark:border-sidebar-border">
                            <td class="px-4 py-3 text-muted-foreground" :title="a.created_at_exact ?? ''">{{ a.created_at }}</td>
                            <td class="px-4 py-3">{{ a.log_name }}</td>
                            <td class="px-4 py-3">{{ a.event ?? a.description }}</td>
                            <td class="px-4 py-3">
                                <span v-if="a.subject_type">{{ a.subject_type }}<span v-if="a.subject_label"> · {{ a.subject_label }}</span></span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-3">{{ a.causer ?? 'System' }}</td>
                            <td class="px-4 py-3">
                                <details v-if="hasChanges(a)">
                                    <summary class="cursor-pointer text-muted-foreground">anzeigen</summary>
                                    <pre class="mt-1 max-h-64 overflow-auto rounded-md border border-sidebar-border/70 bg-muted/40 p-2 text-xs dark:border-sidebar-border">{{ pretty(a.changes) }}</pre>
                                </details>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                        </tr>
                        <tr v-if="props.activities.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">Noch keine Aktivität protokolliert.</td>
                        </tr>
                    </tbody>
                </table>
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
