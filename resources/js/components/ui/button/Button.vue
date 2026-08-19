<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Primitive, type PrimitiveProps } from 'radix-vue';
import type { AnchorHTMLAttributes, ButtonHTMLAttributes, HTMLAttributes } from 'vue';
import { buttonVariants, type ButtonVariants } from '.';

// `Primitive` renders whatever `as`/`as-child` resolves to (a `<button>` by default, but
// also used as `as="a"` for link-styled buttons elsewhere in the app) and forwards every
// extra attribute it receives onto that root element via Vue's implicit `$attrs`
// fallthrough (native attrs/listeners like `type`, `disabled`, `onClick`, `href`, ... were
// never declared props here, they just fell through). `strictTemplates` checks call sites
// against declared props only, so it now needs these listed — but actually declaring them
// as runtime props would remove them from `$attrs` and silently break the fallthrough.
// `/* @vue-ignore */` keeps `ButtonHTMLAttributes` a type-only extension: per the Vue SFC
// compiler itself ("properties in the base type are treated as fallthrough attrs at
// runtime"), this satisfies the type checker without changing runtime behaviour at all.
interface Props extends PrimitiveProps, /* @vue-ignore */ ButtonHTMLAttributes {
    variant?: ButtonVariants['variant'];
    size?: ButtonVariants['size'];
    href?: AnchorHTMLAttributes['href'];
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    as: 'button',
});
</script>

<template>
    <Primitive :as="as ?? 'button'" :as-child="asChild" :class="cn(buttonVariants({ variant, size }), props.class)">
        <slot />
    </Primitive>
</template>
