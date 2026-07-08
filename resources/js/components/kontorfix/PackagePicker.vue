<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';
import { onUnmounted, ref, watch } from 'vue';

interface Pkg {
    id: string;
    name: string;
    type: 'composer' | 'npm';
}

const selected = defineModel<Pkg[]>({ default: () => [] });

const query = ref('');
const failed = ref(false);
const results = ref<Pkg[]>([]);
const loading = ref(false);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let requestToken = 0;

const creating = ref(false);
const createForm = ref<{ name: string; type: 'composer' | 'npm'; repository_url: string }>({
    name: '',
    type: 'composer',
    repository_url: '',
});
const createErrors = ref<Record<string, string>>({});
const createSubmitting = ref(false);

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
    createForm.value = { name: query.value.trim(), type: 'composer', repository_url: '' };
    createErrors.value = {};
    creating.value = true;
}

function cancelCreate() {
    creating.value = false;
    createErrors.value = {};
}

// Laravel erwartet den entschlüsselten CSRF-Token als X-XSRF-TOKEN-Header
// (analog zu dem, was Inertias axios automatisch sendet).
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
        <Input v-model="query" type="text" placeholder="Paket suchen…" autocomplete="off" />

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
            <div class="grid gap-2">
                <Label for="new-package-name">Name</Label>
                <Input id="new-package-name" v-model="createForm.name" type="text" placeholder="vendor/paket" autocomplete="off" class="font-mono" />
                <InputError :message="createErrors.name" />
            </div>

            <div class="grid gap-2">
                <Label>Typ</Label>
                <div class="inline-flex gap-2">
                    <button
                        v-for="option in ['composer', 'npm'] as const"
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
                        @click="createForm.type = option"
                    >
                        {{ option }}
                    </button>
                </div>
                <InputError :message="createErrors.type" />
            </div>

            <div class="grid gap-2">
                <Label for="new-package-url">Repository-URL</Label>
                <Input
                    id="new-package-url"
                    v-model="createForm.repository_url"
                    type="text"
                    placeholder="https://git.example.com/vendor/paket.git"
                    autocomplete="off"
                    class="font-mono"
                />
                <InputError :message="createErrors.repository_url" />
            </div>

            <InputError :message="createErrors.general" />

            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" @click="cancelCreate">Abbrechen</Button>
                <Button type="button" size="sm" :disabled="createSubmitting" @click="createPackage">Anlegen & zuweisen</Button>
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
