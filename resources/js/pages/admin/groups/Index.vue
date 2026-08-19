<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import GroupSheet from '@/components/kontorfix/GroupSheet.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface GroupRow {
    id: string;
    name: string;
    slug: string;
    public: boolean;
    portal_enabled: boolean;
    packages_count: number;
    domains: string[];
    organization: string | null;
    organization_id: string | null;
}

interface OrgOption {
    id: string;
    name: string;
}

const props = defineProps<{
    groups: GroupRow[];
    organizations: OrgOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Gruppen', href: '/admin/groups' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

const visibilityOptions = [
    { value: 'public', label: 'Öffentlich' },
    { value: 'private', label: 'Privat' },
];

const columns: ColumnDef<GroupRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'slug', label: 'Slug' },
    { key: 'organization', label: 'Kunde / Org' },
    { key: 'domains', label: 'Domains', sortable: false },
    { key: 'packages_count', label: 'Pakete', sortAs: 'number' },
    { key: 'public', label: 'Sichtbarkeit', sortValue: (row) => (row.public ? 'Öffentlich' : 'Privat') },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<GroupRow>({
    rows: () => props.groups,
    columns,
    searchKeys: ['name', 'slug'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        org: {
            label: 'Kunde/Org',
            options: orgOptions.value,
            match: (row, value) => row.organization_id === value,
        },
        visibility: {
            label: 'Sichtbarkeit',
            options: visibilityOptions,
            match: (row, value) => (value === 'public' ? row.public : !row.public),
        },
    },
});

const sheetOpen = ref(false);

function destroyGroup(id: string) {
    router.delete(route('admin.groups.destroy', id), {
        onBefore: () => confirm('Gruppe wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Gruppen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Gruppen = Registries</h1>
                    <p class="text-sm text-muted-foreground">
                        Jede Gruppe ist eine Registry mit eigenem <code class="font-mono">/r/&lt;slug&gt;</code>-Endpunkt.
                    </p>
                </div>
                <Button @click="sheetOpen = true">
                    <Plus class="size-4" />
                    Neue Registry
                </Button>
            </div>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Gruppen angelegt.">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.org.value"
                        :options="orgOptions"
                        placeholder="Kunde/Org"
                        class="w-48"
                        @update:model-value="(v) => table.setFilter('org', String(v))"
                    />
                    <SearchableSelect
                        :model-value="table.filterValues.visibility.value"
                        :options="visibilityOptions"
                        placeholder="Sichtbarkeit"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('visibility', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <tr
                        v-for="group in rows"
                        :key="group.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3">
                            <Link :href="route('admin.groups.show', group.id)" class="hover:underline">{{ group.name }}</Link>
                        </td>
                        <td class="px-4 py-3 font-mono">/r/{{ group.slug }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ group.organization ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ group.domains.length > 0 ? group.domains.join(', ') : '—' }}
                        </td>
                        <td class="px-4 py-3">{{ group.packages_count }}</td>
                        <td class="px-4 py-3">
                            <span
                                v-if="group.public"
                                class="inline-flex items-center rounded-full border border-verdigris/30 bg-verdigris/15 px-2.5 py-0.5 text-xs font-medium text-verdigris"
                            >
                                Öffentlich
                            </span>
                            <span
                                v-else
                                class="inline-flex items-center rounded-full border border-border bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                            >
                                Privat
                            </span>
                            <span
                                v-if="!group.portal_enabled"
                                class="ml-1 inline-flex items-center rounded-full border border-border bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                                title="Reine Paketsammlung, im Kundenportal ausgeblendet"
                            >
                                Sammlung
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <Button variant="ghost" size="icon" @click="destroyGroup(group.id)" aria-label="Gruppe löschen">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>

        <GroupSheet v-model:open="sheetOpen" :organizations="props.organizations" />
    </AppLayout>
</template>
