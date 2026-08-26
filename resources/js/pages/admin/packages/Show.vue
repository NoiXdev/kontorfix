<script setup lang="ts">
import ActivityTimeline from '@/components/kontorfix/ActivityTimeline.vue';
import ReadmeContent from '@/components/kontorfix/ReadmeContent.vue';
import StatusPill from '@/components/kontorfix/StatusPill.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useOperatorChannel, type PackagePayload } from '@/composables/useOperatorChannel';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

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

interface PythonDistRow {
    filename: string;
    version: string;
    filetype: string;
    size: number;
    download_count: number;
    uploaded_at: string | null;
}

const props = defineProps<{
    package: {
        id: string;
        type: 'composer' | 'npm' | 'python';
        source_mode: 'publish' | 'git';
        is_git_sourced: boolean;
        name: string;
        description: string | null;
        readme_html: string | null;
        repository_url: string | null;
        git_credential_id: string | null;
        has_repository_token: boolean;
        sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
        sync_error: string | null;
        synced_at: string | null;
        abandoned_at: string | null;
        replacement_package: string | null;
        abandonment_reason: string | null;
    };
    versions: VersionRow[];
    pythonDists: PythonDistRow[];
    gitCredentials: { id: string; name: string; provider: string }[];
    groups: GroupRow[];
    sharedElsewhere: number;
    stats: { downloads: number; storage_bytes: number; versions: number };
    activities: ActivityRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pakete', href: '/admin/packages' },
    { title: props.package.name, href: route('admin.packages.show', props.package.id) },
];

// Version selector: defaults to the newest version (props.versions[0], guaranteed by VersionOrder::sort()).
const selectedVersion = ref<string>(props.versions[0]?.version ?? '');

const currentVersion = computed(() => props.versions.find((v) => v.version === selectedVersion.value) ?? null);

const versionOptions = computed(() => props.versions.map((v) => ({ value: v.version, label: v.version })));

const installCommand = {
    composer: `composer require ${props.package.name}`,
    npm: `npm install ${props.package.name}`,
    python: `pip install ${props.package.name}`,
}[props.package.type];

function depCount(deps: Record<string, string>): number {
    return Object.keys(deps).length;
}

// --- Edit repository source ---
const isGitSourced = computed(() => props.package.is_git_sourced);

// A publish-mode package may still carry a repository_url — reference-only, and exactly
// what the npm migration leaves behind. SyncPackage's failure message tells that operator
// to remove the URL, so the tab holding the field has to open for them too; gating it on
// is_git_sourced alone made the recommended remedy unreachable.
const canEditSource = computed(() => isGitSourced.value || props.package.repository_url !== null);

// The README empty state points at the tab that actually replaces "Versionen" for this
// package type — Python shows "Distributionen" there instead (see the TabsTrigger below).
const secondaryTabLabel = computed(() => (props.package.type === 'python' ? 'Distributionen' : 'Versionen'));

const credentialOptions = computed(() => [
    { value: '', label: 'Kein Token / öffentlich' },
    ...props.gitCredentials.map((c) => ({ value: c.id, label: `${c.name} (${c.provider})` })),
]);

const sourceForm = useForm({
    repository_url: props.package.repository_url ?? '',
    is_private: props.package.git_credential_id !== null || props.package.has_repository_token,
    git_credential_id: props.package.git_credential_id ?? '',
    repository_token: '',
});

function saveSource() {
    sourceForm
        .transform((d) => ({
            repository_url: d.repository_url,
            git_credential_id: d.is_private ? d.git_credential_id || null : null,
            repository_token: d.is_private && !d.git_credential_id ? d.repository_token || null : null,
            // Switching to public (or to a managed credential) clears any inline token.
            remove_token: !d.is_private || !!d.git_credential_id,
        }))
        .put(route('admin.packages.update', props.package.id), {
            preserveScroll: true,
            onSuccess: () => (sourceForm.repository_token = ''),
        });
}

// Retries a sync without touching the stored source — the only other way was re-submitting
// the form above with the same values. Only offered for git-sourced packages: the server
// route stays reachable regardless, refusing a publish-based package with 409.
const resyncForm = useForm({});

function resyncPackage() {
    resyncForm.post(route('admin.packages.resync', props.package.id), { preserveScroll: true });
}

// --- Abandonment ---
const isAbandoned = computed(() => props.package.abandoned_at !== null);

const abandonmentForm = useForm({
    abandoned: isAbandoned.value,
    replacement_package: props.package.replacement_package ?? '',
    abandonment_reason: props.package.abandonment_reason ?? '',
});

// Clearing the switch discards the dependent fields — same pattern as Form.vue's
// onPrivateToggle for the repository token.
function onAbandonedToggle() {
    if (!abandonmentForm.abandoned) {
        abandonmentForm.replacement_package = '';
        abandonmentForm.abandonment_reason = '';
    }
}

watch(() => abandonmentForm.abandoned, onAbandonedToggle);

function saveAbandonment() {
    abandonmentForm
        .transform((d) => ({
            abandoned: d.abandoned,
            replacement_package: d.abandoned ? d.replacement_package || null : null,
            abandonment_reason: d.abandoned ? d.abandonment_reason || null : null,
        }))
        .put(route('admin.packages.abandonment', props.package.id), { preserveScroll: true });
}

// Live update of the sync status for the currently displayed package.
// Local state, so the live update doesn't mutate the prop.
const syncStatus = ref(props.package.sync_status);
const syncError = ref(props.package.sync_error);

function applyStatus(p: PackagePayload) {
    if (p.id !== props.package.id) {
        return;
    }
    syncStatus.value = p.sync_status as typeof props.package.sync_status;
    syncError.value = p.error ?? null;
}

// The composable decides whether this account may subscribe at all.
useOperatorChannel({
    onSynced: applyStatus,
    onFailed: applyStatus,
});
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
                <div v-if="props.package.synced_at" class="text-xs text-muted-foreground">Zuletzt synchronisiert: {{ props.package.synced_at }}</div>
                <div v-if="syncError" class="rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
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

            <Tabs default-value="uebersicht">
                <TabsList>
                    <TabsTrigger value="uebersicht">Übersicht</TabsTrigger>
                    <TabsTrigger value="installation">Installation</TabsTrigger>
                    <TabsTrigger value="registries">Registries</TabsTrigger>
                    <TabsTrigger v-if="canEditSource" value="quelle">Quelle</TabsTrigger>
                    <TabsTrigger v-if="props.package.type === 'python'" value="dists">Distributionen ({{ props.pythonDists.length }})</TabsTrigger>
                    <TabsTrigger v-else value="versionen">Versionen ({{ props.versions.length }})</TabsTrigger>
                    <TabsTrigger value="aktivitaet">Aktivität</TabsTrigger>
                    <TabsTrigger value="verwaltung">Verwaltung</TabsTrigger>
                </TabsList>

                <TabsContent value="uebersicht">
                    <ReadmeContent :html="props.package.readme_html" />
                    <div
                        v-if="!props.package.readme_html"
                        class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
                    >
                        Für dieses Paket liegt keine README vor. Installationsbefehle stehen im Tab „Installation“, weitere Details unter „{{
                            secondaryTabLabel
                        }}“.
                    </div>
                </TabsContent>

                <TabsContent value="installation">
                    <section class="flex flex-col gap-3">
                        <pre
                            class="overflow-x-auto rounded-md border border-sidebar-border/70 bg-muted/50 px-4 py-3 font-mono text-sm dark:border-sidebar-border"
                            >{{ installCommand }}</pre>
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
                                    <tr
                                        v-if="props.sharedElsewhere > 0"
                                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td colspan="2" class="px-4 py-3 text-muted-foreground">
                                            Zusätzlich in {{ props.sharedElsewhere }} Registry(s) außerhalb deines Bereichs.
                                        </td>
                                    </tr>
                                    <tr v-if="props.groups.length === 0 && props.sharedElsewhere === 0">
                                        <td colspan="2" class="px-4 py-8 text-center text-muted-foreground">Keiner Registry zugeordnet.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </TabsContent>

                <TabsContent v-if="canEditSource" value="quelle">
                    <form
                        class="flex max-w-xl flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        @submit.prevent="saveSource"
                    >
                        <p v-if="!isGitSourced" class="rounded-md bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                            Dieses Paket wird nicht aus einem Repository gespiegelt — die Repository-URL dient nur als Referenz. Feld leeren und
                            speichern, um sie zu entfernen.
                        </p>

                        <div class="grid gap-2">
                            <Label for="src_url">Repository-URL</Label>
                            <Input
                                id="src_url"
                                v-model="sourceForm.repository_url"
                                placeholder="https://git.example.com/vendor/paket.git"
                                autocomplete="off"
                                class="font-mono"
                            />
                            <p v-if="sourceForm.errors.repository_url" class="text-sm text-destructive">{{ sourceForm.errors.repository_url }}</p>
                        </div>

                        <label class="flex items-center gap-2 text-sm">
                            <Switch v-model="sourceForm.is_private" />
                            Privates Repository (Token nötig)
                        </label>

                        <template v-if="sourceForm.is_private">
                            <div v-if="props.gitCredentials.length" class="grid gap-2">
                                <Label for="src_cred">Gespeicherter Token</Label>
                                <SearchableSelect id="src_cred" v-model="sourceForm.git_credential_id" :options="credentialOptions" />
                            </div>
                            <div v-if="!sourceForm.git_credential_id" class="grid gap-2">
                                <Label for="src_token">Token einfügen{{ props.package.has_repository_token ? ' (leer = unverändert)' : '' }}</Label>
                                <Input
                                    id="src_token"
                                    v-model="sourceForm.repository_token"
                                    type="password"
                                    placeholder="z. B. GitHub PAT (ghp_…)"
                                    autocomplete="off"
                                    class="font-mono"
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{ props.package.has_repository_token ? 'Ein Token ist hinterlegt.' : 'Kein Token hinterlegt.' }}
                                    Wird verschlüsselt gespeichert.
                                </p>
                            </div>
                        </template>

                        <div class="flex items-center gap-3">
                            <Button type="submit" :disabled="sourceForm.processing">
                                {{ isGitSourced ? 'Speichern & neu synchronisieren' : 'Speichern' }}
                            </Button>
                            <Button v-if="isGitSourced" type="button" variant="outline" :disabled="resyncForm.processing" @click="resyncPackage">
                                Jetzt synchronisieren
                            </Button>
                        </div>
                    </form>
                </TabsContent>

                <TabsContent v-if="props.package.type === 'python'" value="dists">
                    <section class="flex flex-col gap-3">
                        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">Datei</th>
                                        <th class="px-4 py-3 font-medium">Version</th>
                                        <th class="px-4 py-3 font-medium">Typ</th>
                                        <th class="px-4 py-3 font-medium">Größe</th>
                                        <th class="px-4 py-3 font-medium">Downloads</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="dist in props.pythonDists"
                                        :key="dist.filename"
                                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td class="px-4 py-3 font-mono text-xs">{{ dist.filename }}</td>
                                        <td class="px-4 py-3">{{ dist.version }}</td>
                                        <td class="px-4 py-3">
                                            <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground uppercase">
                                                {{ dist.filetype === 'bdist_wheel' ? 'wheel' : 'sdist' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground">{{ formatBytes(dist.size) }}</td>
                                        <td class="px-4 py-3 text-muted-foreground">{{ dist.download_count.toLocaleString('de-DE') }}</td>
                                    </tr>
                                    <tr v-if="props.pythonDists.length === 0">
                                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                                            Noch keine Distributionen hochgeladen (via <code>twine upload</code>).
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </TabsContent>

                <TabsContent v-else value="versionen">
                    <section class="flex flex-col gap-4">
                        <div
                            v-if="props.versions.length === 0"
                            class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
                        >
                            Noch keine Versionen synchronisiert.
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
                                    <span v-if="currentVersion.reference" class="font-mono text-xs text-muted-foreground">
                                        {{ currentVersion.reference.slice(0, 12) }}
                                    </span>
                                    <span class="ml-auto text-sm text-muted-foreground">
                                        {{ currentVersion.download_count.toLocaleString('de-DE') }} Downloads ·
                                        {{ formatBytes(currentVersion.dist_size) }}
                                    </span>
                                </div>

                                <div class="mt-5 grid gap-6 md:grid-cols-2">
                                    <div>
                                        <h3 class="text-sm font-medium">Abhängigkeiten ({{ depCount(currentVersion.dependencies.runtime) }})</h3>
                                        <p v-if="depCount(currentVersion.dependencies.runtime) === 0" class="mt-2 text-sm text-muted-foreground">
                                            Keine
                                        </p>
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
                </TabsContent>

                <TabsContent value="aktivitaet">
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <ActivityTimeline :activities="props.activities" compact />
                    </div>
                </TabsContent>

                <TabsContent value="verwaltung">
                    <form
                        class="flex max-w-xl flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        @submit.prevent="saveAbandonment"
                    >
                        <label class="flex items-center gap-2 text-sm">
                            <Switch v-model="abandonmentForm.abandoned" />
                            Paket als verwaist markieren
                        </label>

                        <template v-if="abandonmentForm.abandoned">
                            <div class="grid gap-2">
                                <Label for="abandon_replacement">Empfohlener Ersatz (optional)</Label>
                                <Input
                                    id="abandon_replacement"
                                    v-model="abandonmentForm.replacement_package"
                                    :placeholder="{ composer: 'vendor/paket', npm: '@scope/name', python: 'projektname' }[props.package.type]"
                                    autocomplete="off"
                                    class="font-mono"
                                />
                                <p v-if="abandonmentForm.errors.replacement_package" class="text-sm text-destructive">
                                    {{ abandonmentForm.errors.replacement_package }}
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="abandon_reason">Begründung (optional)</Label>
                                <textarea
                                    id="abandon_reason"
                                    v-model="abandonmentForm.abandonment_reason"
                                    rows="3"
                                    placeholder="Wird nicht mehr gepflegt, siehe …"
                                    class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                                />
                                <p v-if="abandonmentForm.errors.abandonment_reason" class="text-sm text-destructive">
                                    {{ abandonmentForm.errors.abandonment_reason }}
                                </p>
                            </div>
                        </template>

                        <div>
                            <Button type="submit" :disabled="abandonmentForm.processing">Speichern</Button>
                        </div>
                    </form>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
