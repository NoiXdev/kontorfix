<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. the `data-sidebar` marker the sidebar
// skeleton sets) for the checker only; it already reaches the root `<div>` via Vue's
// implicit `$attrs` fallthrough.
//
// This is deliberately an inline intersection in `defineProps<...>()`, not a named
// `interface SkeletonProps extends ... { }`, AND the locally-declared object type comes
// FIRST in the intersection, before the `/* @vue-ignore */`-marked member:
//
// `@vue/compiler-sfc` skips resolving a type node whose leading comments contain the
// literal substring "@vue-ignore" — including a comment like this one that only
// *mentions* the pragma as prose. On a bare `interface X extends ...` declaration, that
// comment attaches to the interface node itself and the compiler discards the WHOLE
// interface. Inlining the type avoids a named declaration for the comment to attach to —
// but if the vue-ignored member were FIRST inside `defineProps<...>`, the compiler would
// still attach the leading comment to the outer intersection node (it starts at the same
// source position as the first member), reproducing the same wipeout. A real,
// non-ignored member first absorbs that attachment instead.
const props = defineProps<
    {
        class?: HTMLAttributes['class'];
    } & /* @vue-ignore */ HTMLAttributes
>();
</script>

<template>
    <div :class="cn('animate-pulse rounded-md bg-muted', props.class)" />
</template>
