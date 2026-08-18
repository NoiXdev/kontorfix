<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface OrganizationRow {
    id: string;
    name: string;
    slug: string;
    is_operator: boolean;
    users_count: number;
    groups_count: number;
}

const props = defineProps<{
    organizations: OrganizationRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Kunden', href: '/admin/organizations' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

// Derived from the existing `is_operator` prop — not a separate controller-supplied
// option list, just labels for the two values that field already takes.
const typeOptions = [
    { value: 'operator', label: 'Betreiber' },
    { value: 'customer', label: 'Kunde' },
];

const columns: ColumnDef<OrganizationRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'slug', label: 'Slug' },
    { key: 'is_operator', label: 'Typ', sortValue: (row) => (row.is_operator ? 'Betreiber' : 'Kunde') },
    { key: 'groups_count', label: 'Registries', sortAs: 'number' },
    { key: 'users_count', label: 'Nutzer', sortAs: 'number' },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<OrganizationRow>({
    rows: () => props.organizations,
    columns,
    searchKeys: ['name', 'slug'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        type: {
            label: 'Typ',
            options: typeOptions,
            match: (row, value) => (value === 'operator' ? row.is_operator : !row.is_operator),
        },
    },
});

const dialogOpen = ref(false);

const form = useForm({
    name: '',
    slug: '',
});

function submit() {
    form.post(route('admin.organizations.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

function destroyOrganization(id: string) {
    router.delete(route('admin.organizations.destroy', id), {
        onBefore: () => confirm('Kunde wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Kunden" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Kunden</h1>
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Kunde anlegen
                </Button>
            </div>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Kunden angelegt.">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.type.value"
                        :options="typeOptions"
                        placeholder="Typ"
                        class="w-40"
                        @update:model-value="(v) => table.setFilter('type', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <tr
                        v-for="org in rows"
                        :key="org.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3">
                            <Link :href="route('admin.organizations.show', org.id)" class="font-medium text-copper-hi hover:underline">
                                {{ org.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ org.slug }}</td>
                        <td class="px-4 py-3">
                            <span
                                :class="
                                    cn(
                                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                        org.is_operator
                                            ? 'border-copper/30 bg-copper/15 text-copper-hi'
                                            : 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
                                    )
                                "
                            >
                                {{ org.is_operator ? 'Betreiber' : 'Kunde' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ org.groups_count }}</td>
                        <td class="px-4 py-3">{{ org.users_count }}</td>
                        <td class="px-4 py-3">
                            <Button
                                v-if="!org.is_operator"
                                variant="ghost"
                                size="icon"
                                @click="destroyOrganization(org.id)"
                                aria-label="Kunde löschen"
                            >
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Kunde anlegen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" placeholder="Kadenz GmbH" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="slug">Slug</Label>
                        <Input id="slug" v-model="form.slug" placeholder="kadenz" autocomplete="off" />
                        <InputError :message="form.errors.slug" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
