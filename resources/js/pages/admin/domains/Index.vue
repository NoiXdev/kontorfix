<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface DomainRow {
    id: string;
    hostname: string;
    group: string | null;
    group_id: string;
}

interface GroupOption {
    id: string;
    name: string;
}

const props = defineProps<{
    domains: DomainRow[];
    groups: GroupOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Domains', href: '/admin/domains' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

// Attaching a hostname is operator-only server-side (routes/web.php). Mirror that here so
// a customer-org admin is not offered a form that can only ever return 403. Detaching
// stays available to the owning organization, so the table's delete button is unguarded.
const canAttach = computed(() => page.props.auth?.can?.super === true);

const groupOptions = computed(() => props.groups.map((g) => ({ value: g.id, label: g.name })));

const columns: ColumnDef<DomainRow>[] = [
    { key: 'hostname', label: 'Hostname' },
    { key: 'group', label: 'Gruppe' },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<DomainRow>({
    rows: () => props.domains,
    columns,
    searchKeys: ['hostname'],
    defaultSort: { key: 'hostname', direction: 'asc' },
    filters: {
        group: {
            label: 'Gruppe',
            options: groupOptions.value,
            match: (row, value) => row.group_id === value,
        },
    },
});

const dialogOpen = ref(false);

const form = useForm({
    group_id: '',
    hostname: '',
});

function submit() {
    form.post(route('admin.domains.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

function destroyDomain(id: string) {
    router.delete(route('admin.domains.destroy', id), {
        onBefore: () => confirm('Domain wirklich entfernen?'),
    });
}
</script>

<template>
    <Head title="Domains" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Domains</h1>
                <Button v-if="canAttach" @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Domain hinzufügen
                </Button>
            </div>

            <p class="text-sm text-muted-foreground">
                Eine Gruppe ist unter <code class="font-mono">https://&lt;Hostname&gt;/</code> erreichbar, sobald DNS und Reverse-Proxy auf diesen
                Host zeigen.
            </p>
            <p v-if="!canAttach" class="text-sm text-muted-foreground">
                Neue Hostnamen werden vom Betreiber der Instanz eingetragen — ein Hostname gilt instanzweit. Bitte wende dich an den Betreiber.
            </p>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Domains angelegt.">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.group.value"
                        :options="groupOptions"
                        placeholder="Gruppe"
                        class="w-48"
                        @update:model-value="(v) => table.setFilter('group', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <tr
                        v-for="domain in rows"
                        :key="domain.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3 font-mono text-xs">{{ domain.hostname }}</td>
                        <td class="px-4 py-3">{{ domain.group ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Button variant="ghost" size="icon" @click="destroyDomain(domain.id)" aria-label="Domain entfernen">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Domain hinzufügen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="group_id">Gruppe</Label>
                        <SearchableSelect
                            id="group_id"
                            v-model="form.group_id"
                            placeholder="Bitte wählen"
                            :options="props.groups.map((g) => ({ value: g.id, label: g.name }))"
                        />
                        <InputError :message="form.errors.group_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="hostname">Hostname</Label>
                        <Input id="hostname" v-model="form.hostname" placeholder="packages.kunde.de" autocomplete="off" />
                        <InputError :message="form.errors.hostname" />
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
