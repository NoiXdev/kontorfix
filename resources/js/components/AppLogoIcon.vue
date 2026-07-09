<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- Kontorfix „Speichergiebel": Treppengiebel eines Kontorhauses, Tor = Registry-Zugang,
         Punkt = Kranbalken. Einfarbig (currentColor) für getönte Kontexte. -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" :class="className" v-bind="$attrs">
        <circle cx="48" cy="21.5" r="3.5" fill="currentColor" />
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M18 78V54h12V42h12V30h12v12h12v12h12v24H18Zm25 0V60h10v18H43Z"
        />
    </svg>
</template>
