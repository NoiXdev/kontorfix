<script setup lang="ts">
interface Props {
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <!-- Farbige Kontorfix-Marke (Speichergiebel) — feste Markenfarben, siehe docs/brand. -->
    <div class="flex aspect-square size-8 items-center justify-center">
        <svg viewBox="0 0 96 96" class="size-8" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Kontorfix">
            <rect width="96" height="96" rx="20" fill="#151F2E" />
            <circle cx="48" cy="21.5" r="3" fill="#7FB5A2" />
            <path d="M18 78V54h12V42h12V30h12v12h12v12h12v24H18Z" fill="#D07A45" />
            <rect x="43" y="60" width="10" height="18" fill="#151F2E" />
        </svg>
    </div>
    <div class="ml-1.5 grid flex-1 text-left">
        <span class="truncate font-display text-base leading-none font-extrabold tracking-tight lowercase">
            kontor<span class="text-copper">fix</span>
        </span>
    </div>
</template>
