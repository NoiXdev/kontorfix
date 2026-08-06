<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusPill from '@/components/kontorfix/StatusPill.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import AppLayout from '@/layouts/AppLayout.vue';
import { useOperatorChannel, type PackagePayload } from '@/composables/useOperatorChannel';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface PackageRow {
    id: string;
    type: 'composer' | 'npm' | 'python';
    source_mode: 'publish' | 'git';
    name: string;
    sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
    sync_error: string | null;
    groups_count: number;
    synced_at: string | null;
}

interface GroupOption {
    id: string;
    name: string;
    slug: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
}

interface Filters {
    q: string | null;
    type: string | null;
    status: string | null;
    group: string | null;
}

const props = defineProps<{
    packages: Paginated<PackageRow>;
    groups: GroupOption[];
    filters: Filters;
    registryTypes: string[];
    gitCredentials: { id: string; name: string; provider: string }[];
    sourceModes: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pakete', href: '/admin/packages' }];

// Type metadata (labels, publish-based) comes from the shared single source.
const { isPublishBased, options: typeOptionsFor } = useRegistryTypes();
// Only instance-enabled types are offered in the create dialog and the filter.
const typeOptions = computed(() => typeOptionsFor(props.registryTypes));

const filterQ = ref(props.filters.q ?? '');
const filterType = ref(props.filters.type ?? '');
const filterStatus = ref(props.filters.status ?? '');
const filterGroup = ref(props.filters.group ?? '');

const hasActiveFilters = computed(
    () => filterQ.value !== '' || filterType.value !== '' || filterStatus.value !== '' || filterGroup.value !== '',
);

function applyFilters() {
    router.get(
        route('admin.packages.index'),
        { q: filterQ.value, type: filterType.value, status: filterStatus.value, group: filterGroup.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
watch(filterQ, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 250);
});
watch([filterType, filterStatus, filterGroup], applyFilters);

function resetFilters() {
    filterQ.value = '';
    filterType.value = '';
    filterStatus.value = '';
    filterGroup.value = '';
}

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

// Live hint for sync events via the operator channel.
const liveHint = ref<{ message: string; failed: boolean } | null>(null);
let hintTimer: ReturnType<typeof setTimeout> | undefined;

function showHint(message: string, failed: boolean) {
    liveHint.value = { message, failed };
    clearTimeout(hintTimer);
    hintTimer = setTimeout(() => (liveHint.value = null), 5000);
}

function applyStatus(p: PackagePayload) {
    const row = props.packages.data.find((pkg) => pkg.id === p.id);
    if (row) {
        row.sync_status = p.sync_status as PackageRow['sync_status'];
        row.sync_error = p.error ?? null;
    }
}

const isOperator = page.props.auth.can?.console ?? false;
if (isOperator) {
    useOperatorChannel({
        onSynced: (p) => {
            applyStatus(p);
            showHint(`${p.name} synchronisiert`, false);
        },
        onFailed: (p) => {
            applyStatus(p);
            showHint(`${p.name}: Sync fehlgeschlagen`, true);
        },
    });
}

const dialogOpen = ref(false);

const form = useForm({
    type: 'composer' as 'composer' | 'npm' | 'python',
    source_mode: 'publish' as 'publish' | 'git',
    name: '',
    repository_url: '',
    is_private: false,
    repository_token: '',
    git_credential_id: '',
    group_ids: [] as string[],
});

// Clearing the private toggle discards any entered token/credential.
function onPrivateToggle() {
    if (!form.is_private) {
        form.repository_token = '';
        form.git_credential_id = '';
        resetProbe();
    }
}

// Composer is always git; npm/python default to publish and may opt into git-mirror.
const canChooseSource = computed(() => isPublishBased(form.type));
const isGitMode = computed(() => form.type === 'composer' || form.source_mode === 'git');

// Switching type resets the source mode (composer → git implicitly) and the probe.
function onTypeChange() {
    form.source_mode = 'publish';
    onSourceModeChange();
}

// Leaving git-mirror mode discards the repository config so a publish package isn't
// created with stale git fields.
function onSourceModeChange() {
    if (!isGitMode.value) {
        form.repository_url = '';
        form.is_private = false;
        form.repository_token = '';
        form.git_credential_id = '';
    }
    resetProbe();
}

const credentialOptions = computed(() => [
    { value: '', label: 'Kein Token / öffentlich' },
    ...props.gitCredentials.map((c) => ({ value: c.id, label: `${c.name} (${c.provider})` })),
]);

// Probe-first for git types: check the repository (and read its manifest) before creating.
interface ProbeResult {
    ok: boolean;
    error?: string;
    name?: string | null;
    description?: string | null;
    versions: string[];
}
const probing = ref(false);
const probeResult = ref<ProbeResult | null>(null);

function resetProbe() {
    probeResult.value = null;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function probeRepository() {
    if (probing.value || form.repository_url.trim() === '') {
        return;
    }
    probing.value = true;
    probeResult.value = null;
    form.clearErrors();

    try {
        const response = await fetch('/admin/packages/probe', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                type: form.type,
                repository_url: form.repository_url,
                repository_token: form.repository_token,
                git_credential_id: form.git_credential_id || null,
            }),
        });

        if (!response.ok) {
            probeResult.value = { ok: false, error: 'Prüfung fehlgeschlagen — bitte erneut versuchen.', versions: [] };
            return;
        }

        const result: ProbeResult = await response.json();
        probeResult.value = result;
        if (result.ok && result.name) {
            form.name = result.name;
        }
    } catch {
        probeResult.value = { ok: false, error: 'Prüfung fehlgeschlagen — bitte erneut versuchen.', versions: [] };
    } finally {
        probing.value = false;
    }
}

const canSubmit = computed(() => form.name.trim() !== '' && (!isGitMode.value || probeResult.value?.ok === true));

function submit() {
    // Enforce the probe-first gate here too — not only via the disabled button — so a
    // git-sourced package (Composer, or an npm/Python git mirror) can never be created by
    // pressing Enter before „Prüfen" succeeded.
    if (!canSubmit.value) {
        return;
    }
    form.post(route('admin.packages.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
            probeResult.value = null;
        },
    });
}

function toggleGroup(groupId: string, checked: boolean) {
    if (checked) {
        form.group_ids.push(groupId);
    } else {
        form.group_ids = form.group_ids.filter((id) => id !== groupId);
    }
}

function destroyPackage(id: string) {
    router.delete(route('admin.packages.destroy', id), {
        onBefore: () => confirm('Paket wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Pakete" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div
                v-if="liveHint"
                class="fixed right-4 top-16 z-50 rounded-md border px-4 py-2 text-sm shadow-lg"
                :class="
                    liveHint.failed
                        ? 'border-destructive/30 bg-destructive/10 text-destructive'
                        : 'border-verdigris/30 bg-verdigris/15 text-verdigris'
                "
            >
                {{ liveHint.message }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Pakete</h1>
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Paket hinzufügen
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Input
                    v-model="filterQ"
                    type="search"
                    placeholder="Name suchen…"
                    autocomplete="off"
                    class="h-10 w-full sm:w-64"
                    aria-label="Nach Name suchen"
                />
                <SearchableSelect
                    v-model="filterType"
                    class="w-40"
                    placeholder="Alle Typen"
                    :options="[{ value: '', label: 'Alle Typen' }, ...typeOptions]"
                />
                <SearchableSelect
                    v-model="filterStatus"
                    class="w-40"
                    placeholder="Alle Status"
                    :options="[
                        { value: '', label: 'Alle Status' },
                        { value: 'pending', label: 'pending' },
                        { value: 'syncing', label: 'syncing' },
                        { value: 'synced', label: 'synced' },
                        { value: 'failed', label: 'failed' },
                    ]"
                />
                <SearchableSelect
                    v-model="filterGroup"
                    class="w-52"
                    placeholder="Alle Registries"
                    :options="[{ value: '', label: 'Alle Registries' }, ...props.groups.map((g) => ({ value: g.id, label: g.name }))]"
                />
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    @click="resetFilters"
                >
                    Zurücksetzen
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Typ</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Gruppen</th>
                            <th class="px-4 py-3 font-medium">Zuletzt synchronisiert</th>
                            <th class="px-4 py-3 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="pkg in props.packages.data"
                            :key="pkg.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3 font-mono">
                                <Link :href="route('admin.packages.show', pkg.id)" class="hover:underline">{{ pkg.name }}</Link>
                            </td>
                            <td class="px-4 py-3"><TypeBadge :type="pkg.type" /></td>
                            <td class="px-4 py-3">
                                <span :title="pkg.sync_status === 'failed' ? (pkg.sync_error ?? undefined) : undefined">
                                    <StatusPill :status="pkg.sync_status" />
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ pkg.groups_count }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ pkg.synced_at ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Button variant="ghost" size="icon" @click="destroyPackage(pkg.id)" aria-label="Paket löschen">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="props.packages.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">Noch keine Pakete angelegt.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Paket hinzufügen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="type">Typ</Label>
                        <SearchableSelect
                            id="type"
                            v-model="form.type"
                            :options="typeOptions"
                            @update:model-value="onTypeChange"
                        />
                        <InputError :message="form.errors.type" />
                    </div>

                    <!-- npm/Python can either receive pushed artifacts (publish) or mirror a
                         git repository's tags (git). Composer is always git. -->
                    <div v-if="canChooseSource" class="grid gap-2">
                        <Label for="source_mode">Quelle</Label>
                        <SearchableSelect
                            id="source_mode"
                            v-model="form.source_mode"
                            :options="props.sourceModes"
                            @update:model-value="onSourceModeChange"
                        />
                        <p class="text-xs text-muted-foreground">
                            <strong>Publish (Push):</strong> Versionen entstehen beim Upload.
                            <strong>Git-Mirror:</strong> Versionen werden aus den Tags eines Repositories gespiegelt
                            (keine Build-/Prepare-Skripte).
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            :placeholder="{ composer: 'vendor/paket', npm: '@scope/name', python: 'projektname' }[form.type]"
                            autocomplete="off"
                        />
                        <p v-if="isGitMode" class="text-xs text-muted-foreground">Wird beim „Prüfen" automatisch aus dem Repository übernommen.</p>
                        <InputError :message="form.errors.name" />
                    </div>

                    <!-- Publish-based: no git repo — the name is the reserved identifier;
                         versions/metadata arrive with each upload. -->
                    <p v-if="!isGitMode" class="text-xs text-muted-foreground">
                        Publish-basiert: Der Name ist der <strong>reservierte Paketname</strong>. Versionen und Metadaten
                        entstehen beim Upload (<code>{{ form.type === 'npm' ? 'npm publish' : 'twine upload' }}</code>) —
                        kein Repository nötig.
                    </p>
                    <template v-else>
                        <div class="grid gap-2">
                            <Label for="repository_url">Repository-URL</Label>
                            <div class="flex gap-2">
                                <Input
                                    id="repository_url"
                                    v-model="form.repository_url"
                                    placeholder="https://git.example.com/vendor/paket.git"
                                    autocomplete="off"
                                    @update:model-value="resetProbe"
                                    @keyup.enter.prevent="probeRepository"
                                />
                                <Button type="button" variant="outline" :disabled="probing || form.repository_url.trim() === ''" @click="probeRepository">
                                    {{ probing ? 'Prüfe…' : 'Prüfen' }}
                                </Button>
                            </div>
                            <InputError :message="form.errors.repository_url" />
                        </div>

                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" v-model="form.is_private" class="size-4 rounded border-input" @change="onPrivateToggle" />
                            Privates Repository (Token nötig)
                        </label>

                        <template v-if="form.is_private">
                            <div v-if="props.gitCredentials.length" class="grid gap-2">
                                <Label for="git_credential_id">Gespeicherter Token</Label>
                                <SearchableSelect
                                    id="git_credential_id"
                                    v-model="form.git_credential_id"
                                    :options="credentialOptions"
                                    @update:model-value="resetProbe"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Verwaltete Git-Tokens unter „Git-Tokens" (org-weit wiederverwendbar). Oder unten ein Einmal-Token einfügen.
                                </p>
                            </div>

                            <div v-if="!form.git_credential_id" class="grid gap-2">
                                <Label for="repository_token">Token einfügen</Label>
                                <Input
                                    id="repository_token"
                                    v-model="form.repository_token"
                                    type="password"
                                    placeholder="z. B. GitHub PAT (ghp_…)"
                                    autocomplete="off"
                                    @update:model-value="resetProbe"
                                />
                                <p class="text-xs text-muted-foreground">Nur für HTTPS. Wird verschlüsselt gespeichert und für Prüfen/Sync verwendet.</p>
                            </div>
                        </template>

                        <div v-if="probeResult && !probeResult.ok" class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                            {{ probeResult.error ?? 'Repository konnte nicht gelesen werden.' }}
                        </div>
                        <div v-else-if="probeResult && probeResult.ok" class="rounded-md border border-verdigris/30 bg-verdigris/10 px-3 py-2 text-sm">
                            <span class="font-medium text-verdigris">Repository erreichbar.</span>
                            <span v-if="probeResult.versions.length" class="text-muted-foreground">
                                {{ probeResult.versions.length }} Version(en) gefunden.
                            </span>
                            <span v-else class="text-muted-foreground">Keine Tags gefunden — Sync läuft nach dem Anlegen trotzdem.</span>
                        </div>
                        <p v-else class="text-xs text-muted-foreground">Repository zuerst „Prüfen", dann anlegen.</p>
                    </template>

                    <div class="grid gap-2">
                        <Label>Gruppen</Label>
                        <div class="max-h-40 space-y-2 overflow-y-auto rounded-md border border-input p-3">
                            <div v-for="group in props.groups" :key="group.id" class="flex items-center gap-2">
                                <Checkbox
                                    :id="`group-${group.id}`"
                                    :model-value="form.group_ids.includes(group.id)"
                                    @update:model-value="(checked) => toggleGroup(group.id, checked === true)"
                                />
                                <Label :for="`group-${group.id}`" class="font-normal">{{ group.name }}</Label>
                            </div>
                            <p v-if="props.groups.length === 0" class="text-sm text-muted-foreground">Keine Gruppen vorhanden.</p>
                        </div>
                        <InputError :message="form.errors.group_ids" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="form.processing || !canSubmit">Anlegen</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
