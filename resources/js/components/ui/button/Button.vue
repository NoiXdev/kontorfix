<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Primitive, type PrimitiveProps } from 'radix-vue';
import type { AnchorHTMLAttributes, ButtonHTMLAttributes, HTMLAttributes } from 'vue';
import { buttonVariants, type ButtonVariants } from '.';

// `Primitive` renders whatever `as`/`as-child` resolves to (a `<button>` by default, but
// also used as `as="a"` for link-styled buttons elsewhere in the app) and forwards every
// extra attribute it receives onto that root element via Vue's implicit `$attrs`
// fallthrough. `strictTemplates` checks call sites against declared props only, so the
// native attributes need listing — but declaring them as runtime props would remove them
// from `$attrs` and break that fallthrough, hence the type-only extension.
//
// This is deliberately an inline intersection in `defineProps<...>()`, not a named
// `interface Props extends ... { }`. `@vue/compiler-sfc` skips resolving a type node whose
// leading comments contain the literal substring "@vue-ignore". On a named interface that
// comment attaches to the declaration itself, so the compiler discarded the WHOLE
// interface — inherited members AND the locally declared `variant`, `size`, `href` and
// `class`. The component then emitted no runtime props at all: every button in the
// application rendered with cva's default variant and size, and `as` fell back to a
// `<div>`, which made every form unsubmittable. No gate catches this — `vue-tsc` resolves
// `extends` fine on a different code path. `scripts/check-runtime-props.mjs` does.
const props = withDefaults(
    defineProps<
        PrimitiveProps &
            /* @vue-ignore */ ButtonHTMLAttributes & {
                variant?: ButtonVariants['variant'];
                size?: ButtonVariants['size'];
                href?: AnchorHTMLAttributes['href'];
                class?: HTMLAttributes['class'];
            }
    >(),
    {
        as: 'button',
    },
);
</script>

<template>
    <Primitive :as="as ?? 'button'" :as-child="asChild" :class="cn(buttonVariants({ variant, size }), props.class)">
        <slot />
    </Primitive>
</template>
