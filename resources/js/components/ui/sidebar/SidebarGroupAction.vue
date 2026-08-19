<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { PrimitiveProps } from 'radix-vue';
import { Primitive } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. this file's own `data-sidebar` marker)
// for the checker only; it already reaches `Primitive` via Vue's implicit `$attrs`
// fallthrough.
interface Props extends PrimitiveProps, /* @vue-ignore */ HTMLAttributes {
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();

// `Primitive`'s own exported type (radix-vue) only declares `as`/`asChild` — it has no
// index signature for arbitrary attrs, even though it forwards everything it receives at
// runtime. Binding `data-sidebar` as a literal template attribute would run into that
// (real, third-party) type gap; going through a `v-bind` object sidesteps the excess-
// property check without changing what actually reaches the DOM.
const dataAttrs = { 'data-sidebar': 'group-action' };
</script>

<template>
    <Primitive
        v-bind="dataAttrs"
        :as="as"
        :as-child="asChild"
        :class="
            cn(
                'absolute right-3 top-3.5 flex aspect-square w-5 items-center justify-center rounded-md p-0 text-sidebar-foreground outline-hidden ring-sidebar-ring transition-transform hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0',
                // Increases the hit area of the button on mobile.
                'after:absolute after:-inset-2 after:md:hidden',
                'group-data-[collapsible=icon]:hidden',
                props.class,
            )
        "
    >
        <slot />
    </Primitive>
</template>
