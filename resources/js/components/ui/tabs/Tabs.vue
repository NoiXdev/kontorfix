<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TabsRoot, type TabsRootEmits, type TabsRootProps, useForwardPropsEmits } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<TabsRootProps & { class?: HTMLAttributes['class'] }>();
const emits = defineEmits<TabsRootEmits>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;
    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <TabsRoot v-bind="forwarded" :class="cn('flex flex-col gap-4', props.class)">
        <slot />
    </TabsRoot>
</template>
