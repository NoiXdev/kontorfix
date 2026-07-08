<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

const props = defineProps<{
    status: 'pending' | 'syncing' | 'synced' | 'failed';
}>();

const labels: Record<typeof props.status, string> = {
    pending: 'Wartet',
    syncing: 'Läuft…',
    synced: 'Synchronisiert',
    failed: 'Fehlgeschlagen',
};

const classes: Record<typeof props.status, string> = {
    pending: 'bg-muted text-muted-foreground border-border',
    syncing: 'bg-amber-500/15 text-amber-600 border-amber-500/30 dark:text-amber-400',
    synced: 'bg-emerald-500/15 text-emerald-600 border-emerald-500/30 dark:text-emerald-400',
    failed: 'bg-red-500/15 text-red-600 border-red-500/30 dark:text-red-400',
};

const label = computed(() => labels[props.status]);
const classNames = computed(() => classes[props.status]);
</script>

<template>
    <span :class="cn('inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium', classNames)">
        {{ label }}
    </span>
</template>
