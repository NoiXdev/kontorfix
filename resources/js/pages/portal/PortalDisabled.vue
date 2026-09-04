<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    // The viewer's own organization, by name. Safe to name here — see PackageController::
    // home(), which decides that this page is reached only by one of its members.
    organization: string;
}>();
</script>

<template>
    <Head title="Portal" />

    <AppLayout>
        <div class="flex flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Portal</h1>

            <div class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-muted-foreground dark:border-sidebar-border">
                Das Portal von {{ organization }} ist derzeit deaktiviert. Ein Betreiber kann es wieder aktivieren; Registries und Pakete erscheinen
                hier erst danach.
            </div>
        </div>
    </AppLayout>
</template>
