<script setup lang="ts">
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Pkg {
    id: string;
    name: string;
    type: 'composer' | 'npm';
}

const open = ref(false);
const query = ref('');
const results = ref<Pkg[]>([]);
const loading = ref(false);
const failed = ref(false);
const activeIndex = ref(0);
const inputEl = ref<HTMLInputElement | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let requestToken = 0;

// Laravel erwartet den entschlüsselten CSRF-Token als X-XSRF-TOKEN-Header
// (analog zu PackagePicker.vue bzw. lib/passkeys.ts).
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function openPalette() {
    open.value = true;
    nextTick(() => inputEl.value?.focus());
}

function closePalette() {
    open.value = false;
    query.value = '';
    results.value = [];
    loading.value = false;
    failed.value = false;
    activeIndex.value = 0;
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
}

function onKeydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        if (open.value) {
            closePalette();
        } else {
            openPalette();
        }
        return;
    }

    if (open.value && e.key === 'Escape') {
        e.preventDefault();
        closePalette();
    }
}

watch(query, (value) => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
    activeIndex.value = 0;

    if (value.trim() === '') {
        results.value = [];
        loading.value = false;
        failed.value = false;
        return;
    }

    debounceTimer = setTimeout(() => search(value), 200);
});

async function search(value: string) {
    const token = ++requestToken;
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(`${route('admin.package-search')}?q=${encodeURIComponent(value)}`, {
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
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

        results.value = (await response.json()) as Pkg[];
        activeIndex.value = 0;
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

function moveActive(delta: number) {
    if (results.value.length === 0) {
        return;
    }
    const count = results.value.length;
    activeIndex.value = (activeIndex.value + delta + count) % count;
}

function selectActive() {
    const pkg = results.value[activeIndex.value];
    if (pkg) {
        openPackage(pkg);
    }
}

function openPackage(pkg: Pkg) {
    router.visit(route('admin.packages.show', pkg.id));
    closePalette();
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[15vh]" role="dialog" aria-modal="true" aria-label="Paketsuche">
        <div class="fixed inset-0 bg-black/50" @click="closePalette" />

        <div class="relative w-full max-w-lg overflow-hidden rounded-lg border border-sidebar-border/70 bg-background shadow-lg dark:border-sidebar-border">
            <div class="flex items-center gap-2 border-b border-sidebar-border/70 px-3 dark:border-sidebar-border">
                <input
                    ref="inputEl"
                    v-model="query"
                    type="text"
                    autocomplete="off"
                    placeholder="Pakete suchen…"
                    class="w-full bg-transparent py-3 text-sm outline-none placeholder:text-muted-foreground"
                    @keydown.down.prevent="moveActive(1)"
                    @keydown.up.prevent="moveActive(-1)"
                    @keydown.enter.prevent="selectActive"
                />
                <kbd class="hidden shrink-0 rounded border border-sidebar-border/70 px-1.5 py-0.5 text-xs text-muted-foreground sm:inline dark:border-sidebar-border">
                    Esc
                </kbd>
            </div>

            <div class="max-h-80 overflow-y-auto">
                <p v-if="failed" class="px-3 py-6 text-center text-sm text-destructive">Suche fehlgeschlagen — bitte erneut versuchen.</p>
                <p v-else-if="query.trim() === ''" class="px-3 py-6 text-center text-sm text-muted-foreground">Tippe, um Pakete zu suchen.</p>
                <p v-else-if="!loading && results.length === 0" class="px-3 py-6 text-center text-sm text-muted-foreground">Keine Pakete gefunden.</p>

                <ul v-else class="py-1">
                    <li v-for="(pkg, index) in results" :key="pkg.id">
                        <button
                            type="button"
                            :class="[
                                'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                index === activeIndex ? 'bg-muted' : 'hover:bg-muted/50',
                            ]"
                            @click="openPackage(pkg)"
                            @mouseenter="activeIndex = index"
                        >
                            <TypeBadge :type="pkg.type" />
                            <span class="font-mono">{{ pkg.name }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
