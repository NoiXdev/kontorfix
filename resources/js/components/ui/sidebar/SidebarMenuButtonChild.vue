<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Primitive, type PrimitiveProps } from 'radix-vue';
import { computed, useAttrs, type HTMLAttributes } from 'vue';
import { sidebarMenuButtonVariants, type SidebarMenuButtonVariants } from '.';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. this file's own `data-sidebar` marker,
// and whatever `SidebarMenuButton.vue`'s explicit `...$attrs` spread passes down) for the
// checker only; it already reaches `Primitive` via the explicit `v-bind="$attrs"` below.
export interface SidebarMenuButtonProps extends PrimitiveProps, /* @vue-ignore */ HTMLAttributes {
    variant?: SidebarMenuButtonVariants['variant'];
    size?: SidebarMenuButtonVariants['size'];
    isActive?: boolean;
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<SidebarMenuButtonProps>(), {
    as: 'button',
    variant: 'default',
    size: 'default',
});

// `Primitive`'s own exported type (radix-vue) only declares `as`/`asChild` — it has no
// index signature for arbitrary attrs, even though it forwards everything it receives at
// runtime. Binding these as literal template attributes would run into that (real,
// third-party) type gap; going through a `v-bind` object sidesteps the excess-property
// check without changing what actually reaches the DOM.
const attrs = useAttrs();
const dataAttrs = computed(() => ({
    'data-sidebar': 'menu-button',
    'data-size': props.size,
    'data-active': props.isActive,
    ...attrs,
}));
</script>

<template>
    <Primitive
        v-bind="dataAttrs"
        :class="cn(sidebarMenuButtonVariants({ variant, size }), props.class)"
        :as="as ?? 'button'"
        :as-child="asChild"
    >
        <slot />
    </Primitive>
</template>
