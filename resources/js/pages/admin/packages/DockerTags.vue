<script setup lang="ts">
import ActivityTimeline from '@/components/kontorfix/ActivityTimeline.vue';
import { dockerEmptyStateMessage, dockerSetupSnippet } from '@/components/kontorfix/dockerSetup';
import FlashToast from '@/components/kontorfix/FlashToast.vue';
import SharedBadge from '@/components/kontorfix/SharedBadge.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

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
    // Whether THIS repository is reachable by a Docker client right now, and from where —
    // package-scoped twin of SetupSnippetBuilder's registry-level `dockerHost`/`dockerPath`.
    access: { host: string | null; registry_path: string | null };
    tags: TagRow[];
    stats: { tag_count: number; occupied_bytes: number; shared_bytes: number };
    activities: ActivityRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pakete', href: '/admin/packages' },
    { title: props.package.name, href: route('admin.packages.show', props.package.id) },
];

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

// The setup commands or the empty state — the exact same choice RegistrySetup.vue makes
// for the registry-level Einrichtung tab, and the same two functions from dockerSetup.ts,
// so the wording cannot drift between the two surfaces that both explain this constraint.
const accessSnippet = computed(() => (props.access.host ? dockerSetupSnippet(props.access.host, props.package.name) : null));
const accessEmptyMessage = computed(() => (props.access.host ? null : dockerEmptyStateMessage(props.access.registry_path ?? '')));

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
                        <p v-else class="px-4 py-3 text-sm text-muted-foreground">{{ accessEmptyMessage }}</p>
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
