<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { PrimitiveProps } from 'radix-vue';
import { Primitive } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. this file's own `data-sidebar` marker)
// for the checker only; it already reaches `Primitive` via Vue's implicit `$attrs`
// fallthrough.
interface Props extends PrimitiveProps, /* @vue-ignore */ HTMLAttributes {
    size?: 'sm' | 'md';
    isActive?: boolean;
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    as: 'a',
    size: 'md',
});

// `Primitive`'s own exported type (radix-vue) only declares `as`/`asChild` — it has no
// index signature for arbitrary attrs, even though it forwards everything it receives at
// runtime. Binding these as literal template attributes would run into that (real,
// third-party) type gap; going through a `v-bind` object sidesteps the excess-property
// check without changing what actually reaches the DOM.
const dataAttrs = computed(() => ({
    'data-sidebar': 'menu-sub-button',
    'data-size': props.size,
    'data-active': props.isActive,
}));
</script>

<template>
    <Primitive
        v-bind="dataAttrs"
        :as="as"
        :as-child="asChild"
        :class="
            cn(
                'flex h-7 min-w-0 -translate-x-px items-center gap-2 overflow-hidden rounded-md px-2 text-sidebar-foreground outline-hidden ring-sidebar-ring hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 active:bg-sidebar-accent active:text-sidebar-accent-foreground disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0 [&>svg]:text-sidebar-accent-foreground',
                'data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground',
                size === 'sm' && 'text-xs',
                size === 'md' && 'text-sm',
                'group-data-[collapsible=icon]:hidden',
                props.class,
            )
        "
    >
        <slot />
    </Primitive>
</template>
