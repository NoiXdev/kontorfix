<script setup lang="ts">
import ActivityDetailDialog from '@/components/kontorfix/ActivityDetailDialog.vue';
import { eventClass, eventLabel, extraDescription } from '@/lib/activityEvents';
import { groupByDay, timeOfDay, type ActivityEntry, type ActivityGroup } from '@/lib/activityGroups';
import { cn } from '@/lib/utils';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ activities: ActivityEntry[]; showSubject?: boolean; compact?: boolean }>(), {
    showSubject: false,
    compact: false,
});

/**
 * Day headings come from `groupByDay`; `compact` skips it entirely rather than rendering
 * its groups without their labels. Unlabelled groups would still be drawn as separate
 * lists, which is the day grouping back again minus the one thing that explains it.
 */
const groups = computed<ActivityGroup[]>(() =>
    props.compact ? [{ key: 'all', label: '', entries: props.activities }] : groupByDay(props.activities),
);

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
            <section v-for="group in groups" :key="group.key" class="flex flex-col gap-2">
                <h3 v-if="!compact" class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ group.label }}</h3>

                <ol class="flex flex-col border-l border-sidebar-border/70 dark:border-sidebar-border">
                    <li v-for="entry in group.entries" :key="entry.id" class="relative">
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
            </section>
        </div>

        <ActivityDetailDialog v-model:open="detailOpen" :entry="selected" />
    </div>
</template>
