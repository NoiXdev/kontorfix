<script setup lang="ts">
import { cn } from '@/lib/utils';
import { useVModel } from '@vueuse/core';
import type { HTMLAttributes, InputHTMLAttributes } from 'vue';

// Native attrs (`id`, `type`, `placeholder`, `disabled`, ...) were never declared props
// here — they reached the rendered `<input>` via Vue's implicit `$attrs` fallthrough.
// `/* @vue-ignore */` declares `InputHTMLAttributes` for the type checker only, without
// turning its members into actual runtime props, so that fallthrough is unaffected.
interface Props extends /* @vue-ignore */ InputHTMLAttributes {
    defaultValue?: string | number;
    modelValue?: string | number;
    class?: HTMLAttributes['class'];
    // Two call sites use `v-model.number="..."` on this component. Vue always passes the
    // modifiers object for whatever a `v-model` target is, component or native element, so
    // this prop already arrives at runtime today — it's just undeclared. This component has
    // never read it (no coercion happens here), so declaring it changes no behaviour; it
    // only makes the type checker aware of a prop Vue already sends.
    modelModifiers?: Record<string, boolean>;
}

const props = defineProps<Props>();

// The payload is typed `string`, not `string | number`: the template below drives
// `modelValue` off a plain `<input v-model>` with no `.number` modifier, and a native
// input's `v-model` always yields a string regardless of `type="number"` (only
// `.valueAsNumber` is numeric — `.value` never is). `modelValue`/`defaultValue` stay
// `string | number` because callers may still pass a number in as the initial value.
const emits = defineEmits<{
    (e: 'update:modelValue', payload: string): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
    passive: true,
    defaultValue: props.defaultValue,
});
</script>

<template>
    <input
        v-model="modelValue"
        :class="
            cn(
                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                props.class,
            )
        "
    />
</template>
