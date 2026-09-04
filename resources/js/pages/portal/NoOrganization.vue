<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Portal" />

    <AppLayout>
        <div class="flex flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Portal</h1>

            <div class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-muted-foreground dark:border-sidebar-border">
                Dieses Konto gehört noch zu keiner Organisation. Ein Betreiber muss es einer Organisation zuordnen, bevor hier Registries und Pakete
                erscheinen.
            </div>
        </div>
    </AppLayout>
</template>
