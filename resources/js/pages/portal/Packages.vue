<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

interface PackageRow {
    id: string;
    name: string;
}

// A stub for now: the portal's landing page exists so that /c/{orgSlug} resolves to a
// real component. The list itself, with its badges and its lapsed-assignment note,
// arrives with the portal package set.
const props = defineProps<{
    // The organization the URL addresses, not the viewer's own — an operator looking at a
    // customer's portal has to keep navigating inside that customer's portal.
    orgSlug: string;
    packages: PackageRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pakete', href: route('portal.packages.index', props.orgSlug) }];
</script>

<template>
    <Head title="Pakete" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <h1 class="text-xl font-semibold">Pakete</h1>

            <div
                v-if="props.packages.length === 0"
                class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-muted-foreground dark:border-sidebar-border"
            >
                Noch keine Pakete verfügbar.
            </div>
        </div>
    </AppLayout>
</template>
