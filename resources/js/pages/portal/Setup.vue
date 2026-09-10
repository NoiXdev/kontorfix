<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import { type OrgDockerGroup } from '@/components/kontorfix/dockerSetup';
import PortalHeader from '@/components/kontorfix/PortalHeader.vue';
import RegistrySetup from '@/components/kontorfix/RegistrySetup.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { orgTokenScopeWarning, orgTokensEmptyMessage, orgTokensHeading, revokeConfirmation, setupIntro } from './portalSetup';

/**
 * SetupSnippetBuilder::forOrganization()'s payload verbatim — see that method's doc comment
 * for the three ways it differs from for(Group), which RegistrySetup.vue's own `Snippets`
 * interface now accepts as well (Task 7 made every text field there optional and added
 * `dockerGroups` for exactly this shape).
 */
interface Snippets {
    composer?: string;
    auth?: string;
    npm?: string;
    pip?: string;
    dockerHost?: string;
    dockerGroups?: OrgDockerGroup[];
}

interface TokenRow {
    id: string;
    name: string;
    ability: 'read' | 'publish';
    last_used_at: string | null;
    // Raw ISO timestamp, sort-only — matches RegistryController::show()'s identical field on
    // the per-registry token list, which this page's own revoke table shares its shape with.
    last_used_at_iso: string | null;
}

const props = defineProps<{
    // The organization the URL addresses — the first segment of every link this page builds.
    orgSlug: string;
    snippets: Snippets;
    // What this organization MAY serve (RegistryTypeService::effectiveFor()) — the same prop
    // RegistryController::show() sends its own Einrichtung tab, gating the same steps.
    types: string[];
    // The caller's OWN org-wide tokens (`group_id IS NULL`) — never a group-bound one, and
    // never a colleague's. See SetupController::show()'s doc comment. Fed to BOTH
    // RegistrySetup's "Vorhandene Tokens" dropdown (names only, values hidden) and this
    // page's own revoke table below — the only place in the portal an org-wide token can be
    // revoked from at all, since `registries.show`'s own token list is scoped to one group
    // and structurally excludes a `group_id IS NULL` row.
    tokens: TokenRow[];
    // Whether the "Veröffentlichen" ability may be offered on this tab's mint form —
    // RegistryTokenPolicy::create(), asked directly by SetupController rather than read off
    // the shared `portal.may_publish_tokens` prop: this page has no group in hand at all.
    can_publish: boolean;
}>();

const page = usePage<SharedData>();
// Membership — the same question TokenController::store() answers before it mints, and the
// same prop portal/Registry.vue reads for its own mint form. `?? false`: no addressed
// organization means no portal token to mint.
const mayMint = computed(() => page.props.portal?.may_mint_tokens ?? false);

// Same shape and same gate as portal/Registry.vue's own token-ability filter: an ability the
// viewer may not mint can still exist on a token they already hold (an admin who minted a
// publish token and was since demoted, for instance), so the filter only limits which of the
// EXISTING rows are shown — it does not limit what may be revoked.
const abilityOptions = computed((): { value: 'read' | 'publish'; label: string }[] =>
    props.can_publish
        ? [
              { value: 'read', label: 'Lesen' },
              { value: 'publish', label: 'Veröffentlichen' },
          ]
        : [{ value: 'read', label: 'Lesen' }],
);

function abilityLabel(ability: 'read' | 'publish'): string {
    return ability === 'publish' ? 'Veröffentlichen' : 'Lesen';
}

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

/**
 * Revokes an org-wide token through the existing `portal.tokens.destroy` route —
 * RegistryController::show()'s per-registry token list posts to the exact same route; this
 * is that same action, reached from the one page that lists a `group_id IS NULL` row at all.
 * TokenController::destroy() binds the token to the organization the URL names and
 * RegistryTokenPolicy::delete() gates on ownership (or `administers()` for an ownerless,
 * org-shared token) — neither reads `group_id`, so both already refuse another member's or
 * another organization's org-wide token exactly as they refuse a group-bound one
 * (PortalSetupTest pins this directly).
 */
function destroyToken(id: string) {
    router.delete(route('portal.tokens.destroy', [props.orgSlug, id]), {
        preserveScroll: true,
        onBefore: () => confirm(revokeConfirmation()),
    });
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Einrichtung', href: `/c/${props.orgSlug}/setup` }];
</script>

<template>
    <Head title="Einrichtung" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <PortalHeader />

            <div>
                <h1 class="text-xl font-semibold">Einrichtung</h1>
                <!-- The sentence lives in the tested module, not here — see portalSetup.ts. -->
                <p class="mt-1 text-sm text-muted-foreground">{{ setupIntro() }}</p>
            </div>

            <RegistrySetup
                :snippets="props.snippets"
                :types="props.types"
                audience="customer"
                store-route="portal.tokens.store"
                :store-route-params="props.orgSlug"
                :personal-tokens="props.tokens"
                :may-mint="mayMint"
                :may-publish="props.can_publish"
                :token-scope-note="orgTokenScopeWarning()"
            />

            <div>
                <h2 class="mb-3 text-lg font-semibold">{{ orgTokensHeading() }}</h2>

                <DataTable :columns="tokenColumns" :state="tokenTable" :empty-message="orgTokensEmptyMessage()">
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
            </div>
        </div>
    </AppLayout>
</template>
