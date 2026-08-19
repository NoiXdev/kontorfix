<script setup lang="ts">
import { cn } from '@/lib/utils';
import { SwitchRoot, SwitchThumb } from 'radix-vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    id?: string;
    disabled?: boolean;
}>();

// A boolean setting, not a selection. Anything that binds into an array — picking several
// registry types, several webhook events — stays a checkbox, because a switch says
// "on or off" about one thing.
const checked = defineModel<boolean>({ default: false });
</script>

<template>
    <SwitchRoot
        :id="props.id"
        v-model:checked="checked"
        :disabled="props.disabled"
        :class="
            cn(
                'peer inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors',
                'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'data-[state=checked]:bg-primary data-[state=unchecked]:bg-input',
                props.class,
            )
        "
    >
        <SwitchThumb
            class="pointer-events-none block size-4 rounded-full bg-background shadow-lg ring-0 transition-transform data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0"
        />
    </SwitchRoot>
</template>
