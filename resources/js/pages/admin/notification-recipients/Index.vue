<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface RecipientRow {
    id: string;
    email: string;
    name: string | null;
    events: string[];
    enabled: boolean;
}

const props = defineProps<{
    recipients: RecipientRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Benachrichtigungsempfänger', href: '/admin/notification-recipients' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const eventMeta = computed(() => page.props.notificationEventMeta ?? []);

function eventLabel(value: string) {
    return eventMeta.value.find((o) => o.value === value)?.label ?? value;
}

const activeOptions = [
    { value: '1', label: 'Ja' },
    { value: '0', label: 'Nein' },
];

const columns: ColumnDef<RecipientRow>[] = [
    { key: 'email', label: 'E-Mail' },
    { key: 'name', label: 'Name', sortValue: (row) => row.name ?? '' },
    { key: 'events', label: 'Events', sortable: false },
    { key: 'enabled', label: 'Aktiv', sortValue: (row) => (row.enabled ? 'Ja' : 'Nein') },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<RecipientRow>({
    rows: () => props.recipients,
    columns,
    prefix: 'r',
    searchKeys: ['email', 'name'],
    defaultSort: { key: 'email', direction: 'asc' },
    filters: {
        enabled: {
            label: 'Aktiv',
            options: activeOptions,
            match: (row, value) => row.enabled === (value === '1'),
        },
    },
});

function destroyRecipient(id: string) {
    router.delete(route('admin.notification-recipients.destroy', id), { onBefore: () => confirm('Empfänger wirklich löschen?') });
}
</script>

<template>
    <Head title="Benachrichtigungsempfänger" />

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
                    <h1 class="text-xl font-semibold">Benachrichtigungsempfänger</h1>
                    <p class="text-sm text-muted-foreground">
                        Wer den periodischen Digest zu fehlgeschlagenen Hintergrundläufen per E-Mail erhält.
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('admin.notification-recipients.create')">
                        <Plus class="size-4" />
                        Empfänger hinzufügen
                    </Link>
                </Button>
            </div>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine Empfänger angelegt.">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.enabled.value"
                        :options="activeOptions"
                        placeholder="Aktiv"
                        class="w-32"
                        @update:model-value="(v) => table.setFilter('enabled', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <tr
                        v-for="recipient in rows"
                        :key="recipient.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3 font-mono text-xs">{{ recipient.email }}</td>
                        <td class="px-4 py-3">{{ recipient.name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="event in recipient.events"
                                    :key="event"
                                    class="inline-flex items-center rounded-md border border-border bg-muted px-2 py-0.5 text-xs font-medium"
                                >
                                    {{ eventLabel(event) }}
                                </span>
                                <span v-if="recipient.events.length === 0" class="text-xs text-muted-foreground">Keine Events</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                :class="
                                    cn(
                                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                        recipient.enabled
                                            ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                            : 'border-border bg-muted text-muted-foreground',
                                    )
                                "
                            >
                                {{ recipient.enabled ? 'Ja' : 'Nein' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <Button variant="ghost" size="icon" @click="destroyRecipient(recipient.id)" aria-label="Empfänger löschen"
                                ><Trash2 class="size-4 text-destructive"
                            /></Button>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
