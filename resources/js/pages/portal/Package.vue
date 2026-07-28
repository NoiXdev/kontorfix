<script setup lang="ts">
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
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

interface Dependencies {
    runtime: Record<string, string>;
    dev: Record<string, string>;
}

interface VersionRow {
    version: string;
    released_at: string | null;
    dependencies: Dependencies;
}

const props = defineProps<{
    registry: Registry;
    package: {
        type: 'composer' | 'npm';
        name: string;
        description: string | null;
        sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
    };
    versions: VersionRow[];
    install: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Registries', href: '/portal' },
    { title: props.registry.name, href: `/portal/registries/${props.registry.id}` },
    { title: props.package.name, href: `/portal/registries/${props.registry.id}/packages` },
];

const copied = ref(false);

async function copyInstall() {
    try {
        await navigator.clipboard.writeText(props.install);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard API not available (insecure context) — the command can be selected manually.
        copied.value = false;
    }
}

function depCount(deps: Record<string, string>): number {
    return Object.keys(deps).length;
}
</script>

<template>
    <Head :title="props.package.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="font-mono text-2xl font-semibold">{{ props.package.name }}</h1>
                    <TypeBadge :type="props.package.type" />
                </div>
                <p v-if="props.package.description" class="max-w-2xl text-sm text-muted-foreground">
                    {{ props.package.description }}
                </p>
                <p class="text-sm text-muted-foreground">
                    In Registry
                    <span class="font-medium text-foreground">{{ props.registry.name }}</span>
                    <span class="ml-2 break-all font-mono text-xs">{{ props.registry.url }}</span>
                </p>
            </div>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Installation</h2>
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <div class="flex items-center justify-between gap-4 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                        <h3 class="font-medium">Paket installieren</h3>
                        <Button variant="outline" size="sm" @click="copyInstall">
                            <component :is="copied ? Check : Copy" class="size-4" />
                            {{ copied ? 'Kopiert!' : 'Kopieren' }}
                        </Button>
                    </div>
                    <pre class="overflow-x-auto px-4 py-3 font-mono text-sm">{{ props.install }}</pre>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Versionen</h2>
                <div
                    v-if="props.versions.length === 0"
                    class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
                >
                    Noch keine Versionen verfügbar.
                </div>
                <div
                    v-for="version in props.versions"
                    :key="version.version"
                    class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-mono text-sm font-semibold">{{ version.version }}</span>
                        <span v-if="version.released_at" class="text-xs text-muted-foreground">{{ version.released_at }}</span>
                    </div>

                    <details v-if="depCount(version.dependencies.runtime) > 0" class="mt-3">
                        <summary class="cursor-pointer text-sm font-medium">
                            Abhängigkeiten ({{ depCount(version.dependencies.runtime) }})
                        </summary>
                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="(constraint, name) in version.dependencies.runtime"
                                :key="name"
                                class="flex gap-2 font-mono text-xs"
                            >
                                <span>{{ name }}</span>
                                <span class="text-muted-foreground">{{ constraint }}</span>
                            </li>
                        </ul>
                    </details>

                    <details v-if="depCount(version.dependencies.dev) > 0" class="mt-2">
                        <summary class="cursor-pointer text-sm font-medium">
                            Dev-Abhängigkeiten ({{ depCount(version.dependencies.dev) }})
                        </summary>
                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="(constraint, name) in version.dependencies.dev"
                                :key="name"
                                class="flex gap-2 font-mono text-xs"
                            >
                                <span>{{ name }}</span>
                                <span class="text-muted-foreground">{{ constraint }}</span>
                            </li>
                        </ul>
                    </details>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
