<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TooltipContent, TooltipPortal, useForwardPropsEmits, type TooltipContentEmits, type TooltipContentProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

// `/* @vue-ignore */` types `HTMLAttributes` (native attrs like `hidden`) for the checker
// only; the explicit `...$attrs` spread below already forwards them at runtime.
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
        TooltipContentProps &
            /* @vue-ignore */ HTMLAttributes & {
                class?: HTMLAttributes['class'];
            }
    >(),
    {
        sideOffset: 4,
    },
);

const emits = defineEmits<TooltipContentEmits>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <TooltipPortal>
        <TooltipContent
            v-bind="{ ...forwarded, ...$attrs }"
            :class="
                cn(
                    'z-50 overflow-hidden rounded-md border bg-popover px-3 py-1.5 text-sm text-popover-foreground shadow-md animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
                    props.class,
                )
            "
        >
            <slot />
        </TooltipContent>
    </TooltipPortal>
</template>
