<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { computed, onUnmounted, ref, watch } from 'vue';

interface Pkg {
    id: string;
    name: string;
    type: 'composer' | 'npm' | 'python';
}

const selected = defineModel<Pkg[]>({ default: () => [] });

// When set, a persistent "Neues Paket anlegen" button is shown (not only when a search
// yields no results) — used on the registry package tab for an obvious quick-add.
withDefaults(defineProps<{ createButton?: boolean }>(), { createButton: false });

const query = ref('');
const failed = ref(false);
const results = ref<Pkg[]>([]);
const loading = ref(false);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let requestToken = 0;

const creating = ref(false);
const createForm = ref<{ name: string; type: 'composer' | 'npm' | 'python'; repository_url: string; repository_token: string }>({
    name: '',
    type: 'composer',
    repository_url: '',
    repository_token: '',
});

// Publish-based types (npm, python) have no git repository to probe, just a name.
const { isPublishBased } = useRegistryTypes();
const isPublishType = computed(() => isPublishBased(createForm.value.type));
const createErrors = ref<Record<string, string>>({});
const createSubmitting = ref(false);

// Probe-first: the repository is checked and its metadata previewed before creation.
interface ProbeResult {
    ok: boolean;
    error?: string;
    name?: string | null;
    description?: string | null;
    default_branch?: string | null;
    versions: string[];
}
const probing = ref(false);
const probeResult = ref<ProbeResult | null>(null);

watch(query, (value) => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    if (value.trim() === '') {
        results.value = [];
        loading.value = false;
        return;
    }

    debounceTimer = setTimeout(() => search(value), 200);
});

async function search(value: string) {
    const token = ++requestToken;
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(`/admin/package-search?q=${encodeURIComponent(value)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (token !== requestToken) {
            return;
        }

        if (!response.ok) {
            failed.value = true;
            results.value = [];
            return;
        }

        const data: Pkg[] = await response.json();
        const selectedIds = new Set(selected.value.map((p) => p.id));
        results.value = data.filter((p) => !selectedIds.has(p.id));
    } catch {
        if (token === requestToken) {
            failed.value = true;
            results.value = [];
        }
    } finally {
        if (token === requestToken) {
            loading.value = false;
        }
    }
}

function addPackage(pkg: Pkg) {
    selected.value = [...selected.value, pkg];
    query.value = '';
    results.value = [];
}

function removePackage(id: string) {
    selected.value = selected.value.filter((p) => p.id !== id);
}

function startCreate() {
    createForm.value = { name: '', type: 'composer', repository_url: '', repository_token: '' };
    createErrors.value = {};
    probeResult.value = null;
    creating.value = true;
}

function cancelCreate() {
    creating.value = false;
    createErrors.value = {};
    probeResult.value = null;
}

// Any edit to type/url invalidates a previous probe — the metadata no longer applies.
function resetProbe() {
    probeResult.value = null;
}

async function probeRepository() {
    if (probing.value || createForm.value.repository_url.trim() === '') {
        return;
    }
    probing.value = true;
    createErrors.value = {};
    probeResult.value = null;

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
                type: createForm.value.type,
                repository_url: createForm.value.repository_url,
                repository_token: createForm.value.repository_token,
            }),
        });

        if (response.status === 422) {
            const body = await response.json();
            const errors: Record<string, string> = {};
            for (const [key, messages] of Object.entries((body.errors ?? {}) as Record<string, string[]>)) {
                errors[key] = Array.isArray(messages) ? messages[0] : String(messages);
            }
            createErrors.value = errors;
            return;
        }

        if (!response.ok) {
            createErrors.value = { general: 'Prüfung fehlgeschlagen — bitte erneut versuchen.' };
            return;
        }

        const result: ProbeResult = await response.json();
        probeResult.value = result;

        // Adopt the discovered package name; fall back to the current search term.
        if (result.ok && result.name) {
            createForm.value.name = result.name;
        } else if (createForm.value.name.trim() === '') {
            createForm.value.name = query.value.trim();
        }
    } catch {
        createErrors.value = { general: 'Prüfung fehlgeschlagen — bitte erneut versuchen.' };
    } finally {
        probing.value = false;
    }
}

// Laravel expects the decrypted CSRF token as an X-XSRF-TOKEN header
// (analogous to what Inertia's axios sends automatically).
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function createPackage() {
    if (createSubmitting.value) {
        return;
    }
    createSubmitting.value = true;
    createErrors.value = {};

    try {
        const response = await fetch('/admin/packages', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(createForm.value),
        });

        if (response.status === 422) {
            const body = await response.json();
            const errors: Record<string, string> = {};
            for (const [key, messages] of Object.entries((body.errors ?? {}) as Record<string, string[]>)) {
                errors[key] = Array.isArray(messages) ? messages[0] : String(messages);
            }
            createErrors.value = errors;
            return;
        }

        if (!response.ok) {
            createErrors.value = { general: 'Anlegen fehlgeschlagen — bitte erneut versuchen.' };
            return;
        }

        const pkg: Pkg = await response.json();
        selected.value = [...selected.value, pkg];
        creating.value = false;
        probeResult.value = null;
        query.value = '';
        results.value = [];
    } catch {
        createErrors.value = { general: 'Anlegen fehlgeschlagen — bitte erneut versuchen.' };
    } finally {
        createSubmitting.value = false;
    }
}

onUnmounted(() => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center gap-2">
            <Input v-model="query" type="text" placeholder="Paket suchen…" autocomplete="off" class="flex-1" />
            <Button v-if="createButton && !creating" type="button" variant="outline" size="sm" @click="startCreate">Neu anlegen</Button>
        </div>

        <div v-if="query.trim() !== '' && !creating" class="space-y-1 rounded-md border border-input">
            <button
                v-for="pkg in results"
                :key="pkg.id"
                type="button"
                class="flex w-full items-center gap-2 border-b border-sidebar-border/70 px-3 py-2 text-left text-sm last:border-0 hover:bg-muted/50 dark:border-sidebar-border"
                @click="addPackage(pkg)"
            >
                <TypeBadge :type="pkg.type" />
                <span class="font-mono">{{ pkg.name }}</span>
            </button>

            <p v-if="failed" class="px-3 py-2 text-sm text-destructive">Suche fehlgeschlagen — bitte erneut versuchen.</p>
            <div v-else-if="!loading && results.length === 0" class="flex items-center justify-between gap-2 px-3 py-2">
                <p class="text-sm text-muted-foreground">Keine Treffer im Pool.</p>
                <button type="button" class="text-sm font-medium text-copper-hi hover:underline" @click="startCreate">Neues Paket anlegen</button>
            </div>
        </div>

        <div v-if="creating" class="space-y-3 rounded-md border border-input p-3">
            <p v-if="!isPublishType" class="text-xs text-muted-foreground">
                Typ und Repository-URL angeben, prüfen lassen — dann bestätigen. HTTPS und SSH werden unterstützt (SSH erfordert einen Deploy-Key).
            </p>
            <p v-else class="text-xs text-muted-foreground">
                Typ wählen und den reservierten Paketnamen angeben — Versionen kommen später per Upload.
            </p>

            <div class="grid gap-2">
                <Label>Typ</Label>
                <div class="inline-flex gap-2">
                    <button
                        v-for="option in ['composer', 'npm', 'python'] as const"
                        :key="option"
                        type="button"
                        :class="
                            cn(
                                'rounded-md border px-3 py-1.5 text-sm',
                                createForm.type === option
                                    ? 'border-copper/40 bg-copper/10 font-medium'
                                    : 'border-input text-muted-foreground hover:bg-muted/50',
                            )
                        "
                        @click="((createForm.type = option), resetProbe())"
                    >
                        {{ option }}
                    </button>
                </div>
                <InputError :message="createErrors.type" />
            </div>

            <!-- Publish-based (npm/Python): no git repository, just the reserved name. -->
            <div v-if="isPublishType" class="grid gap-2">
                <Label for="new-package-name-py">Name</Label>
                <Input
                    id="new-package-name-py"
                    v-model="createForm.name"
                    type="text"
                    :placeholder="createForm.type === 'npm' ? '@scope/name' : 'projektname'"
                    autocomplete="off"
                    class="font-mono"
                />
                <p class="text-xs text-muted-foreground">
                    Publish-basiert: Der Name ist der <strong>reservierte Paketname</strong>. Versionen/Daten entstehen beim
                    Upload (<code>{{ createForm.type === 'npm' ? 'npm publish' : 'twine upload' }}</code>) — kein Repository.
                </p>
                <InputError :message="createErrors.name" />
            </div>

            <template v-else>
                <div class="grid gap-2">
                    <Label for="new-package-url">Repository-URL</Label>
                    <div class="flex gap-2">
                        <Input
                            id="new-package-url"
                            v-model="createForm.repository_url"
                            type="text"
                            placeholder="https://git.example.com/vendor/paket.git"
                            autocomplete="off"
                            class="font-mono"
                            @update:model-value="resetProbe"
                            @keyup.enter="probeRepository"
                        />
                        <Button type="button" variant="outline" size="sm" :disabled="probing || createForm.repository_url.trim() === ''" @click="probeRepository">
                            {{ probing ? 'Prüfe…' : 'Prüfen' }}
                        </Button>
                    </div>
                    <InputError :message="createErrors.repository_url" />
                </div>

                <div class="grid gap-2">
                    <Label for="new-package-token">Zugriffs-Token <span class="text-muted-foreground">(privates Repo, optional)</span></Label>
                    <Input
                        id="new-package-token"
                        v-model="createForm.repository_token"
                        type="password"
                        placeholder="z. B. GitHub PAT (ghp_…)"
                        autocomplete="off"
                        class="font-mono"
                        @update:model-value="resetProbe"
                    />
                    <p class="text-xs text-muted-foreground">Nur für HTTPS. Wird verschlüsselt gespeichert und für Prüfen/Sync verwendet.</p>
                </div>

                <!-- Probe failed to reach / read the repo -->
                <div v-if="probeResult && !probeResult.ok" class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {{ probeResult.error ?? 'Repository konnte nicht gelesen werden.' }}
                </div>

                <!-- Probe succeeded: preview discovered metadata before confirming -->
                <div v-if="probeResult && probeResult.ok" class="space-y-3 rounded-md border border-verdigris/30 bg-verdigris/10 p-3">
                    <p class="text-sm font-medium text-verdigris">Repository erreichbar</p>

                    <div class="grid gap-2">
                        <Label for="new-package-name">Name{{ probeResult.name ? ' (gefunden)' : ' (nicht gefunden — bitte angeben)' }}</Label>
                        <Input id="new-package-name" v-model="createForm.name" type="text" placeholder="vendor/paket" autocomplete="off" class="font-mono" />
                        <InputError :message="createErrors.name" />
                    </div>

                    <p v-if="probeResult.description" class="text-sm text-muted-foreground">{{ probeResult.description }}</p>

                    <div v-if="probeResult.versions.length > 0" class="text-xs text-muted-foreground">
                        <span class="font-medium">{{ probeResult.versions.length }} Version(en):</span>
                        {{ probeResult.versions.slice(0, 8).join(', ') }}{{ probeResult.versions.length > 8 ? ' …' : '' }}
                    </div>
                    <p v-else class="text-xs text-muted-foreground">Keine Tags gefunden — nach dem Anlegen wird trotzdem synchronisiert.</p>
                </div>
            </template>

            <InputError :message="createErrors.general" />

            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" @click="cancelCreate">Abbrechen</Button>
                <Button
                    type="button"
                    size="sm"
                    :disabled="createSubmitting || createForm.name.trim() === '' || (!isPublishType && !probeResult?.ok)"
                    @click="createPackage"
                >
                    Anlegen bestätigen
                </Button>
            </div>
        </div>

        <div v-if="selected.length > 0" class="flex flex-wrap gap-2">
            <span
                v-for="pkg in selected"
                :key="pkg.id"
                class="inline-flex items-center gap-1.5 rounded-md border border-sidebar-border/70 bg-muted/50 py-1 pl-2 pr-1 text-sm dark:border-sidebar-border"
            >
                <TypeBadge :type="pkg.type" />
                <span class="font-mono">{{ pkg.name }}</span>
                <button
                    type="button"
                    class="rounded-sm p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    :aria-label="`${pkg.name} entfernen`"
                    @click="removePackage(pkg.id)"
                >
                    <X class="size-3.5" />
                </button>
            </span>
        </div>
        <p v-else class="text-sm text-muted-foreground">Noch keine Pakete zugewiesen.</p>
    </div>
</template>
