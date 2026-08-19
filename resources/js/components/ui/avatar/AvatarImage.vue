<script setup lang="ts">
import { AvatarImage, type AvatarImageProps } from 'radix-vue';

// `alt` (and other native `<img>` attrs) were never declared here — it reached the
// rendered element via Vue's implicit `$attrs` fallthrough onto radix-vue's `AvatarImage`.
// Adding it directly (rather than extending `ImgHTMLAttributes`, whose optional `src?`
// conflicts with `AvatarImageProps`'s required `src`) types the checker without turning it
// into an actual runtime prop, so that fallthrough is unaffected.
interface Props extends AvatarImageProps {
    alt?: string;
}

const props = defineProps<Props>();
</script>

<template>
    <AvatarImage v-bind="props" class="h-full w-full object-cover" />
</template>
