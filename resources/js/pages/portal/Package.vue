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
import {
    installCardTitle,
    installHeading,
    prerequisiteNote,
    readmeFallbackNote,
    setupLinkLabel,
    versionsEmptyNote,
    versionsHeading,
    type PortalPackageType,
} from './portalInstall';
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

/**
 * One tag of a Docker repository — what stands in this page's list where the other three types
 * have versions.
 *
 * A repository has NO `package_versions` rows at all: the OCI push path writes an `oci_tags`
 * row and nothing else. `versions` is therefore empty for every Docker repository, which is why
 * this list used to say "Noch keine Versionen verfügbar." under a repository with tags in it.
 *
 * `digest` is the immutable name of what the tag points at, and it is here because it is the
 * only thing on this page a customer can pin a deployment to — a tag is a mutable pointer and
 * can be moved under them. Null only for a tag whose manifest row is missing, which the table
 * renders as a dash rather than guessing.
 */
interface TagRow {
    name: string;
    digest: string | null;
    /**
     * When the tag last CHANGED — relative, from `oci_tags.updated_at`. Deliberately not
     * labelled "gepusht": `ManifestStore::put()` writes the tag with `updateOrCreate`, so
     * re-pushing a tag that still points at the same manifest moves no timestamp at all.
     */
    updated_at: string | null;
}

import {
    PORTAL_NO_REMOVALS,
    PORTAL_NO_RETENTION,
    PORTAL_RETENTION_ADVICE,
    PORTAL_RETENTION_EXPLANATION,
    PORTAL_UPCOMING_REMOVALS,
} from './portalRetention';

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
    // Empty for every type but Docker, and the list below renders it INSTEAD of `versions`
    // there — see TagRow, and plate 4: "statt einer Versionsliste steht darunter die
    // Tag-Tabelle".
    //
    // Rendered in the order the server sends, never re-sorted here: the rows arrive in
    // `OciTag::scopeInPullOrder()`, the same clause that picks the tag `install` above names,
    // so the first row of the table is the tag in the pull command. Sorting this array in the
    // browser would break that pairing without touching any PHP.
    tags: TagRow[];
    /**
     * What the customer may know about retention, read-only: the resolved policy's name,
     * its rules in the operator's own words, and the tags the next run would remove. Null
     * for every type but Docker; for a Docker repository WITHOUT a policy the object is
     * present with `policy_name` null and the section says "nichts wird entfernt" — an
     * absent section would be indistinguishable from one that failed to load.
     */
    retention: {
        policy_name: string | null;
        rules: string[];
        removals: { name: string; pushed_at: string | null }[];
    } | null;
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

// Which list stands under the command: the tag table, or the version selector with its
// dependency tree. One reading of the type, so the heading, the empty state and the table
// cannot end up branching on three different conditions.
const isDocker = computed(() => props.package.type === 'docker');

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
                <!-- The command REPLACES nothing else on the page: the readme, and the list
                     under it (versions, or a Docker repository's tags), stay — they are what
                     the customer came to check against. Only the one element that would not work is withheld — a snippet
                     that answers 404 is worse than none. The single-registry sentence, from
                     the tested module: this page knows only the registry it is addressed by,
                     and the landing page's note claims none of them serve it.

                     `v-else-if`, not `v-else`, and the missing third branch is deliberate: an
                     in-force assignment whose command is nevertheless null renders NOTHING here.
                     It cannot happen today (installCommand() answers for every type), but the
                     branch this used to fall into printed "Diese Registry liefert das Paket
                     nicht mehr aus." over an assignment that is in force — a false statement
                     about the one fact the section is there to convey. -->
                <div v-else-if="!props.in_force" class="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm">
                    {{ registryLapsedNote() }}
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h2 class="text-lg font-medium">{{ versionsHeading(props.package.type) }}</h2>

                <!-- A DOCKER REPOSITORY GETS ITS TAGS HERE, not a version list. It has no
                     `package_versions` rows at all — an OCI push writes none — so this section
                     was unbranched while `readmeFallbackNote()` above it was already type-aware,
                     and it told the reader "Noch keine Versionen verfügbar." under every
                     repository on the instance, however many tags it held. Plate 4: "statt einer
                     Versionsliste steht darunter die Tag-Tabelle". -->
                <div v-if="isDocker" class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Tag</th>
                                <th class="px-4 py-3 font-medium">Digest</th>
                                <!-- "Aktualisiert", not "Gepusht": the column is `updated_at`,
                                     and re-pushing a tag onto the manifest it already names
                                     writes nothing and moves no timestamp. -->
                                <th class="px-4 py-3 font-medium">Aktualisiert</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="tag in props.tags"
                                :key="tag.name"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-mono">{{ tag.name }}</td>
                                <td class="px-4 py-3 font-mono text-xs break-all text-muted-foreground">{{ tag.digest ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ tag.updated_at ?? '—' }}</td>
                            </tr>
                            <tr v-if="props.tags.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">
                                    {{ versionsEmptyNote(props.package.type) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- RETENTION, READ-ONLY (plate 5's customer half): why tags disappear, and
                     which ones the next run would take. Always rendered for a Docker
                     repository — with no policy it states the fact instead of vanishing. -->
                <div v-if="isDocker && props.retention" class="rounded-xl border border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border">
                    <h2 class="font-medium">Aufbewahrung</h2>

                    <template v-if="props.retention.policy_name">
                        <p class="mt-1 text-muted-foreground">{{ PORTAL_RETENTION_EXPLANATION }}</p>
                        <p class="mt-2">
                            Richtlinie: <span class="font-medium">{{ props.retention.policy_name }}</span>
                        </p>
                        <ul class="mt-1 list-inside list-disc text-muted-foreground">
                            <li v-for="rule in props.retention.rules" :key="rule">{{ rule }}</li>
                        </ul>

                        <template v-if="props.retention.removals.length > 0">
                            <p class="mt-3">{{ PORTAL_UPCOMING_REMOVALS }}</p>
                            <ul class="mt-1 list-inside list-disc font-mono text-muted-foreground">
                                <li v-for="removal in props.retention.removals" :key="removal.name">
                                    {{ removal.name }}
                                    <span v-if="removal.pushed_at" class="font-sans text-xs">(gepusht {{ removal.pushed_at }})</span>
                                </li>
                            </ul>
                            <p class="mt-2 text-muted-foreground">{{ PORTAL_RETENTION_ADVICE }}</p>
                        </template>
                        <p v-else class="mt-3 text-muted-foreground">{{ PORTAL_NO_REMOVALS }}</p>
                    </template>

                    <p v-else class="mt-1 text-muted-foreground">{{ PORTAL_NO_RETENTION }}</p>
                </div>

                <div
                    v-else-if="props.versions.length === 0"
                    class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
                >
                    {{ versionsEmptyNote(props.package.type) }}
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
