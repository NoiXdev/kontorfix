<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed } from 'vue';

const props = defineProps<{
    type: 'composer' | 'npm';
}>();

const classes = computed(() =>
    props.type === 'composer' ? 'bg-copper/15 text-copper-hi border-copper/30' : 'bg-verdigris/15 text-verdigris border-verdigris/30',
);
</script>

<template>
    <span :class="cn('inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-xs font-medium', classes)">
        {{ type }}
    </span>
</template>
