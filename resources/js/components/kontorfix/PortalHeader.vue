<script setup lang="ts">
import { SearchableSelect } from '@/components/ui/searchable-select';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { offersSwitcher, operatorBannerNote, switcherOptions } from './portalHeader';

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

        <SearchableSelect
            v-if="offersSwitcher(options)"
            class="w-full sm:w-72"
            :model-value="portal.organization.slug"
            :options="options"
            @update:model-value="(slug) => visit(String(slug))"
        />
    </div>
</template>
