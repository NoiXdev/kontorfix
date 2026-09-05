<script setup lang="ts">
import { SearchableSelect } from '@/components/ui/searchable-select';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { offersSwitcher, operatorBannerNote, portalAreaLinks, switcherOptions } from './portalHeader';

const page = usePage<SharedData>();

// Null on every request that addresses no portal. The four portal pages all render behind
// ResolvePortalContext, so it is never null where this component is mounted — but the prop
// is shared application-wide and typed accordingly, so the template guards rather than
// asserting non-null and rendering a blank banner if that ever stops being true.
const portal = computed(() => page.props.portal ?? null);

// Computed once and handed to both the `v-if` and the control: whether to show the
// switcher is a question about the rows it would hold, so it must be asked of the same
// list the control renders.
const options = computed(() => (portal.value === null ? [] : switcherOptions(portal.value.switchable, portal.value.organization)));

// The portal's two areas. This is the ONLY link into the registries from anywhere in the
// interface — see the module — so it is unconditional: not derived from whether the customer
// has registries, and not rendered per row on the package list, because a customer with an
// empty package list is precisely the one who needs the setup snippets.
const areas = computed(() => (portal.value === null ? [] : portalAreaLinks(portal.value.areas, page.url)));

function visit(slug: string) {
    if (portal.value === null || slug === portal.value.organization.slug) {
        return;
    }

    // The portal's landing page of the organization switched to, not the current page under
    // a different slug: the addresses below /c/{orgSlug} carry registry and package ids that
    // belong to the organization they were built for, so re-pointing the current path at
    // another one would produce a 403 or a 404 rather than a switch.
    router.visit(route('portal.packages.index', slug));
}
</script>

<template>
    <div v-if="portal" class="flex flex-col gap-4">
        <div v-if="portal.viewing_as_operator" class="rounded-lg border border-copper/40 bg-copper/10 px-4 py-3 text-sm text-copper-hi">
            {{ operatorBannerNote(portal.organization.name) }}
        </div>

        <nav class="flex items-center gap-1 border-b border-sidebar-border/70 dark:border-sidebar-border" aria-label="Portalbereiche">
            <Link
                v-for="area in areas"
                :key="area.href"
                :href="area.href"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors"
                :class="area.current ? 'border-verdigris text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
                :aria-current="area.current ? 'page' : undefined"
            >
                {{ area.label }}
            </Link>
        </nav>

        <SearchableSelect
            v-if="offersSwitcher(options)"
            class="w-full sm:w-72"
            :model-value="portal.organization.slug"
            :options="options"
            @update:model-value="(slug) => visit(String(slug))"
        />
    </div>
</template>
