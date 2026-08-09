<script setup lang="ts">
import { useOperatorChannel } from '@/composables/useOperatorChannel';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, Boxes, CheckCircle2, CloudDownload, Globe, KeyRound, Layers, Package } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Stats {
    packages: number;
    composer: number;
    npm: number;
    versions: number;
    groups: number;
    tokens: number;
    domains: number;
    upstreams: number;
    failedJobs: number;
    sync: { synced: number; syncing: number; pending: number; failed: number };
}

interface FailedPackage {
    id: string;
    name: string;
    type: string;
    error: string | null;
    synced_at: string | null;
}

const props = defineProps<{
    stats: Stats;
    recent: Array<{ name: string; type: string; status: string; synced_at: string | null }>;
    failedPackages: FailedPackage[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const tiles = computed(() => [
    { label: 'Pakete', value: props.stats.packages, sub: `${props.stats.composer} composer · ${props.stats.npm} npm`, icon: Package, href: '/admin/packages' },
    { label: 'Registries', value: props.stats.groups, sub: `${props.stats.domains} Domains`, icon: Boxes, href: '/admin/groups' },
    { label: 'Tokens', value: props.stats.tokens, sub: `${props.stats.upstreams} Upstreams`, icon: KeyRound, href: '/admin/tokens' },
]);

const syncSegments = computed(() => [
    { key: 'synced', label: 'Synchronisiert', value: props.stats.sync.synced, dot: 'bg-[#6CBF8B]' },
    { key: 'syncing', label: 'Läuft', value: props.stats.sync.syncing, dot: 'bg-copper' },
    { key: 'pending', label: 'Ausstehend', value: props.stats.sync.pending, dot: 'bg-muted-foreground/50' },
    { key: 'failed', label: 'Fehlgeschlagen', value: props.stats.sync.failed, dot: 'bg-destructive' },
]);

const syncTotal = computed(() => syncSegments.value.reduce((n, s) => n + s.value, 0));

function statusDot(status: string): string {
    return { synced: 'bg-[#6CBF8B]', syncing: 'bg-copper', pending: 'bg-muted-foreground/50', failed: 'bg-destructive' }[status] ?? 'bg-muted-foreground/50';
}

// Subtle live hint on sync activity via the operator channel.
const liveHint = ref<{ message: string; failed: boolean } | null>(null);
let hintTimer: ReturnType<typeof setTimeout> | undefined;

function showHint(message: string, failed: boolean) {
    liveHint.value = { message, failed };
    clearTimeout(hintTimer);
    hintTimer = setTimeout(() => (liveHint.value = null), 5000);
}

// The composable decides whether this account may subscribe at all.
useOperatorChannel({
    onSynced: (p) => showHint(`Aktivität: ${p.name} synchronisiert`, false),
    onFailed: (p) => showHint(`Aktivität: ${p.name}: Sync fehlgeschlagen`, true),
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-4">
            <div
                v-if="liveHint"
                class="fixed right-4 top-4 z-50 rounded-md border px-4 py-2 text-sm shadow-lg"
                :class="
                    liveHint.failed
                        ? 'border-destructive/30 bg-destructive/10 text-destructive'
                        : 'border-verdigris/30 bg-verdigris/15 text-verdigris'
                "
            >
                {{ liveHint.message }}
            </div>

            <!-- Metrics -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="t in tiles"
                    :key="t.label"
                    :href="t.href"
                    class="group rounded-xl border border-sidebar-border/70 bg-card p-5 transition-colors hover:border-copper/50 dark:border-sidebar-border"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-muted-foreground">{{ t.label }}</span>
                        <component :is="t.icon" class="size-5 text-copper" :stroke-width="1.75" />
                    </div>
                    <div class="mt-3 font-display text-3xl font-extrabold tabular-nums">{{ t.value }}</div>
                    <div class="mt-1 text-xs text-muted-foreground">{{ t.sub }}</div>
                </Link>

                <!-- Failed jobs — highlighted when > 0 -->
                <Link
                    href="/admin/status"
                    class="group rounded-xl border p-5 transition-colors"
                    :class="stats.failedJobs > 0 ? 'border-destructive/40 bg-destructive/5 hover:border-destructive/70' : 'border-sidebar-border/70 bg-card hover:border-copper/50 dark:border-sidebar-border'"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-muted-foreground">Fehlgeschlagene Jobs</span>
                        <AlertTriangle class="size-5" :class="stats.failedJobs > 0 ? 'text-destructive' : 'text-muted-foreground/60'" :stroke-width="1.75" />
                    </div>
                    <div class="mt-3 font-display text-3xl font-extrabold tabular-nums" :class="stats.failedJobs > 0 ? 'text-destructive' : ''">{{ stats.failedJobs }}</div>
                    <div class="mt-1 text-xs text-muted-foreground">Zur Statusseite</div>
                </Link>
            </div>

            <!-- Failed packages widget -->
            <section
                class="rounded-xl border p-5"
                :class="failedPackages.length ? 'border-destructive/40 bg-destructive/5' : 'border-sidebar-border/70 bg-card dark:border-sidebar-border'"
            >
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <AlertTriangle v-if="failedPackages.length" class="size-5 text-destructive" :stroke-width="1.75" />
                        <CheckCircle2 v-else class="size-5 text-[#6CBF8B]" :stroke-width="1.75" />
                        <h2 class="font-display text-lg font-bold">Fehlgeschlagene Pakete</h2>
                        <span
                            v-if="failedPackages.length"
                            class="rounded-full bg-destructive/15 px-2 py-0.5 text-xs font-semibold text-destructive tabular-nums"
                        >
                            {{ stats.sync.failed }}
                        </span>
                    </div>
                    <Link v-if="failedPackages.length" href="/admin/packages?status=failed" class="text-sm text-muted-foreground underline-offset-4 hover:underline">
                        Alle ansehen
                    </Link>
                </div>

                <div v-if="failedPackages.length" class="mt-4 flex flex-col divide-y divide-destructive/15">
                    <Link
                        v-for="p in failedPackages"
                        :key="p.id"
                        :href="`/admin/packages/${p.id}`"
                        class="group flex items-start gap-3 py-2.5 transition-colors hover:bg-destructive/5"
                    >
                        <span class="mt-1 size-2 shrink-0 rounded-full bg-destructive" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-mono text-sm">{{ p.name }}</span>
                                <span class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase text-muted-foreground">{{ p.type }}</span>
                                <span v-if="p.synced_at" class="ml-auto shrink-0 text-xs text-muted-foreground">{{ p.synced_at }}</span>
                            </div>
                            <p v-if="p.error" class="mt-0.5 truncate text-xs text-destructive/90" :title="p.error">{{ p.error }}</p>
                        </div>
                    </Link>
                </div>
                <p v-else class="mt-2 text-sm text-muted-foreground">Alle Pakete sind synchron — keine Fehler.</p>
            </section>

            <div class="grid flex-1 gap-4 lg:grid-cols-[1.1fr_0.9fr]">
                <!-- Sync status + last activity -->
                <section class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border">
                    <div>
                        <h2 class="font-display text-lg font-bold">Sync-Status</h2>
                        <p class="text-sm text-muted-foreground">{{ stats.versions }} Versionen über {{ stats.packages }} Pakete</p>
                    </div>

                    <div v-if="syncTotal > 0" class="flex h-2.5 overflow-hidden rounded-full bg-muted">
                        <div v-for="s in syncSegments" :key="s.key" :class="s.dot" :style="{ width: (s.value / syncTotal) * 100 + '%' }" />
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div v-for="s in syncSegments" :key="s.key" class="rounded-lg border border-sidebar-border/50 p-3">
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-full" :class="s.dot" />
                                <span class="text-xs text-muted-foreground">{{ s.label }}</span>
                            </div>
                            <div class="mt-1 font-display text-xl font-bold tabular-nums">{{ s.value }}</div>
                        </div>
                    </div>
                </section>

                <!-- Last activity -->
                <section class="flex flex-col rounded-xl border border-sidebar-border/70 bg-card p-5 dark:border-sidebar-border">
                    <h2 class="font-display text-lg font-bold">Zuletzt synchronisiert</h2>
                    <div v-if="recent.length" class="mt-3 flex flex-col divide-y divide-sidebar-border/50">
                        <div v-for="r in recent" :key="r.name" class="flex items-center gap-3 py-2.5">
                            <span class="size-2 shrink-0 rounded-full" :class="statusDot(r.status)" />
                            <span class="truncate font-mono text-sm">{{ r.name }}</span>
                            <span class="ml-auto shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase text-muted-foreground">{{ r.type }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ r.synced_at }}</span>
                        </div>
                    </div>
                    <div v-else class="mt-6 flex flex-1 flex-col items-center justify-center gap-3 text-center">
                        <Layers class="size-8 text-muted-foreground/40" :stroke-width="1.5" />
                        <p class="text-sm text-muted-foreground">Noch keine synchronisierten Pakete.</p>
                        <Link href="/admin/packages" class="rounded-md bg-copper px-4 py-2 text-sm font-medium text-ink transition-colors hover:bg-copper-hi">
                            Erstes Paket anlegen
                        </Link>
                    </div>
                </section>
            </div>

            <!-- Secondary metrics -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border">
                    <Layers class="size-5 text-muted-foreground" :stroke-width="1.75" />
                    <div><div class="font-display text-lg font-bold tabular-nums">{{ stats.versions }}</div><div class="text-xs text-muted-foreground">Versionen</div></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border">
                    <Globe class="size-5 text-muted-foreground" :stroke-width="1.75" />
                    <div><div class="font-display text-lg font-bold tabular-nums">{{ stats.domains }}</div><div class="text-xs text-muted-foreground">Domains</div></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border">
                    <CloudDownload class="size-5 text-muted-foreground" :stroke-width="1.75" />
                    <div><div class="font-display text-lg font-bold tabular-nums">{{ stats.upstreams }}</div><div class="text-xs text-muted-foreground">Upstreams</div></div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border">
                    <KeyRound class="size-5 text-muted-foreground" :stroke-width="1.75" />
                    <div><div class="font-display text-lg font-bold tabular-nums">{{ stats.tokens }}</div><div class="text-xs text-muted-foreground">Tokens</div></div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
