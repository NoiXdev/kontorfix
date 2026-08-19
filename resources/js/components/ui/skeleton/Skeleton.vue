<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. the `data-sidebar` marker the sidebar
// skeleton sets) for the checker only; it already reaches the root `<div>` via Vue's
// implicit `$attrs` fallthrough.
interface SkeletonProps extends /* @vue-ignore */ HTMLAttributes {
    class?: HTMLAttributes['class'];
}

const props = defineProps<SkeletonProps>();
</script>

<template>
    <div :class="cn('animate-pulse rounded-md bg-muted', props.class)" />
</template>
