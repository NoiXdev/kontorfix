<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import RegistrySetup from '@/components/kontorfix/RegistrySetup.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Registry {
    id: string;
    name: string;
    slug: string;
    url: string;
}

interface Snippets {
    composer: string;
    auth: string;
    npm: string;
}

interface PackageRow {
    id: string;
    name: string;
    type: string;
    description: string | null;
    latest_version: string | null;
}

interface TokenRow {
    id: string;
    name: string;
    ability: 'read' | 'publish';
    last_used_at: string | null;
}

const props = defineProps<{
    registry: Registry;
    snippets: Snippets;
    packages: PackageRow[];
    tokens: TokenRow[];
    filters?: { q: string | null; type: string | null };
}>();

const filterQ = ref(props.filters?.q ?? '');
const filterType = ref(props.filters?.type ?? '');

const hasActiveFilters = computed(() => filterQ.value !== '' || filterType.value !== '');

function applyFilters() {
    router.get(
        route('portal.registries.show', props.registry.id),
        { q: filterQ.value, type: filterType.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
watch(filterQ, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 250);
});
watch(filterType, applyFilters);

function resetFilters() {
    filterQ.value = '';
    filterType.value = '';
}

const page = usePage<SharedData>();
const plainTextToken = computed(() => page.props.flash?.plainTextToken ?? null);

const tokenCalloutDismissed = ref(false);
watch(plainTextToken, (value) => {
    if (value) {
        tokenCalloutDismissed.value = false;
    }
});

const showTokenCallout = computed(() => !!plainTextToken.value && !tokenCalloutDismissed.value);

const tokenCopied = ref(false);

async function copyToken() {
    if (!plainTextToken.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(plainTextToken.value);
        tokenCopied.value = true;
        setTimeout(() => (tokenCopied.value = false), 2000);
    } catch {
        // Clipboard API not available (insecure context) — the token can be selected manually.
        tokenCopied.value = false;
    }
}

const tokenForm = useForm({
    name: '',
    group_id: props.registry.id,
    ability: 'read' as 'read' | 'publish',
});

function submitToken() {
    tokenForm.post(route('portal.tokens.store'), {
        preserveScroll: true,
        onSuccess: () => {
            tokenForm.reset('name');
            tokenForm.group_id = props.registry.id;
        },
    });
}

function abilityLabel(ability: 'read' | 'publish') {
    return ability === 'publish' ? 'Veröffentlichen' : 'Lesen';
}

function destroyToken(id: string) {
    router.delete(route('portal.tokens.destroy', id), {
        preserveScroll: true,
        onBefore: () => confirm('Token wirklich widerrufen?'),
    });
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Registries', href: '/portal' },
    { title: props.registry.name, href: `/portal/registries/${props.registry.id}` },
];
</script>

<template>
    <Head :title="props.registry.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">{{ props.registry.name }}</h1>
                <p class="mt-1 break-all font-mono text-sm text-muted-foreground">{{ props.registry.url }}</p>
            </div>

            <Tabs default-value="einrichtung">
                <TabsList>
                    <TabsTrigger value="einrichtung">Einrichtung</TabsTrigger>
                    <TabsTrigger value="pakete">Pakete</TabsTrigger>
                    <TabsTrigger value="tokens">Zugriffstokens</TabsTrigger>
                </TabsList>

                <TabsContent value="einrichtung">
                    <RegistrySetup
                        :snippets="props.snippets"
                        store-route="portal.tokens.store"
                        :store-payload="{ group_id: props.registry.id }"
                        :personal-tokens="props.tokens"
                    />
                </TabsContent>

                <TabsContent value="pakete">
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <Input
                        v-model="filterQ"
                        type="search"
                        placeholder="Name suchen…"
                        autocomplete="off"
                        class="h-10 w-full sm:w-64"
                        aria-label="Nach Name suchen"
                    />
                    <select
                        v-model="filterType"
                        aria-label="Nach Typ filtern"
                        class="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        <option value="">Alle Typen</option>
                        <option value="composer">composer</option>
                        <option value="npm">npm</option>
                    </select>
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
                                <th class="px-4 py-3 font-medium">Letzte Version</th>
                                <th class="px-4 py-3 font-medium">Beschreibung</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="pkg in props.packages"
                                :key="pkg.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-mono">
                                    <Link
                                        :href="route('portal.registries.package', [props.registry.id, pkg.id])"
                                        class="hover:underline"
                                    >
                                        {{ pkg.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">{{ pkg.type }}</td>
                                <td class="px-4 py-3 font-mono text-muted-foreground">{{ pkg.latest_version ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ pkg.description ?? '—' }}</td>
                            </tr>
                            <tr v-if="props.packages.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Noch keine Pakete in dieser Registry.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                </TabsContent>

                <TabsContent value="tokens">
                <div v-if="showTokenCallout" class="mb-4 rounded-xl border border-copper/30 bg-copper/10 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1 space-y-2">
                            <p class="font-medium text-copper-hi">Neuer Token erstellt</p>
                            <p class="select-all break-all rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-sm">
                                {{ plainTextToken }}
                            </p>
                            <p class="text-sm text-muted-foreground">Dieser Token wird nur einmal angezeigt. Bewahre ihn sicher auf.</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Button variant="outline" size="sm" @click="copyToken">
                                <Copy class="size-4" />
                                {{ tokenCopied ? 'Kopiert!' : 'Kopieren' }}
                            </Button>
                            <Button variant="ghost" size="sm" @click="tokenCalloutDismissed = true">Schließen</Button>
                        </div>
                    </div>
                </div>

                <form
                    class="mb-4 grid gap-4 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-[1fr_auto_auto] sm:items-end dark:border-sidebar-border"
                    @submit.prevent="submitToken"
                >
                    <div class="grid gap-2">
                        <Label for="token_name">Name</Label>
                        <Input id="token_name" v-model="tokenForm.name" placeholder="ci-token" autocomplete="off" />
                        <InputError :message="tokenForm.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token_ability">Recht</Label>
                        <select
                            id="token_ability"
                            v-model="tokenForm.ability"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option value="read">Lesen</option>
                            <option value="publish">Veröffentlichen</option>
                        </select>
                        <InputError :message="tokenForm.errors.ability" />
                    </div>

                    <Button type="submit" :disabled="tokenForm.processing">
                        <Plus class="size-4" />
                        Token erstellen
                    </Button>
                </form>

                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Recht</th>
                                <th class="px-4 py-3 font-medium">Zuletzt genutzt</th>
                                <th class="px-4 py-3 font-medium">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="token in props.tokens"
                                :key="token.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-mono">{{ token.name }}</td>
                                <td class="px-4 py-3">{{ abilityLabel(token.ability) }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ token.last_used_at ?? 'nie' }}</td>
                                <td class="px-4 py-3">
                                    <Button variant="ghost" size="icon" aria-label="Token widerrufen" @click="destroyToken(token.id)">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="props.tokens.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Noch keine Tokens erstellt.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
