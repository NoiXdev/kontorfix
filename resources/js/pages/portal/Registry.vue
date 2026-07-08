<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Check, Copy } from 'lucide-vue-next';
import { ref } from 'vue';

interface Registry {
    id: string;
    name: string;
    slug: string;
    url: string;
}

interface Snippets {
    composer: string;
    auth: string;
    npm: string;
}

interface PackageRow {
    name: string;
    type: string;
    description: string | null;
    latest_version: string | null;
}

const props = defineProps<{
    registry: Registry;
    snippets: Snippets;
    packages: PackageRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Registries', href: '/portal' },
    { title: props.registry.name, href: `/portal/registries/${props.registry.id}` },
];

const copiedKey = ref<string | null>(null);

async function copy(text: string, key: string) {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => {
            if (copiedKey.value === key) {
                copiedKey.value = null;
            }
        }, 2000);
    } catch {
        // Clipboard-API nicht verfügbar (unsicherer Kontext) — der Inhalt ist markierbar.
        copiedKey.value = null;
    }
}

const steps = [
    { key: 'composer', title: 'Composer einrichten', content: () => props.snippets.composer },
    { key: 'auth', title: 'Zugang einrichten', content: () => props.snippets.auth },
    { key: 'npm', title: 'npm einrichten', content: () => props.snippets.npm },
];
</script>

<template>
    <Head :title="props.registry.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">{{ props.registry.name }}</h1>
                <p class="mt-1 break-all font-mono text-sm text-muted-foreground">{{ props.registry.url }}</p>
            </div>

            <div class="grid gap-4">
                <div
                    v-for="step in steps"
                    :key="step.key"
                    class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                >
                    <div class="flex items-center justify-between gap-4 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                        <h2 class="font-medium">{{ step.title }}</h2>
                        <Button variant="outline" size="sm" @click="copy(step.content(), step.key)">
                            <component :is="copiedKey === step.key ? Check : Copy" class="size-4" />
                            {{ copiedKey === step.key ? 'Kopiert!' : 'Kopieren' }}
                        </Button>
                    </div>
                    <pre class="overflow-x-auto px-4 py-3 font-mono text-sm">{{ step.content() }}</pre>
                </div>
            </div>

            <div>
                <h2 class="mb-3 font-medium">Pakete</h2>
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Typ</th>
                                <th class="px-4 py-3 font-medium">Letzte Version</th>
                                <th class="px-4 py-3 font-medium">Beschreibung</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="pkg in props.packages"
                                :key="pkg.name"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-mono">{{ pkg.name }}</td>
                                <td class="px-4 py-3">{{ pkg.type }}</td>
                                <td class="px-4 py-3 font-mono text-muted-foreground">{{ pkg.latest_version ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ pkg.description ?? '—' }}</td>
                            </tr>
                            <tr v-if="props.packages.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Noch keine Pakete in dieser Registry.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
