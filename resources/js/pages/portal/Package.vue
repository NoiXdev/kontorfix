<script setup lang="ts">
import PortalHeader from '@/components/kontorfix/PortalHeader.vue';
import ReadmeContent from '@/components/kontorfix/ReadmeContent.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { installCardTitle, installHeading, prerequisiteNote, readmeFallbackNote, setupLinkLabel, type PortalPackageType } from './portalInstall';
import { registryLapsedNote } from './portalPackages';

interface Registry {
    id: string;
    name: string;
    slug: string;
    url: string;
    /**
     * What a `docker login` addresses — `RegistryUrl::dockerHost()`, host and port with no
     * scheme and no namespace. Named by the Docker prerequisite line; deliberately not `url`,
     * which carries `https://` and, on the instance host, the `/r/{org}/{registry}` path that
     * no Docker client accepts.
     */
    docker_host: string;
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
        id: string;
        /**
         * The real `PackageType` set. This was `'composer' | 'npm'` while Python packages
         * were already being served through this page, so the one branch that mattered — the
         * pip command that silently resolved against PyPI — was the branch nothing
         * type-checked. Docker joins it here rather than becoming the next omission.
         */
        type: PortalPackageType;
        name: string;
        description: string | null;
        readme_html: string | null;
        sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
        abandoned_at: string | null;
        replacement_package: string | null;
        abandonment_reason: string | null;
    };
    versions: VersionRow[];
    /**
     * The whole command, built by `SetupSnippetBuilder::installCommand()` from THIS registry's
     * address — never assembled in the browser, and never from the package type alone.
     *
     * Null when the registry no longer serves the assignment. The server withholds it rather
     * than leaving it to this page to hide: a command that answers 404 should not be in the
     * payload at all.
     */
    install: string | null;
    // REGISTRY-LOCAL: whether THIS registry still serves the assignment — this page is
    // addressed by one registry and says nothing about the others. Decided by
    // RegistryAccessService (expiry AND own-or-shared), the predicate the registry endpoints
    // answer by. The page used to 404 for a lapsed one, so the customer arrived here because
    // their build failed and was shown nothing at all; it is served now, and the install
    // command is replaced by the reason.
    in_force: boolean;
    // The organization the URL addresses — the first segment of every portal link here.
    orgSlug: string;
}>();

const isAbandoned = computed(() => props.package.abandoned_at !== null);

// The registry page opens on its Einrichtung tab, so its own address IS the setup address —
// the prerequisite link needs no tab parameter, and inventing one would be a second way to
// address a page that has one.
const setupHref = `/c/${props.orgSlug}/registries/${props.registry.id}`;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Registries', href: `/c/${props.orgSlug}/registries` },
    { title: props.registry.name, href: `/c/${props.orgSlug}/registries/${props.registry.id}` },
    // The package's own address. `.../packages` was never a route — it was a fiction that
    // 404s — and this crumb pointing at the page it labels is the standard shape anyway.
    { title: props.package.name, href: route('portal.registries.package', [props.orgSlug, props.registry.id, props.package.id]) },
];

// Version selector: defaults to the newest version (props.versions[0], guaranteed by VersionOrder::sort()).
const selectedVersion = ref<string>(props.versions[0]?.version ?? '');

const currentVersion = computed(() => props.versions.find((v) => v.version === selectedVersion.value) ?? null);

const versionOptions = computed(() => props.versions.map((v) => ({ value: v.version, label: v.version })));

const copied = ref(false);

async function copyInstall() {
    if (props.install === null) {
        return;
    }

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
            <PortalHeader />

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
                    <span class="ml-2 font-mono text-xs break-all">{{ props.registry.url }}</span>
                </p>
            </div>

            <div
                v-if="isAbandoned"
                class="flex flex-col gap-1 rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
            >
                <span>
                    Dieses Paket ist seit {{ props.package.abandoned_at }} als verwaist markiert.
                    <template v-if="props.package.replacement_package">
                        Empfohlener Ersatz: <strong>{{ props.package.replacement_package }}</strong
                        >.
                    </template>
                </span>
                <span v-if="props.package.abandonment_reason">{{ props.package.abandonment_reason }}</span>
            </div>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Übersicht</h2>
                <ReadmeContent :html="props.package.readme_html" />
                <div
                    v-if="!props.package.readme_html"
                    class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
                >
                    {{ readmeFallbackNote(props.package.type) }}
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">{{ installHeading(props.package.type) }}</h2>
                <template v-if="props.in_force && props.install !== null">
                    <!-- Plate 4's prerequisite line. A command without its precondition is a
                         trap: the reader copies it, it fails (or, for pip, quietly succeeds
                         against PyPI), and the explanation sits in a tab they have not seen.
                         Both halves come from the tested module — the sentence, which names
                         the login host for Docker, and the label, which names the registry so
                         a customer with several cannot configure the wrong one. -->
                    <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm text-muted-foreground">
                        <span>{{ prerequisiteNote(props.package.type, props.registry.docker_host) }}</span>
                        <Link :href="setupHref" class="font-medium text-copper-hi hover:underline">
                            {{ setupLinkLabel(props.registry.name) }}
                        </Link>
                    </p>
                    <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <div class="flex items-center justify-between gap-4 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                            <h3 class="font-medium">{{ installCardTitle(props.package.type) }}</h3>
                            <Button variant="outline" size="sm" @click="copyInstall">
                                <component :is="copied ? Check : Copy" class="size-4" />
                                {{ copied ? 'Kopiert!' : 'Kopieren' }}
                            </Button>
                        </div>
                        <pre class="overflow-x-auto px-4 py-3 font-mono text-sm">{{ props.install }}</pre>
                    </div>
                </template>
                <!-- The command REPLACES nothing else on the page: the readme, the versions and
                     the dependency tree stay, because they are what the customer came to check
                     against. Only the one element that would not work is withheld — a snippet
                     that answers 404 is worse than none. The single-registry sentence, from
                     the tested module: this page knows only the registry it is addressed by,
                     and the landing page's note claims none of them serve it. -->
                <div v-else class="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm">
                    {{ registryLapsedNote() }}
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h2 class="text-lg font-medium">Versionen</h2>

                <div
                    v-if="props.versions.length === 0"
                    class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
                >
                    Noch keine Versionen verfügbar.
                </div>

                <template v-else>
                    <div class="flex flex-wrap items-center gap-3">
                        <Label for="version-select" class="text-sm">Version</Label>
                        <SearchableSelect id="version-select" v-model="selectedVersion" :options="versionOptions" class="w-64" />
                        <span class="text-xs text-muted-foreground"> {{ props.versions.length }} Versionen </span>
                    </div>

                    <div v-if="currentVersion" class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                        <div class="flex flex-wrap items-baseline gap-3">
                            <span class="font-mono text-lg font-semibold">{{ currentVersion.version }}</span>
                            <span v-if="currentVersion.released_at" class="text-sm text-muted-foreground">
                                {{ currentVersion.released_at }}
                            </span>
                        </div>

                        <div class="mt-5 grid gap-6 md:grid-cols-2">
                            <div>
                                <h3 class="text-sm font-medium">Abhängigkeiten ({{ depCount(currentVersion.dependencies.runtime) }})</h3>
                                <p v-if="depCount(currentVersion.dependencies.runtime) === 0" class="mt-2 text-sm text-muted-foreground">Keine</p>
                                <ul v-else class="mt-2 space-y-1">
                                    <li
                                        v-for="(constraint, name) in currentVersion.dependencies.runtime"
                                        :key="name"
                                        class="flex gap-2 font-mono text-xs"
                                    >
                                        <span>{{ name }}</span>
                                        <span class="text-muted-foreground">{{ constraint }}</span>
                                    </li>
                                </ul>
                            </div>

                            <div>
                                <h3 class="text-sm font-medium">Dev-Abhängigkeiten ({{ depCount(currentVersion.dependencies.dev) }})</h3>
                                <p v-if="depCount(currentVersion.dependencies.dev) === 0" class="mt-2 text-sm text-muted-foreground">Keine</p>
                                <ul v-else class="mt-2 space-y-1">
                                    <li
                                        v-for="(constraint, name) in currentVersion.dependencies.dev"
                                        :key="name"
                                        class="flex gap-2 font-mono text-xs"
                                    >
                                        <span>{{ name }}</span>
                                        <span class="text-muted-foreground">{{ constraint }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
