<script lang="ts" setup>
import { cn } from '@/lib/utils';
import { Primitive, type PrimitiveProps } from 'radix-vue';
import type { AnchorHTMLAttributes, HTMLAttributes } from 'vue';

// Renders as `<a>` by default (`href` reaches it via Vue's implicit `$attrs` fallthrough,
// same as before). `/* @vue-ignore */` types `AnchorHTMLAttributes` for the checker only.
interface Props extends PrimitiveProps, /* @vue-ignore */ AnchorHTMLAttributes {
    class?: HTMLAttributes['class'];
}

const props = withDefaults(defineProps<Props>(), {
    as: 'a',
});
</script>

<template>
    <Primitive :as="as" :as-child="asChild" :class="cn('transition-colors hover:text-foreground', props.class)">
        <slot />
    </Primitive>
</template>
