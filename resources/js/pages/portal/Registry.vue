<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DataTable from '@/components/kontorfix/DataTable.vue';
import PortalHeader from '@/components/kontorfix/PortalHeader.vue';
import RegistrySetup from '@/components/kontorfix/RegistrySetup.vue';
import SharedBadge from '@/components/kontorfix/SharedBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { installCell, type PortalPackageType } from './portalInstall';
import { badgesFor, SHARED_BADGE_TITLE } from './portalPackages';

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
    // Docker's raw facts, forwarded verbatim to RegistrySetup — see SetupSnippetBuilder.
    // Named here rather than left off: this interface used to omit them, which let the page
    // forward a payload whose Docker half it did not describe at all.
    dockerHost: string;
    dockerRepositoryPrefix: string;
    dockerHasDomain: boolean;
    dockerExample: string | null;
}

interface PackageRow {
    id: string;
    name: string;
    type: PortalPackageType;
    description: string | null;
    latest_version: string | null;
    /**
     * The whole command for THIS registry, built by `SetupSnippetBuilder::installCommand()` —
     * one source with the Einrichtung tab beside it, so the two tabs of one page cannot point
     * a customer at two different addresses.
     *
     * Null for a lapsed assignment. The server withholds it rather than sending a command the
     * page is trusted to hide; `installCell()` turns the null into the reason.
     */
    install: string | null;
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
    // What this organization MAY serve (RegistryTypeService::effectiveFor()), not what is
    // already in the registry. This used to be `[...new Set(packages.map(p => p.type))]`
    // computed right here, and an empty registry therefore showed no instructions at all —
    // which for images is the normal FIRST state, because nobody pushes a first image into
    // a registry whose address is written nowhere. The server answers it now.
    types: string[];
    packages: PackageRow[];
    tokens: TokenRow[];
}>();

// The enum-driven type list (PackageType::metadata(), shared as `registryTypeMeta`), not a
// hardcoded array. The hardcoded one listed composer/npm/python, so a Docker repository in
// this registry could not be filtered for at all — the same defect PackagePicker's quick-add
// carried, and the same reason PackageType's own docblock gives for the enum existing.
//
// Every type the instance knows, deliberately, rather than `props.types`: that prop is what
// this organization MAY serve now, and a registry can hold a package of a type the operator
// has since switched off. A filter that hid those rows' own type would be a filter that
// cannot find a row the table is displaying.
const typeOptions = useRegistryTypes().options();

// Packages and tokens each get their own useTableState instance with a distinct
// prefix ('pkg' / 'tok') — without it both tables would read and write the same
// sort/direction/q query keys, and sorting one would silently reorder the other.
const packageColumns: ColumnDef<PackageRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'type', label: 'Typ' },
    { key: 'latest_version', label: 'Letzte Version' },
    { key: 'description', label: 'Beschreibung' },
    // Plate 5. Unsortable: the column holds a command for most rows and an explanation for
    // the rest, and sorting a mixed column alphabetically orders nothing a reader asked for.
    { key: 'install', label: 'Installation', sortable: false },
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

// Both token forms on this page POST to portal.tokens.store, so both read the one flag.
// `?? false` for the null case: no addressed organization means no portal token to mint.
const mayMint = computed(() => page.props.portal?.may_mint_tokens ?? false);
// One reading of the publish flag for both of this page's token forms — the tokens tab's
// ability picker below, and the Einrichtung tab's, which RegistrySetup renders.
const mayPublish = computed(() => page.props.portal?.may_publish_tokens ?? false);

// Publish tokens are organization write credentials and are admin/maintainer-only on the
// server (RegistryTokenPolicy::create). Do not offer the option to plain members.
//
// `portal.may_publish_tokens`, NOT `auth.can.console`. That flag means "administers SOME
// organization"; the policy asks whether the caller administers THIS one. An admin of A who
// is a plain member of B was therefore offered "Veröffentlichen" in /c/B and refused with a
// 403 on submit — the same shown-and-then-refused shape the token form itself was hidden to
// avoid. The prop is `User::administers($organization->id)`, which is the method the policy
// calls.
//
// The explicit return type keeps `value` as the literal `'read' | 'publish'` union (what
// `tokenForm.ability` is actually typed as) rather than the widened `string` a plain object
// literal would infer — `SearchableSelect`'s `v-model` needs the two to line up exactly.
const abilityOptions = computed((): { value: 'read' | 'publish'; label: string }[] =>
    mayPublish.value
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
 * The Installation cell of one row: the command, abbreviated for the column, or the reason
 * there is none. Both decisions live in the tested module — including the choice of the
 * single-registry sentence, which is the only one this page may make (see portalPackages.ts,
 * TWO ANSWERS: the landing page's note speaks about every registry, and this page knows one).
 *
 * The reason used to render as a second, full-width row under the package. It renders IN the
 * column now, where the command would have been: printing both would say one sentence twice
 * on one row, and the column a reader is scanning for "how do I get this" is where the answer
 * that they cannot belongs.
 */
function cellFor(pkg: PackageRow) {
    return installCell(pkg);
}

// Which row's command was last copied, so the confirmation lands on that row rather than on
// every button in the column. Null once it times out.
const copiedId = ref<string | null>(null);

async function copyInstall(pkg: PackageRow) {
    const command = cellFor(pkg).command;

    // The WHOLE command, never the abbreviated cell text: `pip install --index-url … kernmodul`
    // pasted into a terminal fails, and a reader who repairs it by deleting the flag is back
    // to installing from PyPI — the defect this column exists to close.
    if (command === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(command);
        copiedId.value = pkg.id;
        setTimeout(() => (copiedId.value = null), 2000);
    } catch {
        // Clipboard API not available (insecure context) — the command can be selected manually.
        copiedId.value = null;
    }
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
            <PortalHeader />

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
                        :types="props.types"
                        audience="customer"
                        store-route="portal.tokens.store"
                        :store-route-params="props.orgSlug"
                        :store-payload="{ group_id: props.registry.id }"
                        :personal-tokens="props.tokens"
                        :may-mint="mayMint"
                        :may-publish="mayPublish"
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
                            <!-- One <tr> per package. The block used to be a <template> wrapping
                                 two rows, the second carrying the lapsed explanation full-width;
                                 that explanation lives in the Installation cell now, so the
                                 separator no longer has to be handed from one row to the next. -->
                            <tr v-for="pkg in rows" :key="pkg.id" class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
                                <td class="px-4 py-3 font-mono">
                                    <div class="flex items-center gap-2">
                                        <!-- The name of a lapsed assignment stays LINKED, as it
                                                 does on portal/Packages.vue: the detail page serves
                                                 it with the explanation instead of 404ing, so this
                                                 is no longer a dead end on either page. -->
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
                                <td class="px-4 py-3">
                                    <!-- The command, abbreviated in the cell and whole in the
                                             clipboard — or, for a lapsed assignment, the reason
                                             there is none and no button to copy one. -->
                                    <div v-if="cellFor(pkg).command" class="flex items-center gap-2">
                                        <span class="font-mono text-xs break-all">{{ cellFor(pkg).text }}</span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="h-6 shrink-0 px-2 text-xs"
                                            aria-label="Installationsbefehl kopieren"
                                            @click="copyInstall(pkg)"
                                        >
                                            <component :is="copiedId === pkg.id ? Check : Copy" class="size-3" />
                                            {{ copiedId === pkg.id ? 'Kopiert!' : 'Kopieren' }}
                                        </Button>
                                    </div>
                                    <span v-else class="text-xs text-destructive">{{ cellFor(pkg).text }}</span>
                                </td>
                            </tr>
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
                                <p class="text-sm text-muted-foreground">Dieser Token wird nur einmal angezeigt. Bewahren Sie ihn sicher auf.</p>
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

                    <!-- Hidden rather than shown and then refused: TokenController::store()
                         requires MEMBERSHIP of the addressed organization, which an operator
                         account looking at a customer's portal does not have. `may_mint_tokens`
                         is that same question, answered once on the server — see
                         HandleInertiaRequests::portal(). -->
                    <form
                        v-if="mayMint"
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
