<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

const props = defineProps<{
    type: 'composer' | 'npm' | 'python' | 'docker';
}>();

const classes = computed(
    () =>
        ({
            composer: 'bg-copper/15 text-copper-hi border-copper/30',
            npm: 'bg-verdigris/15 text-verdigris border-verdigris/30',
            python: 'bg-sky-500/15 text-sky-600 border-sky-500/30 dark:text-sky-400',
            // Neutral, matching plate 2's `pill-neutral`: unlike the other three ecosystems,
            // a Docker repository is a Package like any other one row down (see
            // DockerTags.vue) — it needs a badge, not the accent that would suggest another
            // distinguishing property.
            docker: 'bg-muted text-muted-foreground border-border',
        })[props.type],
);
</script>

<template>
    <span :class="cn('inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-xs font-medium', classes)">
        {{ type }}
    </span>
</template>
