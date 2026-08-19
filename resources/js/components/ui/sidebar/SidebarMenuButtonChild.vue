<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Primitive, type PrimitiveProps } from 'radix-vue';
import { computed, useAttrs, type HTMLAttributes } from 'vue';
import { sidebarMenuButtonVariants, type SidebarMenuButtonVariants } from '.';

// The exported interface carries NO `@vue-ignore` pragma: that annotation, on a named
// interface declaration, is what made twelve components in this codebase emit zero runtime
// props at all — see "A named props interface can silently emit no runtime props at all" in
// docs/development.md. The pragma sits on the inlined intersection below instead, where it
// applies only to the type it annotates.
//
// The interface stays exported because `SidebarMenuButton.vue` consumes it.
export interface SidebarMenuButtonProps extends PrimitiveProps {
    variant?: SidebarMenuButtonVariants['variant'];
    size?: SidebarMenuButtonVariants['size'];
    isActive?: boolean;
    class?: HTMLAttributes['class'];
}

// `HTMLAttributes` — this file's own `data-sidebar` marker, and whatever
// `SidebarMenuButton.vue`'s explicit `...$attrs` spread passes down — is typed for the
// checker only; it already reaches `Primitive` through the `v-bind="$attrs"` below.
const props = withDefaults(
    defineProps<SidebarMenuButtonProps & /* @vue-ignore */ HTMLAttributes>(), {
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
