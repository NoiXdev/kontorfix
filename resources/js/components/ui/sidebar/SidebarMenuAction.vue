<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Primitive, type PrimitiveProps } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. this file's own `data-sidebar` marker)
// for the checker only; it already reaches `Primitive` via Vue's implicit `$attrs`
// fallthrough.
interface Props extends PrimitiveProps, /* @vue-ignore */ HTMLAttributes {
    showOnHover?: boolean;
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    as: 'button',
});

// `Primitive`'s own exported type (radix-vue) only declares `as`/`asChild` — it has no
// index signature for arbitrary attrs, even though it forwards everything it receives at
// runtime. Binding `data-sidebar` as a literal template attribute would run into that
// (real, third-party) type gap; going through a `v-bind` object sidesteps the excess-
// property check without changing what actually reaches the DOM.
const dataAttrs = { 'data-sidebar': 'menu-action' };
</script>

<template>
    <Primitive
        v-bind="dataAttrs"
        :class="
            cn(
                'absolute right-1 top-1.5 flex aspect-square w-5 items-center justify-center rounded-md p-0 text-sidebar-foreground outline-hidden ring-sidebar-ring transition-transform hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 peer-hover/menu-button:text-sidebar-accent-foreground [&>svg]:size-4 [&>svg]:shrink-0',
                // Increases the hit area of the button on mobile.
                'after:absolute after:-inset-2 after:md:hidden',
                'peer-data-[size=sm]/menu-button:top-1',
                'peer-data-[size=default]/menu-button:top-1.5',
                'peer-data-[size=lg]/menu-button:top-2.5',
                'group-data-[collapsible=icon]:hidden',
                showOnHover &&
                    'group-focus-within/menu-item:opacity-100 group-hover/menu-item:opacity-100 data-[state=open]:opacity-100 peer-data-[active=true]/menu-button:text-sidebar-accent-foreground md:opacity-0',
                props.class,
            )
        "
        :as="as"
        :as-child="asChild"
    >
        <slot />
    </Primitive>
</template>
