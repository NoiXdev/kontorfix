<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { PrimitiveProps } from 'radix-vue';
import { Primitive } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. this file's own `data-sidebar` marker)
// for the checker only; it already reaches `Primitive` via Vue's implicit `$attrs`
// fallthrough.
//
// This is deliberately an inline intersection in `defineProps<...>()`, not a named
// `interface Props extends ... { }`: `@vue/compiler-sfc` skips resolving a type node
// whose leading comments contain the literal substring "@vue-ignore" — including a
// comment like this one that only *mentions* the pragma as prose. On a bare `interface
// Props extends ...` declaration, that comment attaches to the interface node itself, so
// the compiler discards the WHOLE interface, inherited members and locally-declared ones
// alike. Inlining the type avoids a named declaration for the comment to attach to.
//
// No `withDefaults(..., { as: ... })` here — deliberately: a `div` is this component's
// genuine intended element, not a fallback default.
const props = defineProps<
    PrimitiveProps &
        /* @vue-ignore */ HTMLAttributes & {
            class?: HTMLAttributes['class'];
        }
>();

// `Primitive`'s own exported type (radix-vue) only declares `as`/`asChild` — it has no
// index signature for arbitrary attrs, even though it forwards everything it receives at
// runtime. Binding `data-sidebar` as a literal template attribute would run into that
// (real, third-party) type gap; going through a `v-bind` object sidesteps the excess-
// property check without changing what actually reaches the DOM.
const dataAttrs = { 'data-sidebar': 'group-label' };
</script>

<template>
    <Primitive
        v-bind="dataAttrs"
        :as="as"
        :as-child="asChild"
        :class="
            cn(
                'flex h-8 shrink-0 items-center rounded-md px-2 text-xs font-medium text-sidebar-foreground/70 outline-hidden ring-sidebar-ring transition-[margin,opa] duration-200 ease-linear focus-visible:ring-2 [&>svg]:size-4 [&>svg]:shrink-0',
                'group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0',
                props.class,
            )
        "
    >
        <slot />
    </Primitive>
</template>
