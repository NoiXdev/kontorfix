<script setup lang="ts">
import { SearchableSelect } from '@/components/ui/searchable-select';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Building2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage<SharedData>();

const scope = computed(() => page.props.scope ?? null);

// The sentinel for "all organizations" — SearchableSelect works on string values, so we
// map null <-> '' here.
const ALL = '';
const selected = ref<string>(scope.value?.active ?? ALL);

// Keep the local selection in step with server state (e.g. after a scope change reloads
// the shared props, or when navigating between pages).
watch(
    () => scope.value?.active,
    (active) => {
        selected.value = active ?? ALL;
    },
);

const options = computed(() => [
    { value: ALL, label: 'Alle Organisationen' },
    ...(scope.value?.organizations ?? []).map((o) => ({ value: o.id, label: o.name })),
]);

function change(value: string | undefined) {
    const next = value ?? ALL;
    if (next === (scope.value?.active ?? ALL)) {
        return;
    }

    // The endpoint redirects back, so Inertia reloads the current page against the new
    // scope — every scoped list re-renders without an extra round-trip.
    router.post(route('admin.scope.set'), { organization_id: next === ALL ? null : next }, { preserveScroll: true });
}

watch(selected, (value) => change(value));
</script>

<template>
    <div v-if="scope?.canSelectAll" class="px-2 pb-1 group-has-[[data-collapsible=icon]]/sidebar-wrapper:hidden">
        <div class="mb-1 flex items-center gap-1.5 px-1 text-xs font-medium text-muted-foreground">
            <Building2 class="size-3.5" />
            <span>Organisation</span>
        </div>
        <SearchableSelect v-model="selected" :options="options" placeholder="Alle Organisationen" />
    </div>
</template>
