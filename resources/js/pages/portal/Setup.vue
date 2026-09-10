<script setup lang="ts">
import PortalHeader from '@/components/kontorfix/PortalHeader.vue';
import RegistrySetup from '@/components/kontorfix/RegistrySetup.vue';
import { type OrgDockerGroup } from '@/components/kontorfix/dockerSetup';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { orgTokenScopeWarning, setupIntro } from './portalSetup';

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
    // the per-registry token list, which this page's token dropdown shares its shape with.
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
    // never a colleague's. See SetupController::show()'s doc comment.
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
        </div>
    </AppLayout>
</template>
