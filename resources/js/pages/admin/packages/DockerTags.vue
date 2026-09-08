<script setup lang="ts">
import ActivityTimeline from '@/components/kontorfix/ActivityTimeline.vue';
import { dockerDomainNote, dockerNoRegistryMessage, dockerSetupSnippet } from '@/components/kontorfix/dockerSetup';
import FlashToast from '@/components/kontorfix/FlashToast.vue';
import SharedBadge from '@/components/kontorfix/SharedBadge.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { NO_SPACE_FREED_YET } from '@/pages/admin/retention/policies';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface DockerPackage {
    id: string;
    type: 'docker';
    name: string;
    description: string | null;
    abandoned_at: string | null;
    replacement_package: string | null;
    abandonment_reason: string | null;
    shared: boolean;
}

interface GroupRow {
    id: string;
    name: string;
    slug: string;
    // The registry's path, from App\Services\Registry\RegistryUrl.
    url_path: string;
}

interface TagRow {
    name: string;
    digest: string | null;
    platform: string | null;
    // Null exactly when this tag's manifest is also named by another tag — the template
    // renders "geteilt" rather than repeating the manifest's full size for every tag that
    // points at it (see PackageController::showDocker()'s doc comment).
    size_bytes: number | null;
    shared: boolean;
    pushed_at: string | null;
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
    package: DockerPackage;
    canSharePackages: boolean;
    groups: GroupRow[];
    sharedElsewhere: number;
    // Where a Docker client reaches THIS repository — the package-scoped twin of
    // SetupSnippetBuilder's registry-level Docker fields. `host` is null only when the
    // repository is in no registry this viewer can see; a registry without a custom domain
    // is addressed on the instance host, with `repository_prefix` in front of the name.
    access: { host: string | null; repository_prefix: string | null; has_domain: boolean };
    tags: TagRow[];
    stats: { tag_count: number; occupied_bytes: number; shared_bytes: number };
    activities: ActivityRow[];
    // Which policy resolves for this repository and from which tier, what the next run
    // would remove, and — super-admin only — the selector. `policies` is empty for an org
    // admin on purpose: which rule sets exist is operator config.
    retention: {
        policy: { id: string; name: string } | null;
        tier: 'package' | 'instance' | null;
        rules: string[];
        dry_run: {
            kept_count: number;
            removed_count: number;
            tags: { name: string; pushed_at: string | null; keep: boolean; reason: string | null }[];
        } | null;
        can_assign: boolean;
        selected_policy_id: string | null;
        policies: { id: string; name: string }[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pakete', href: '/admin/packages' },
    { title: props.package.name, href: route('admin.packages.show', props.package.id) },
];

// --- Retention card state ---

const retentionOptions = computed(() => [
    { value: '', label: 'Instanz-Vorgabe (keine eigene Richtlinie)' },
    ...props.retention.policies.map((p) => ({ value: p.id, label: p.name })),
]);

const selectedPolicy = ref<string>(props.retention.selected_policy_id ?? '');
const savingPolicy = ref(false);

function saveRetentionPolicy() {
    savingPolicy.value = true;

    router.put(
        route('admin.packages.retention.update', props.package.id),
        { retention_policy_id: selectedPolicy.value || null },
        { preserveScroll: true, onFinish: () => (savingPolicy.value = false) },
    );
}

const retentionConfirmOpen = ref(false);
const applyingRetention = ref(false);

function applyRetention() {
    applyingRetention.value = true;

    router.post(
        route('admin.packages.retention.apply', props.package.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                applyingRetention.value = false;
                retentionConfirmOpen.value = false;
            },
        },
    );
}

// Binary units, spelled correctly (MiB, not MB for a division by 1024) — the mockups this
// page implements use them throughout, and this page has no legacy formatBytes() to stay
// consistent with the way Show.vue does.
function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '—';
    }
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    let value = bytes;
    let i = 0;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return `${value.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function abbreviateDigest(digest: string | null): string {
    if (!digest) {
        return '—';
    }
    const [algorithm, hex] = digest.split(':');
    if (!hex || hex.length <= 12) {
        return digest;
    }
    return `${algorithm}:${hex.slice(0, 6)}…${hex.slice(-4)}`;
}

// The same commands and the same note RegistrySetup.vue renders for the registry-level
// Einrichtung tab, out of the same module — so the wording cannot drift between the two
// surfaces that both describe one registry's address.
const accessSnippet = computed(() =>
    props.access.host ? dockerSetupSnippet(props.access.host, props.access.repository_prefix ?? '', props.package.name) : null,
);
// Only when a registry was found and it has no custom domain: the commands above work as
// they stand, and this says what a hostname of its own would change. 'operator' — this page
// lives in /admin, and Registry → Domains is a page its readers can actually open.
const accessNote = computed(() => (props.access.host && !props.access.has_domain ? dockerDomainNote('operator') : null));
// The one case with no address at all.
const accessMissingMessage = computed(() => (props.access.host ? null : dockerNoRegistryMessage()));

// --- Abandonment ---
const isAbandoned = computed(() => props.package.abandoned_at !== null);

const abandonmentForm = useForm({
    abandoned: isAbandoned.value,
    replacement_package: props.package.replacement_package ?? '',
    abandonment_reason: props.package.abandonment_reason ?? '',
});

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

// --- Shared ---
const sharedForm = useForm({ shared: props.package.shared });

function saveShared() {
    sharedForm.put(route('admin.packages.shared', props.package.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="props.package.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <FlashToast />

            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="font-mono text-2xl font-semibold">{{ props.package.name }}</h1>
                    <TypeBadge type="docker" />
                    <SharedBadge v-if="props.package.shared" />
                </div>
                <p v-if="props.package.description" class="max-w-2xl text-sm text-muted-foreground">
                    {{ props.package.description }}
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

            <Tabs default-value="tags">
                <TabsList>
                    <TabsTrigger value="tags">Tags ({{ props.tags.length }})</TabsTrigger>
                    <TabsTrigger value="zugang">Zugang</TabsTrigger>
                    <TabsTrigger value="registries">Registries</TabsTrigger>
                    <TabsTrigger value="retention">Retention</TabsTrigger>
                    <TabsTrigger value="aktivitaet">Aktivität</TabsTrigger>
                    <TabsTrigger value="verwaltung">Verwaltung</TabsTrigger>
                </TabsList>

                <TabsContent value="tags">
                    <section class="flex flex-col gap-3">
                        <!-- Two figures, because two questions (plate 2's note): "belegt" is
                             every manifest this repository holds, each counted once. "davon
                             geteilt" is the part of that total belonging to a manifest more
                             than one tag names — never invented from a per-tag share. -->
                        <div class="flex flex-wrap gap-6 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                            <div>
                                <div class="text-xs text-muted-foreground">Belegt</div>
                                <div class="text-lg font-semibold">{{ formatBytes(props.stats.occupied_bytes) }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted-foreground">Davon geteilt</div>
                                <div class="text-lg font-semibold">{{ formatBytes(props.stats.shared_bytes) }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-muted-foreground">Tags</div>
                                <div class="text-lg font-semibold">{{ props.stats.tag_count }}</div>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">Tag</th>
                                        <th class="px-4 py-3 font-medium">Digest</th>
                                        <th class="px-4 py-3 font-medium">Plattform</th>
                                        <th class="px-4 py-3 text-right font-medium">Größe</th>
                                        <th class="px-4 py-3 font-medium">Gepusht</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="tag in props.tags"
                                        :key="tag.name"
                                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td class="px-4 py-3 font-mono">{{ tag.name }}</td>
                                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ abbreviateDigest(tag.digest) }}</td>
                                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ tag.platform ?? '—' }}</td>
                                        <td class="px-4 py-3 text-right font-mono">
                                            <template v-if="tag.shared">
                                                <span class="text-muted-foreground">— geteilt</span>
                                            </template>
                                            <template v-else>{{ formatBytes(tag.size_bytes) }}</template>
                                        </td>
                                        <td class="px-4 py-3 text-muted-foreground">{{ tag.pushed_at ?? '—' }}</td>
                                    </tr>
                                    <tr v-if="props.tags.length === 0">
                                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                                            Noch keine Tags gepusht (via <code>docker push</code>).
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </TabsContent>

                <TabsContent value="zugang">
                    <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <div class="border-b border-sidebar-border/70 px-4 py-3 font-medium dark:border-sidebar-border">Docker einrichten</div>
                        <pre v-if="accessSnippet" class="overflow-x-auto px-4 py-3 font-mono text-sm">{{ accessSnippet }}</pre>
                        <p
                            v-if="accessNote"
                            class="border-t border-sidebar-border/70 px-4 py-3 text-sm text-muted-foreground dark:border-sidebar-border"
                        >
                            {{ accessNote }}
                        </p>
                        <p v-if="accessMissingMessage" class="px-4 py-3 text-sm text-muted-foreground">{{ accessMissingMessage }}</p>
                    </div>
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
                                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ group.url_path }}</td>
                                    </tr>
                                    <tr
                                        v-if="props.sharedElsewhere > 0"
                                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td colspan="2" class="px-4 py-3 text-muted-foreground">
                                            Zusätzlich in {{ props.sharedElsewhere }} Registry(s) außerhalb Ihres Bereichs.
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

                <TabsContent value="retention">
                    <div class="flex max-w-3xl flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <div v-if="props.retention.policy" class="text-sm">
                            <span class="font-medium">{{ props.retention.policy.name }}</span>
                            <span class="ml-2 text-xs text-muted-foreground">
                                {{ props.retention.tier === 'package' ? 'diesem Repository zugewiesen' : 'geerbt von der Instanz-Vorgabe' }}
                            </span>
                            <ul class="mt-2 list-inside list-disc text-muted-foreground">
                                <li v-for="rule in props.retention.rules" :key="rule">{{ rule }}</li>
                            </ul>
                        </div>
                        <p v-else class="text-sm text-muted-foreground">
                            Keine Richtlinie aufgelöst — es wird nichts entfernt. Alle Tags bleiben, bis eine Richtlinie zugewiesen oder eine
                            Instanz-Vorgabe gesetzt wird.
                        </p>

                        <div v-if="props.retention.can_assign" class="flex items-end gap-3 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                            <div class="grid w-72 gap-1">
                                <label class="text-xs font-medium" for="retention-policy">Richtlinie</label>
                                <SearchableSelect id="retention-policy" v-model="selectedPolicy" :options="retentionOptions" />
                            </div>
                            <Button variant="outline" :disabled="savingPolicy" @click="saveRetentionPolicy">Zuweisen</Button>
                        </div>

                        <div v-if="props.retention.dry_run" class="border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                            <div class="mb-2 flex items-center justify-between">
                                <p class="text-sm">
                                    Nächster Lauf: <span class="font-medium text-destructive">{{ props.retention.dry_run.removed_count }}</span>
                                    Tag(s) würden entfernt, {{ props.retention.dry_run.kept_count }} bleiben.
                                </p>
                                <Button
                                    v-if="props.retention.can_assign"
                                    variant="destructive"
                                    size="sm"
                                    :disabled="props.retention.dry_run.removed_count === 0"
                                    @click="retentionConfirmOpen = true"
                                >
                                    Jetzt anwenden
                                </Button>
                            </div>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-sidebar-border/70 text-left dark:border-sidebar-border">
                                        <th class="py-2 pr-4 font-medium">Tag</th>
                                        <th class="py-2 pr-4 font-medium">Gepusht</th>
                                        <th class="py-2 pr-4 font-medium">Entscheidung</th>
                                        <th class="py-2 font-medium">Begründung</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="tag in props.retention.dry_run.tags"
                                        :key="tag.name"
                                        class="border-b border-sidebar-border/40 last:border-b-0 dark:border-sidebar-border/40"
                                    >
                                        <td class="py-2 pr-4 font-mono">{{ tag.name }}</td>
                                        <td class="py-2 pr-4 text-muted-foreground">{{ tag.pushed_at ?? '—' }}</td>
                                        <td class="py-2 pr-4">
                                            <span v-if="tag.keep" class="text-emerald-600 dark:text-emerald-400">bleibt</span>
                                            <span v-else class="text-destructive">wird entfernt</span>
                                        </td>
                                        <td class="py-2 text-muted-foreground">{{ tag.reason ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <Dialog v-model:open="retentionConfirmOpen">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Retention auf „{{ props.package.name }}“ anwenden?</DialogTitle>
                            </DialogHeader>
                            <p class="text-sm">
                                {{ props.retention.dry_run?.removed_count ?? 0 }} Tag(s) werden entfernt. Beim Anwenden wird neu ausgewertet.
                            </p>
                            <p class="text-sm text-muted-foreground">{{ NO_SPACE_FREED_YET }}</p>
                            <DialogFooter>
                                <Button variant="outline" :disabled="applyingRetention" @click="retentionConfirmOpen = false">Abbrechen</Button>
                                <Button variant="destructive" :disabled="applyingRetention" @click="applyRetention">
                                    {{ applyingRetention ? 'Läuft …' : 'Anwenden' }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
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
                                <label class="text-sm font-medium" for="abandon_replacement">Empfohlener Ersatz (optional)</label>
                                <Input
                                    id="abandon_replacement"
                                    v-model="abandonmentForm.replacement_package"
                                    placeholder="registry.example.com/team/bild"
                                    autocomplete="off"
                                    class="font-mono"
                                />
                                <p v-if="abandonmentForm.errors.replacement_package" class="text-sm text-destructive">
                                    {{ abandonmentForm.errors.replacement_package }}
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <label class="text-sm font-medium" for="abandon_reason">Begründung (optional)</label>
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

                    <form
                        v-if="props.canSharePackages"
                        class="mt-4 flex max-w-xl flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        @submit.prevent="saveShared"
                    >
                        <label class="flex items-start gap-2 text-sm">
                            <Switch v-model="sharedForm.shared" class="mt-1" />
                            <span>
                                Für andere Organisationen freigeben
                                <span class="block text-xs text-muted-foreground">
                                    Ein geteiltes Paket kann jeder Registry der Instanz zugeordnet werden, nicht nur denen der
                                    besitzenden Organisation. Nur für Pakete der Betreiber-Organisation möglich.
                                </span>
                            </span>
                        </label>
                        <p v-if="sharedForm.errors.shared" class="text-sm text-destructive">{{ sharedForm.errors.shared }}</p>

                        <div>
                            <Button type="submit" :disabled="sharedForm.processing">Speichern</Button>
                        </div>
                    </form>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
