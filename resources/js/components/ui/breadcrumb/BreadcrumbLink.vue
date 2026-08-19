<script lang="ts" setup>
import { cn } from '@/lib/utils';
import { Primitive, type PrimitiveProps } from 'radix-vue';
import type { AnchorHTMLAttributes, HTMLAttributes } from 'vue';

// Renders as `<a>` by default (`href` reaches it via Vue's implicit `$attrs` fallthrough,
// same as before). `/* @vue-ignore */` types `AnchorHTMLAttributes` for the checker only.
//
// This is deliberately an inline intersection in `defineProps<...>()`, not a named
// `interface Props extends ... { }`: `@vue/compiler-sfc` skips resolving a type node
// whose leading comments contain the literal substring "@vue-ignore" — including a
// comment like this one that only *mentions* the pragma as prose. On a bare `interface
// Props extends ...` declaration, that comment attaches to the interface node itself, so
// the compiler discards the WHOLE interface, inherited members and locally-declared ones
// alike. Inlining the type avoids a named declaration for the comment to attach to.
const props = withDefaults(
    defineProps<
        PrimitiveProps &
            /* @vue-ignore */ AnchorHTMLAttributes & {
                class?: HTMLAttributes['class'];
            }
    >(),
    {
        as: 'a',
    },
);
</script>

<template>
    <Primitive :as="as ?? 'a'" :as-child="asChild" :class="cn('transition-colors hover:text-foreground', props.class)">
        <slot />
    </Primitive>
</template>
