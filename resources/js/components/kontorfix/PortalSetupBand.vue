<script setup lang="ts">
/**
 * The entry band above the portal's package list (spec §3.1, plate 1).
 *
 * A customer landing on `/c/{org}` used to find a package list and not one sentence about
 * how to reach any of it: the instructions live two clicks deep, in a tab of a registry
 * detail page. This band puts the route in front of them — three steps, the registry it is
 * about, and the way into that registry's Einrichtung tab.
 *
 * ITS SECOND STATE IS THE IMPORTANT ONE. Once a token of this organization has been used the
 * band is one line. A hint that stays after the work is done becomes wallpaper, and wallpaper
 * is not read when it later says something urgent.
 *
 * Every decision and every sentence is in `portalSetupBand.ts`, which is where they can be
 * tested; this file is the markup and the browser state (which registry is picked) alone.
 */
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import {
    offersRegistryPicker,
    offersSetupBand,
    REGISTRY_PICKER_LABEL,
    selectedRegistry,
    setupBand,
    setupSteps,
    type PortalLastUsedToken,
    type PortalSetupRegistry,
    type PortalSetupState,
} from './portalSetupBand';

const props = defineProps<{
    // The organization the URL addresses, not the viewer's own. Every link this band builds
    // carries it, so an operator opening a customer's portal keeps navigating inside it.
    orgSlug: string;
    // The registries this portal shows, in the server's order (by name). Empty is a real
    // state and `offersSetupBand()` is what keeps it from becoming a broken button.
    registries: PortalSetupRegistry[];
    // The server's answer, read from `registry_tokens.last_used_at`. Never re-derived here —
    // see the module's opening comment.
    setupState: PortalSetupState;
    // Display payload for the collapsed line; null unless a token has actually been used.
    lastUsedToken: PortalLastUsedToken | null;
    // The ecosystems this organization MAY serve (`RegistryTypeService::effectiveFor()`), as
    // type values. The second step names them, because the button below leads to the
    // Einrichtung tab — which shows exactly these and nothing else. Required, not defaulted:
    // `[]` is a definite answer (this organization may serve nothing), and any list
    // substituted for it is the false claim this prop was added to remove.
    types: string[];
}>();

const visible = computed(() => offersSetupBand(props.registries));
const offersPicker = computed(() => offersRegistryPicker(props.registries));
const band = computed(() => setupBand(props.setupState, props.lastUsedToken));

// The PackageType enum's own labels, shared from the backend — the console spells every
// ecosystem in one place ("npm" lowercase, "Python" rather than "pip"), and the module writes
// the sentence out of whatever it is handed.
const { label: typeLabel } = useRegistryTypes();
const steps = computed(() => setupSteps(props.types.map(typeLabel)));

// The picked registry, as browser state. It starts on the first — the alphabetically first,
// since the server orders by name — which is also what the collapsed line points at, where
// there is no picker to change it.
const selectedId = ref(props.registries[0]?.id ?? '');

// Unwrapped into plain strings rather than exposing the registry object to the template: the
// selection can point at a row a partial reload removed, so the lookup is nullable, and a
// template that narrowed it would depend on `v-if` narrowing through a `computed` — which
// `vue-tsc` does only unreliably. The guard is `visible` above; these are its fallbacks.
const selected = computed(() => selectedRegistry(props.registries, selectedId.value));
const selectedName = computed(() => selected.value?.name ?? '');
const selectedUrl = computed(() => selected.value?.url ?? '');

// `portal.registries.show` renders with `Tabs default-value="einrichtung"`, so the registry
// page IS the setup tab — there is no tab parameter to carry, and inventing one here would
// be a second statement of that default.
const setupHref = computed(() => (selected.value === null ? '' : route('portal.registries.show', [props.orgSlug, selected.value.id])));

const registryOptions = computed(() => props.registries.map((registry) => ({ value: registry.id, label: registry.name })));
</script>

<template>
    <section v-if="visible">
        <!-- The collapsed shape: one line, no steps, no call to act. -->
        <div
            v-if="band.collapsed"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-l-4 border-sidebar-border/70 border-l-verdigris px-4 py-3 dark:border-sidebar-border dark:border-l-verdigris"
        >
            <p class="text-sm text-muted-foreground">{{ band.title }}</p>
            <Link :href="setupHref" class="text-sm font-medium text-verdigris hover:underline">{{ band.action }}</Link>
        </div>

        <div
            v-else
            class="rounded-xl border border-l-4 border-sidebar-border/70 border-l-verdigris p-4 dark:border-sidebar-border dark:border-l-verdigris"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <!-- h2, under the page's own `Pakete` h1 — which portal/Packages.vue
                         renders ABOVE this band for exactly that reason. -->
                    <h2 class="text-base font-semibold">{{ band.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">{{ band.lead }}</p>
                </div>

                <div class="flex min-w-56 flex-col gap-1">
                    <span class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ REGISTRY_PICKER_LABEL }}</span>
                    <!-- One registry is not a choice: it is named rather than offered as a
                         picker that changes nothing. The same rule the portal header's
                         organization switcher follows. -->
                    <SearchableSelect v-if="offersPicker" v-model="selectedId" :options="registryOptions" />
                    <p v-else class="font-mono text-sm">{{ selectedName }}</p>
                    <!-- The registry's address, from RegistryUrl — the one source for it.
                         Written here because the band is the first place a customer meets the
                         registry at all, and the address is what every one of the three steps
                         below is ultimately about. -->
                    <p class="font-mono text-xs break-all text-muted-foreground">{{ selectedUrl }}</p>
                </div>
            </div>

            <ol class="mt-4 grid gap-4 sm:grid-cols-3">
                <li v-for="(step, index) in steps" :key="step.title" class="flex items-start gap-3">
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full border border-verdigris font-mono text-xs text-verdigris"
                    >
                        {{ index + 1 }}
                    </span>
                    <div>
                        <p class="text-sm font-medium">{{ step.title }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ step.detail }}</p>
                    </div>
                </li>
            </ol>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                <p class="text-sm text-muted-foreground">{{ band.note }}</p>
                <Button as-child size="sm">
                    <Link :href="setupHref">{{ band.action }}</Link>
                </Button>
            </div>
        </div>
    </section>
</template>
