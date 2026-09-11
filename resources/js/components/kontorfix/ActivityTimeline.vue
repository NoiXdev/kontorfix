<script setup lang="ts">
import ActivityDetailDialog from '@/components/kontorfix/ActivityDetailDialog.vue';
import { eventClass, eventLabel, extraDescription } from '@/lib/activityEvents';
import {
    groupByDay,
    groupBursts,
    timeOfDay,
    type ActivityBurst,
    type ActivityEntry,
    type ActivityGroup,
    type ActivityRow,
} from '@/lib/activityGroups';
import { cn } from '@/lib/utils';
import { ChevronRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{ activities: ActivityEntry[]; showSubject?: boolean; compact?: boolean; chronological?: boolean }>(),
    {
        showSubject: false,
        compact: false,
        // The per-subject tabs never offer a sort control — every list they render is
        // newest/oldest by timestamp — so they can omit this prop and still fold correctly.
        // Only the global page, which can be sorted by `description` or `log_name`, passes
        // a computed value here.
        chronological: true,
    },
);

/**
 * Day headings come from `groupByDay`; `compact` skips it entirely rather than rendering
 * its groups without their labels. Unlabelled groups would still be drawn as separate
 * lists, which is the day grouping back again minus the one thing that explains it.
 */
const groups = computed<ActivityGroup[]>(() =>
    props.compact ? [{ key: 'all', label: '', entries: props.activities }] : groupByDay(props.activities),
);

interface DisplayGroup {
    key: string;
    label: string;
    rows: ActivityRow[];
}

/**
 * Each day group's entries, further folded into bursts. Folding runs inside the day
 * grouping rather than before it: a burst is keyed on a single minute, which never spans a
 * day boundary, so the two never conflict — this only avoids computing bursts across group
 * boundaries that could not fold anyway.
 */
const displayGroups = computed<DisplayGroup[]>(() =>
    groups.value.map((group) => ({
        key: group.key,
        label: group.label,
        rows: groupBursts(group.entries, props.chronological),
    })),
);

const expanded = ref<Set<string>>(new Set());

function toggleBurst(key: string) {
    const next = new Set(expanded.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    expanded.value = next;
}

const SUBJECT_PLURALS: Record<string, string> = {
    Package: 'Pakete',
    Group: 'Registries',
    Organization: 'Organisationen',
    User: 'Nutzer',
    Domain: 'Domains',
};

const EVENT_VERBS: Record<string, string> = {
    created: 'erstellt',
    updated: 'aktualisiert',
    deleted: 'gelöscht',
    retention_applied: 'zurückgehalten',
    storage_swept: 'bereinigt',
};

const EVENT_NOUNS: Record<string, string> = {
    created: 'Neuanlagen',
    updated: 'Änderungen',
    deleted: 'Löschungen',
    retention_applied: 'Retention-Läufe',
    storage_swept: 'Bereinigungen',
};

/**
 * "12 Pakete aktualisiert" outside `compact`, or "3 Änderungen um 14:32" inside it — in
 * `compact` every entry already shares the same subject (a subject's own "Aktivität" tab),
 * so naming the subject type there would say nothing a reader does not already know.
 */
function burstHeadline(burst: ActivityBurst): string {
    const sample = burst.entries[0];
    const count = burst.entries.length;

    if (props.compact) {
        const noun = (sample.event && EVENT_NOUNS[sample.event]) ?? 'Ereignisse';

        return `${count} ${noun} um ${timeOfDay(sample.created_at_exact)}`;
    }

    const subjectPlural = (sample.subject_type && SUBJECT_PLURALS[sample.subject_type]) ?? sample.subject_type ?? 'Einträge';
    const verb = (sample.event && EVENT_VERBS[sample.event]) ?? eventLabel(sample.event, sample.description).toLowerCase();

    return `${count} ${subjectPlural} ${verb}`;
}

const selected = ref<ActivityEntry | null>(null);
const detailOpen = ref(false);

function openDetail(entry: ActivityEntry) {
    selected.value = entry;
    detailOpen.value = true;
}
</script>

<template>
    <div>
        <p v-if="activities.length === 0" class="px-1 py-6 text-center text-sm text-muted-foreground">Noch keine Aktivität.</p>

        <div v-else class="flex flex-col gap-6">
            <section v-for="group in displayGroups" :key="group.key" class="flex flex-col gap-2">
                <h3 v-if="!compact" class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ group.label }}</h3>

                <ol class="flex flex-col border-l border-sidebar-border/70 dark:border-sidebar-border">
                    <li v-for="row in group.rows" :key="row.type === 'burst' ? row.key : row.entry.id" class="relative">
                        <button
                            v-if="row.type === 'single'"
                            type="button"
                            class="flex w-full flex-wrap items-center gap-2 rounded-md py-2 pr-2 pl-6 text-left text-sm hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                            @click="openDetail(row.entry)"
                        >
                            <span aria-hidden="true" :class="cn('absolute top-3.5 -left-[5px] size-2.5 rounded-full border', eventClass(row.entry.event))" />

                            <time class="w-10 shrink-0 font-mono text-xs text-muted-foreground" :title="row.entry.created_at ?? ''">
                                {{ timeOfDay(row.entry.created_at_exact) }}
                            </time>

                            <span :class="cn('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium', eventClass(row.entry.event))">
                                {{ eventLabel(row.entry.event, row.entry.description) }}
                            </span>

                            <span v-if="extraDescription(row.entry.event, row.entry.description)" class="text-muted-foreground">
                                {{ extraDescription(row.entry.event, row.entry.description) }}
                            </span>

                            <span v-if="showSubject && row.entry.subject_type" class="text-muted-foreground">
                                {{ row.entry.subject_type }}<span v-if="row.entry.subject_label"> · {{ row.entry.subject_label }}</span>
                            </span>

                            <span class="ml-auto text-xs text-muted-foreground">von {{ row.entry.causer ?? 'System' }}</span>
                        </button>

                        <template v-else>
                            <button
                                type="button"
                                class="flex w-full flex-wrap items-center gap-2 rounded-md py-2 pr-2 pl-6 text-left text-sm hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                :aria-expanded="expanded.has(row.key)"
                                @click="toggleBurst(row.key)"
                            >
                                <span aria-hidden="true" :class="cn('absolute top-3.5 -left-[5px] size-2.5 rounded-full border', eventClass(row.entries[0].event))" />

                                <ChevronRight :class="cn('size-4 shrink-0 text-muted-foreground transition-transform', expanded.has(row.key) && 'rotate-90')" />

                                <span class="font-medium">{{ burstHeadline(row) }}</span>

                                <span v-if="row.outcome" class="text-xs text-muted-foreground">({{ row.outcome }})</span>

                                <span class="ml-auto text-xs text-muted-foreground">von {{ row.entries[0].causer ?? 'System' }}</span>
                            </button>

                            <ol v-if="expanded.has(row.key)" class="mt-1 ml-6 flex flex-col border-l border-sidebar-border/70 dark:border-sidebar-border">
                                <li v-for="entry in row.entries" :key="entry.id" class="relative">
                                    <button
                                        type="button"
                                        class="flex w-full flex-wrap items-center gap-2 rounded-md py-2 pr-2 pl-6 text-left text-sm hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                        @click="openDetail(entry)"
                                    >
                                        <span aria-hidden="true" :class="cn('absolute top-3.5 -left-[5px] size-2.5 rounded-full border', eventClass(entry.event))" />

                                        <time class="w-10 shrink-0 font-mono text-xs text-muted-foreground" :title="entry.created_at ?? ''">
                                            {{ timeOfDay(entry.created_at_exact) }}
                                        </time>

                                        <span :class="cn('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium', eventClass(entry.event))">
                                            {{ eventLabel(entry.event, entry.description) }}
                                        </span>

                                        <span v-if="extraDescription(entry.event, entry.description)" class="text-muted-foreground">
                                            {{ extraDescription(entry.event, entry.description) }}
                                        </span>

                                        <span v-if="showSubject && entry.subject_type" class="text-muted-foreground">
                                            {{ entry.subject_type }}<span v-if="entry.subject_label"> · {{ entry.subject_label }}</span>
                                        </span>

                                        <span class="ml-auto text-xs text-muted-foreground">von {{ entry.causer ?? 'System' }}</span>
                                    </button>
                                </li>
                            </ol>
                        </template>
                    </li>
                </ol>
            </section>
        </div>

        <ActivityDetailDialog v-model:open="detailOpen" :entry="selected" />
    </div>
</template>
