<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Separator, type SeparatorProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. the `data-sidebar` marker the sidebar
// separator sets) for the checker only; it already reaches the root via `delegatedProps`'
// implicit `$attrs` fallthrough (radix-vue's own `SeparatorProps` doesn't declare it).
//
// This is deliberately an inline intersection in `defineProps<...>()`, not a named
// `interface Props extends ... { }`: `@vue/compiler-sfc` skips resolving a type node
// whose leading comments contain the literal substring "@vue-ignore" — including a
// comment like this one that only *mentions* the pragma as prose. On a bare `interface
// Props extends ...` declaration, that comment attaches to the interface node itself, so
// the compiler discards the WHOLE interface, inherited members and locally-declared ones
// alike. Inlining the type avoids a named declaration for the comment to attach to.
const props = defineProps<
    SeparatorProps &
        /* @vue-ignore */ HTMLAttributes & {
            class?: HTMLAttributes['class'];
            label?: string;
        }
>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});
</script>

<template>
    <Separator
        v-bind="delegatedProps"
        :class="cn('relative shrink-0 bg-border', props.orientation === 'vertical' ? 'h-full w-px' : 'h-px w-full', props.class)"
    >
        <span
            v-if="props.label"
            :class="
                cn(
                    'absolute left-1/2 top-1/2 flex -translate-x-1/2 -translate-y-1/2 items-center justify-center bg-background text-xs text-muted-foreground',
                    props.orientation === 'vertical' ? 'w-[1px] px-1 py-2' : 'h-[1px] px-2 py-1',
                )
            "
            >{{ props.label }}</span
        >
    </Separator>
</template>
