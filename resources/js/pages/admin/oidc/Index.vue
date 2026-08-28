<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Plus, ShieldCheck, ShieldOff, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

type Role = 'member' | 'maintainer' | 'admin';

interface ProviderRow {
    id: string;
    name: string;
    slug: string;
    issuer: string;
    enabled: boolean;
    allow_registration: boolean;
    trusts_email_claim: boolean;
    has_secret: boolean;
    default_role: Role | null;
    default_organization_id: string | null;
}

const props = defineProps<{
    providers: ProviderRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'OIDC-Provider', href: '/admin/oidc' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

// Derived from the existing `enabled` prop — labels for the two values that
// boolean already takes, not a separate controller-supplied option list.
const activeOptions = [
    { value: 'yes', label: 'Ja' },
    { value: 'no', label: 'Nein' },
];

const columns: ColumnDef<ProviderRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'slug', label: 'Slug' },
    { key: 'issuer', label: 'Issuer' },
    { key: 'enabled', label: 'Aktiv', sortValue: (row) => (row.enabled ? 'Ja' : 'Nein') },
    { key: 'allow_registration', label: 'Registrierung', sortValue: (row) => (row.allow_registration ? 'Erlaubt' : 'Gesperrt') },
    {
        key: 'trusts_email_claim',
        label: 'E-Mail-Vertrauen',
        sortValue: (row) => (row.trusts_email_claim ? 'Vertrauenswürdig' : 'Nicht vertrauenswürdig'),
    },
    { key: 'has_secret', label: 'Secret', sortable: false },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const table = useTableState<ProviderRow>({
    rows: () => props.providers,
    columns,
    searchKeys: ['name', 'slug', 'issuer'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        active: {
            label: 'Aktiv',
            options: activeOptions,
            match: (row, value) => (value === 'yes' ? row.enabled : !row.enabled),
        },
    },
});

function destroyProvider(id: string) {
    router.delete(route('admin.oidc.destroy', id), {
        onBefore: () => confirm('OIDC-Provider wirklich löschen?'),
    });
}

function toggleTrust(provider: ProviderRow) {
    const next = !provider.trusts_email_claim;
    router.patch(
        route('admin.oidc.trust', provider.id),
        { trusts_email_claim: next },
        {
            preserveScroll: true,
            onBefore: () =>
                next ||
                confirm(
                    'Provider als nicht vertrauenswürdig für E-Mail-Zusicherungen markieren? Bestehende Konten werden dann nicht mehr automatisch über die E-Mail-Adresse verknüpft.',
                ),
        },
    );
}

const badgeClasses = (on: boolean) =>
    cn(
        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
        on ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400' : 'border-border bg-muted text-muted-foreground',
    );
</script>

<template>
    <Head title="OIDC-Provider" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">OIDC-Provider</h1>
                <Button as-child>
                    <Link :href="route('admin.oidc.create')">
                        <Plus class="size-4" />
                        Provider hinzufügen
                    </Link>
                </Button>
            </div>

            <DataTable :columns="columns" :state="table" empty-message="Noch keine OIDC-Provider angelegt.">
                <template #filters>
                    <SearchableSelect
                        :model-value="table.filterValues.active.value"
                        :options="activeOptions"
                        placeholder="Aktiv"
                        class="w-32"
                        @update:model-value="(v) => table.setFilter('active', String(v))"
                    />
                </template>

                <template #default="{ rows }">
                    <tr
                        v-for="provider in rows"
                        :key="provider.id"
                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3">{{ provider.name }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ provider.slug }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ provider.issuer }}</td>
                        <td class="px-4 py-3">
                            <span :class="badgeClasses(provider.enabled)">{{ provider.enabled ? 'Ja' : 'Nein' }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="badgeClasses(provider.allow_registration)">
                                {{ provider.allow_registration ? 'Erlaubt' : 'Gesperrt' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5">
                                <span :class="badgeClasses(provider.trusts_email_claim)">
                                    {{ provider.trusts_email_claim ? 'Vertrauenswürdig' : 'Nicht vertrauenswürdig' }}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-7"
                                    @click="toggleTrust(provider)"
                                    :aria-label="
                                        provider.trusts_email_claim
                                            ? 'Als nicht vertrauenswürdig für E-Mail-Zusicherungen markieren'
                                            : 'Als vertrauenswürdig für E-Mail-Zusicherungen markieren'
                                    "
                                >
                                    <ShieldOff v-if="provider.trusts_email_claim" class="size-4" />
                                    <ShieldCheck v-else class="size-4 text-emerald-600 dark:text-emerald-400" />
                                </Button>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                v-if="provider.has_secret"
                                class="inline-flex items-center rounded-md border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                            >
                                Gesetzt
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <Button variant="ghost" size="icon" @click="destroyProvider(provider.id)" aria-label="Provider löschen">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
