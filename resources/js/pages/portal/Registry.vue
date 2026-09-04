<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DataTable from '@/components/kontorfix/DataTable.vue';
import RegistrySetup from '@/components/kontorfix/RegistrySetup.vue';
import SharedBadge from '@/components/kontorfix/SharedBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { badgesFor, registryLapsedNote, SHARED_BADGE_TITLE } from './portalPackages';

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
    pip: string;
    twine: string;
}

interface PackageRow {
    id: string;
    name: string;
    type: string;
    description: string | null;
    latest_version: string | null;
    /** The package is owned by the operator organization and shared into this registry. */
    shared: boolean;
    /**
     * REGISTRY-LOCAL: whether THIS registry still serves the package — not whether the
     * customer can get it at all. Deliberately NOT the `in_force` of `PortalPackageRow`,
     * whose contract is "served by at least one of this customer's registries"; the two
     * differ exactly on a package that lapsed here and still runs next door, which is the
     * case the portal's copy has to keep straight (see portalPackages.ts, TWO ANSWERS). This
     * interface used to extend that one and inherited the wrong contract in silence.
     *
     * Decided by RegistryAccessService — expiry AND own-or-shared, the predicate the registry
     * endpoints themselves answer by.
     */
    in_force: boolean;
}

interface TokenRow {
    id: string;
    name: string;
    ability: 'read' | 'publish';
    last_used_at: string | null;
    // Raw ISO timestamp, sort-only — `last_used_at` is a relative string ("vor 3 Tagen")
    // that Date.parse cannot read.
    last_used_at_iso: string | null;
}

const props = defineProps<{
    // The organization the URL addresses — the first segment of every portal link here.
    orgSlug: string;
    registry: Registry;
    snippets: Snippets;
    packages: PackageRow[];
    tokens: TokenRow[];
}>();

// Ecosystems present in this registry — drives which setup snippets are shown.
const registryTypes = computed(() => [...new Set(props.packages.map((p) => p.type))]);

const typeOptions = [
    { value: 'composer', label: 'composer' },
    { value: 'npm', label: 'npm' },
    { value: 'python', label: 'python' },
];

// Packages and tokens each get their own useTableState instance with a distinct
// prefix ('pkg' / 'tok') — without it both tables would read and write the same
// sort/direction/q query keys, and sorting one would silently reorder the other.
const packageColumns: ColumnDef<PackageRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Typ' },
    { key: 'latest_version', label: 'Letzte Version' },
    { key: 'description', label: 'Beschreibung' },
];

const packageTable = useTableState<PackageRow>({
    rows: () => props.packages,
    columns: packageColumns,
    prefix: 'pkg',
    searchKeys: ['name', 'description'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        type: {
            label: 'Typ',
            options: typeOptions,
            match: (row, value) => row.type === value,
        },
    },
});

const page = usePage<SharedData>();
const plainTextToken = computed(() => page.props.flash?.plainTextToken ?? null);

// Publish tokens are organization write credentials and are admin/maintainer-only on the
// server (RegistryTokenPolicy::create). Do not offer the option to plain members.
//
// The explicit return type keeps `value` as the literal `'read' | 'publish'` union (what
// `tokenForm.ability` is actually typed as) rather than the widened `string` a plain object
// literal would infer — `SearchableSelect`'s `v-model` needs the two to line up exactly.
const abilityOptions = computed((): { value: 'read' | 'publish'; label: string }[] =>
    page.props.auth.can?.console
        ? [
              { value: 'read', label: 'Lesen' },
              { value: 'publish', label: 'Veröffentlichen' },
          ]
        : [{ value: 'read', label: 'Lesen' }],
);

const tokenColumns: ColumnDef<TokenRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'ability', label: 'Recht' },
    { key: 'last_used_at', label: 'Zuletzt genutzt', sortAs: 'date', sortValue: (row) => row.last_used_at_iso },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const tokenTable = useTableState<TokenRow>({
    rows: () => props.tokens,
    columns: tokenColumns,
    prefix: 'tok',
    searchKeys: ['name'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        ability: {
            label: 'Recht',
            options: abilityOptions.value,
            match: (row, value) => row.ability === value,
        },
    },
});

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
    tokenForm.post(route('portal.tokens.store', props.orgSlug), {
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
    router.delete(route('portal.tokens.destroy', [props.orgSlug, id]), {
        preserveScroll: true,
        onBefore: () => confirm('Token wirklich widerrufen?'),
    });
}

/**
 * The note a row carries here: the single-registry sentence, or nothing.
 *
 * NOT `noteFor()`. That function chooses between two notes that both speak about the
 * customer's registries as a set — which this page does not have, and whose `in_force` is
 * registry-local. `registryLapsedNote()` is the sentence for exactly this shape.
 */
function noteForRow(pkg: PackageRow): string | null {
    return pkg.in_force ? null : registryLapsedNote();
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Registries', href: `/c/${props.orgSlug}/registries` },
    { title: props.registry.name, href: `/c/${props.orgSlug}/registries/${props.registry.id}` },
];
</script>

<template>
    <Head :title="props.registry.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">{{ props.registry.name }}</h1>
                <p class="mt-1 font-mono text-sm break-all text-muted-foreground">{{ props.registry.url }}</p>
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
                        :types="registryTypes"
                        store-route="portal.tokens.store"
                        :store-route-params="props.orgSlug"
                        :store-payload="{ group_id: props.registry.id }"
                        :personal-tokens="props.tokens"
                    />
                </TabsContent>

                <TabsContent value="pakete">
                    <DataTable
                        :columns="packageColumns"
                        :state="packageTable"
                        empty-message="Noch keine Pakete in dieser Registry."
                        search-placeholder="Name suchen…"
                    >
                        <template #filters>
                            <SearchableSelect
                                :model-value="packageTable.filterValues.type.value"
                                :options="typeOptions"
                                placeholder="Alle Typen"
                                class="w-40"
                                @update:model-value="(v) => packageTable.setFilter('type', String(v))"
                            />
                        </template>

                        <template #default="{ rows }">
                            <template v-for="pkg in rows" :key="pkg.id">
                                <!-- The separator belongs to the LAST row of this package's block,
                                     so the main row gives it up whenever the note follows it —
                                     the same rule portal/Packages.vue and admin/groups/Show.vue
                                     follow: an outage warning below a separator reads as belonging
                                     to the next package. -->
                                <tr :class="noteForRow(pkg) ? '' : 'border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border'">
                                    <td class="px-4 py-3 font-mono">
                                        <div class="flex items-center gap-2">
                                            <!-- The name of a lapsed assignment stays LINKED here,
                                                 unlike on portal/Packages.vue: the detail page now
                                                 serves it with the explanation instead of 404ing,
                                                 so this is no longer a dead end. -->
                                            <Link
                                                :href="route('portal.registries.package', [props.orgSlug, props.registry.id, pkg.id])"
                                                class="hover:underline"
                                            >
                                                {{ pkg.name }}
                                            </Link>
                                            <!-- One decision, in the tested module: which markers
                                                 this row carries and in what order. -->
                                            <template v-for="badge in badgesFor(pkg)" :key="badge">
                                                <SharedBadge v-if="badge === 'geteilt'" :title="SHARED_BADGE_TITLE" />
                                                <!-- v-else-if, not v-else: a catch-all would render
                                                     any badge added later in destructive red, which
                                                     is the wrong default for a marker that is not a
                                                     fault. -->
                                                <span
                                                    v-else-if="badge === 'abgelaufen'"
                                                    class="inline-flex items-center rounded-md border border-destructive/30 bg-destructive/10 px-2 py-0.5 font-sans text-xs font-medium text-destructive"
                                                >
                                                    {{ badge }}
                                                </span>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">{{ pkg.type }}</td>
                                    <td class="px-4 py-3 font-mono text-muted-foreground">{{ pkg.latest_version ?? '—' }}</td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ pkg.description ?? '—' }}</td>
                                </tr>
                                <tr v-if="noteForRow(pkg)" class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
                                    <td colspan="4" class="px-4 pb-3 text-xs text-destructive">{{ noteForRow(pkg) }}</td>
                                </tr>
                            </template>
                        </template>
                    </DataTable>
                </TabsContent>

                <TabsContent value="tokens">
                    <div v-if="showTokenCallout" class="mb-4 rounded-xl border border-copper/30 bg-copper/10 p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1 space-y-2">
                                <p class="font-medium text-copper-hi">Neuer Token erstellt</p>
                                <p class="rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-sm break-all select-all">
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
                            <SearchableSelect id="token_ability" v-model="tokenForm.ability" :options="abilityOptions" />
                            <InputError :message="tokenForm.errors.ability" />
                        </div>

                        <Button type="submit" :disabled="tokenForm.processing">
                            <Plus class="size-4" />
                            Token erstellen
                        </Button>
                    </form>

                    <DataTable :columns="tokenColumns" :state="tokenTable" empty-message="Noch keine Tokens erstellt.">
                        <template #filters>
                            <SearchableSelect
                                :model-value="tokenTable.filterValues.ability.value"
                                :options="abilityOptions"
                                placeholder="Recht"
                                class="w-40"
                                @update:model-value="(v) => tokenTable.setFilter('ability', String(v))"
                            />
                        </template>

                        <template #default="{ rows }">
                            <tr
                                v-for="token in rows"
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
                        </template>
                    </DataTable>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
