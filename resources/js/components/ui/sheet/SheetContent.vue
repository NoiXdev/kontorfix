<script setup lang="ts">
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    useForwardPropsEmits,
    type DialogContentEmits,
    type DialogContentProps,
} from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';
import { sheetVariants, type SheetVariants } from '.';

// `/* @vue-ignore */` types `HTMLAttributes` (e.g. the `data-sidebar`/`data-mobile`
// markers the sidebar component sets on this content) for the checker only; the explicit
// `...$attrs` spread below (with `inheritAttrs: false`) already forwards them at runtime.
//
// This is deliberately an inline intersection in `defineProps<...>()`, not a named
// `interface SheetContentProps extends ... { }`: `@vue/compiler-sfc` skips resolving a
// type node whose leading comments contain the literal substring "@vue-ignore" —
// including a comment like this one that only *mentions* the pragma as prose. On a bare
// `interface X extends ...` declaration, that comment attaches to the interface node
// itself, so the compiler discards the WHOLE interface, inherited members and
// locally-declared ones alike. Inlining the type avoids a named declaration for the
// comment to attach to.
defineOptions({
    inheritAttrs: false,
});

const props = defineProps<
    DialogContentProps &
        /* @vue-ignore */ HTMLAttributes & {
            class?: HTMLAttributes['class'];
            side?: SheetVariants['side'];
        }
>();

const emits = defineEmits<DialogContentEmits>();

const delegatedProps = computed(() => {
    const { class: _, side, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <DialogPortal>
        <DialogOverlay
            class="fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0"
        />
        <DialogContent :class="cn(sheetVariants({ side }), props.class)" v-bind="{ ...forwarded, ...$attrs }">
            <slot />

            <DialogClose
                class="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-hidden focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:pointer-events-none data-[state=open]:bg-secondary"
            >
                <X class="h-4 w-4 text-muted-foreground" />
            </DialogClose>
        </DialogContent>
    </DialogPortal>
</template>
