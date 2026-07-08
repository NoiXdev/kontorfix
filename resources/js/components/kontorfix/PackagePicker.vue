<script setup lang="ts">
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Input } from '@/components/ui/input';
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

onUnmounted(() => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div class="space-y-3">
        <Input v-model="query" type="text" placeholder="Paket suchen…" autocomplete="off" />

        <div v-if="query.trim() !== ''" class="space-y-1 rounded-md border border-input">
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
            <p v-else-if="!loading && results.length === 0" class="px-3 py-2 text-sm text-muted-foreground">
                Keine Treffer im Pool. Neue Pakete legst du unter „Pakete“ an.
            </p>
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
