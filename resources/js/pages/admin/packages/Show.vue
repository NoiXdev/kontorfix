<script setup lang="ts">
import ActivityList from '@/components/kontorfix/ActivityList.vue';
import StatusPill from '@/components/kontorfix/StatusPill.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useOperatorChannel, type PackagePayload } from '@/composables/useOperatorChannel';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import { ref } from 'vue';

interface Dependencies {
    runtime: Record<string, string>;
    dev: Record<string, string>;
}

interface VersionRow {
    version: string;
    released_at: string | null;
    reference: string | null;
    dependencies: Dependencies;
    download_count: number;
    dist_size: number | null;
}

function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) {
        return '—';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let i = 0;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return `${value.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

interface GroupRow {
    id: string;
    name: string;
    slug: string;
}

interface ActivityRow {
    id: number;
    log_name: string | null;
    event: string | null;
    description: string;
    subject_type: string | null;
    subject_label: string | null;
    causer: string | null;
    changes: Record<string, unknown>;
    created_at: string | null;
    created_at_exact: string | null;
}

const props = defineProps<{
    package: {
        id: string;
        type: 'composer' | 'npm';
        name: string;
        description: string | null;
        repository_url: string | null;
        sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
        sync_error: string | null;
        synced_at: string | null;
    };
    versions: VersionRow[];
    groups: GroupRow[];
    stats: { downloads: number; storage_bytes: number; versions: number };
    activities: ActivityRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pakete', href: '/admin/packages' },
    { title: props.package.name, href: route('admin.packages.show', props.package.id) },
];

const installCommand =
    props.package.type === 'composer' ? `composer require ${props.package.name}` : `npm install ${props.package.name}`;

function depCount(deps: Record<string, string>): number {
    return Object.keys(deps).length;
}

// Live update of the sync status for the currently displayed package.
// Local state, so the live update doesn't mutate the prop.
const page = usePage<SharedData>();
const syncStatus = ref(props.package.sync_status);
const syncError = ref(props.package.sync_error);

function applyStatus(p: PackagePayload) {
    if (p.id !== props.package.id) {
        return;
    }
    syncStatus.value = p.sync_status as typeof props.package.sync_status;
    syncError.value = p.error ?? null;
}

const isOperator = page.props.auth.can?.console ?? false;
if (isOperator) {
    useOperatorChannel({
        onSynced: applyStatus,
        onFailed: applyStatus,
    });
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
                    <StatusPill :status="syncStatus" />
                </div>
                <p v-if="props.package.description" class="max-w-2xl text-sm text-muted-foreground">
                    {{ props.package.description }}
                </p>
                <a
                    v-if="props.package.repository_url"
                    :href="props.package.repository_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex w-fit items-center gap-1.5 text-sm text-verdigris hover:underline"
                >
                    <ExternalLink class="size-3.5" />
                    {{ props.package.repository_url }}
                </a>
                <div v-if="props.package.synced_at" class="text-xs text-muted-foreground">
                    Zuletzt synchronisiert: {{ props.package.synced_at }}
                </div>
                <div
                    v-if="syncError"
                    class="rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400"
                >
                    {{ syncError }}
                </div>

                <!-- Usage stats -->
                <div class="grid max-w-lg grid-cols-3 gap-3">
                    <div class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                        <div class="text-xs text-muted-foreground">Downloads</div>
                        <div class="text-lg font-semibold">{{ props.stats.downloads.toLocaleString('de-DE') }}</div>
                    </div>
                    <div class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                        <div class="text-xs text-muted-foreground">Speicher</div>
                        <div class="text-lg font-semibold">{{ formatBytes(props.stats.storage_bytes) }}</div>
                    </div>
                    <div class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                        <div class="text-xs text-muted-foreground">Versionen</div>
                        <div class="text-lg font-semibold">{{ props.stats.versions }}</div>
                    </div>
                </div>
            </div>

            <Tabs default-value="installation">
                <TabsList>
                    <TabsTrigger value="installation">Installation</TabsTrigger>
                    <TabsTrigger value="registries">Registries</TabsTrigger>
                    <TabsTrigger value="versionen">Versionen ({{ props.versions.length }})</TabsTrigger>
                    <TabsTrigger value="aktivitaet">Aktivität</TabsTrigger>
                </TabsList>

                <TabsContent value="installation">
                    <section class="flex flex-col gap-3">
                        <pre
                            class="overflow-x-auto rounded-md border border-sidebar-border/70 bg-muted/50 px-4 py-3 font-mono text-sm dark:border-sidebar-border"
                            >{{ installCommand }}</pre
                        >
                    </section>
                </TabsContent>

                <TabsContent value="registries">
                    <section class="flex flex-col gap-3">
                        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">Name</th>
                                        <th class="px-4 py-3 font-medium">Pfad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="group in props.groups"
                                        :key="group.id"
                                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td class="px-4 py-3">
                                            <Link :href="route('admin.groups.index')" class="hover:underline">{{ group.name }}</Link>
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">/r/{{ group.slug }}</td>
                                    </tr>
                                    <tr v-if="props.groups.length === 0">
                                        <td colspan="2" class="px-4 py-8 text-center text-muted-foreground">Keiner Registry zugeordnet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </TabsContent>

                <TabsContent value="versionen">
                    <section class="flex flex-col gap-3">
                        <div
                            v-if="props.versions.length === 0"
                            class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
                        >
                            Noch keine Versionen synchronisiert.
                        </div>
                        <div
                            v-for="version in props.versions"
                            :key="version.version"
                            class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="font-mono text-sm font-semibold">{{ version.version }}</span>
                                <span v-if="version.released_at" class="text-xs text-muted-foreground">{{ version.released_at }}</span>
                                <span v-if="version.reference" class="font-mono text-xs text-muted-foreground">
                                    {{ version.reference.slice(0, 12) }}
                                </span>
                                <span class="ml-auto text-xs text-muted-foreground">
                                    {{ version.download_count.toLocaleString('de-DE') }} Downloads · {{ formatBytes(version.dist_size) }}
                                </span>
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
                </TabsContent>

                <TabsContent value="aktivitaet">
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <ActivityList :activities="props.activities" />
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>

