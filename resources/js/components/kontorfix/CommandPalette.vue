<script setup lang="ts">
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface PackageHit {
    id: string;
    name: string;
    type: 'composer' | 'npm';
}

interface RegistryHit {
    id: string;
    name: string;
    slug: string;
}

interface CustomerHit {
    id: string;
    name: string;
    is_operator: boolean;
}

interface SearchResults {
    packages: PackageHit[];
    registries: RegistryHit[];
    customers: CustomerHit[];
}

type FlatHit = { kind: 'package'; item: PackageHit } | { kind: 'registry'; item: RegistryHit } | { kind: 'customer'; item: CustomerHit };

const open = ref(false);
const query = ref('');
const results = ref<SearchResults>({ packages: [], registries: [], customers: [] });
const loading = ref(false);
const failed = ref(false);
const activeIndex = ref(0);
const inputEl = ref<HTMLInputElement | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let requestToken = 0;

// Flat ordering of all hits (packages → registries → customers) for
// keyboard navigation and the enter/click selection.
const flatHits = computed<FlatHit[]>(() => [
    ...results.value.packages.map((item) => ({ kind: 'package', item }) as FlatHit),
    ...results.value.registries.map((item) => ({ kind: 'registry', item }) as FlatHit),
    ...results.value.customers.map((item) => ({ kind: 'customer', item }) as FlatHit),
]);

const totalHits = computed(() => flatHits.value.length);

// Running offset per section, so the active index stays consistent
// across all sections.
const packageOffset = computed(() => 0);
const registryOffset = computed(() => results.value.packages.length);
const customerOffset = computed(() => results.value.packages.length + results.value.registries.length);

// Laravel expects the decrypted CSRF token as an X-XSRF-TOKEN header
// (analogous to PackagePicker.vue and lib/passkeys.ts).
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
    results.value = { packages: [], registries: [], customers: [] };
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
        results.value = { packages: [], registries: [], customers: [] };
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
        const response = await fetch(`${route('admin.search')}?q=${encodeURIComponent(value)}`, {
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
            results.value = { packages: [], registries: [], customers: [] };
            return;
        }

        results.value = (await response.json()) as SearchResults;
        activeIndex.value = 0;
    } catch {
        if (token === requestToken) {
            failed.value = true;
            results.value = { packages: [], registries: [], customers: [] };
        }
    } finally {
        if (token === requestToken) {
            loading.value = false;
        }
    }
}

function moveActive(delta: number) {
    const count = totalHits.value;
    if (count === 0) {
        return;
    }
    activeIndex.value = (activeIndex.value + delta + count) % count;
}

function selectActive() {
    const hit = flatHits.value[activeIndex.value];
    if (hit) {
        openHit(hit);
    }
}

function openHit(hit: FlatHit) {
    if (hit.kind === 'package') {
        router.visit(route('admin.packages.show', hit.item.id));
    } else if (hit.kind === 'registry') {
        router.visit(route('admin.groups.show', hit.item.id));
    } else {
        router.visit(route('admin.organizations.show', hit.item.id));
    }
    closePalette();
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    // Lets a visible trigger (e.g. the header search button) open the palette.
    window.addEventListener('kfx:open-search', openPalette);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('kfx:open-search', openPalette);
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
});
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[15vh]" role="dialog" aria-modal="true" aria-label="Suche">
        <div class="fixed inset-0 bg-black/50" @click="closePalette" />

        <div
            class="relative w-full max-w-lg overflow-hidden rounded-lg border border-sidebar-border/70 bg-background shadow-lg dark:border-sidebar-border"
        >
            <div class="flex items-center gap-2 border-b border-sidebar-border/70 px-3 dark:border-sidebar-border">
                <input
                    ref="inputEl"
                    v-model="query"
                    type="text"
                    autocomplete="off"
                    placeholder="Pakete, Registries, Kunden suchen…"
                    class="w-full bg-transparent py-3 text-sm outline-hidden placeholder:text-muted-foreground"
                    @keydown.down.prevent="moveActive(1)"
                    @keydown.up.prevent="moveActive(-1)"
                    @keydown.enter.prevent="selectActive"
                />
                <kbd
                    class="hidden shrink-0 rounded border border-sidebar-border/70 px-1.5 py-0.5 text-xs text-muted-foreground sm:inline dark:border-sidebar-border"
                >
                    Esc
                </kbd>
            </div>

            <div class="max-h-80 overflow-y-auto">
                <p v-if="failed" class="px-3 py-6 text-center text-sm text-destructive">Suche fehlgeschlagen — bitte erneut versuchen.</p>
                <p v-else-if="query.trim() === ''" class="px-3 py-6 text-center text-sm text-muted-foreground">Tippe, um zu suchen.</p>
                <p v-else-if="!loading && totalHits === 0" class="px-3 py-6 text-center text-sm text-muted-foreground">Keine Treffer.</p>

                <div v-else class="py-1">
                    <template v-if="results.packages.length > 0">
                        <p class="px-3 pt-2 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">Pakete</p>
                        <ul>
                            <li v-for="(pkg, index) in results.packages" :key="`pkg-${pkg.id}`">
                                <button
                                    type="button"
                                    :class="[
                                        'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                        packageOffset + index === activeIndex ? 'bg-muted' : 'hover:bg-muted/50',
                                    ]"
                                    @click="openHit({ kind: 'package', item: pkg })"
                                    @mouseenter="activeIndex = packageOffset + index"
                                >
                                    <TypeBadge :type="pkg.type" />
                                    <span class="font-mono">{{ pkg.name }}</span>
                                </button>
                            </li>
                        </ul>
                    </template>

                    <template v-if="results.registries.length > 0">
                        <p class="px-3 pt-2 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">Registries</p>
                        <ul>
                            <li v-for="(reg, index) in results.registries" :key="`reg-${reg.id}`">
                                <button
                                    type="button"
                                    :class="[
                                        'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                        registryOffset + index === activeIndex ? 'bg-muted' : 'hover:bg-muted/50',
                                    ]"
                                    @click="openHit({ kind: 'registry', item: reg })"
                                    @mouseenter="activeIndex = registryOffset + index"
                                >
                                    <span class="font-medium">{{ reg.name }}</span>
                                    <span class="font-mono text-xs text-muted-foreground">/r/{{ reg.slug }}</span>
                                </button>
                            </li>
                        </ul>
                    </template>

                    <template v-if="results.customers.length > 0">
                        <p class="px-3 pt-2 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">Kunden</p>
                        <ul>
                            <li v-for="(cust, index) in results.customers" :key="`cust-${cust.id}`">
                                <button
                                    type="button"
                                    :class="[
                                        'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                        customerOffset + index === activeIndex ? 'bg-muted' : 'hover:bg-muted/50',
                                    ]"
                                    @click="openHit({ kind: 'customer', item: cust })"
                                    @mouseenter="activeIndex = customerOffset + index"
                                >
                                    <span class="font-medium">{{ cust.name }}</span>
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium',
                                            cust.is_operator
                                                ? 'border-copper/30 bg-copper/15 text-copper-hi'
                                                : 'border-sidebar-border/70 bg-muted text-muted-foreground',
                                        ]"
                                    >
                                        {{ cust.is_operator ? 'Betreiber' : 'Kunde' }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
