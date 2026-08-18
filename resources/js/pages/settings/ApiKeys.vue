<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface ApiKeyRow {
    id: string;
    name: string;
    permission: 'read' | 'write';
    last_used_at: string | null;
    // Raw ISO timestamp, sort-only — `last_used_at` is a relative string ("vor 3 Tagen")
    // that Date.parse cannot read.
    last_used_at_iso: string | null;
    // `expires_at` arrives as an ISO date string (toDateString()), which Date.parse reads
    // directly — no sort-only twin needed here.
    expires_at: string | null;
}

const props = defineProps<{
    apiKeys: ApiKeyRow[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'API-Keys',
        href: '/settings/api-keys',
    },
];

const page = usePage<SharedData>();
const plainApiKey = computed(() => page.props.flash?.plainApiKey ?? null);

const keyCalloutDismissed = ref(false);
watch(plainApiKey, (value) => {
    if (value) {
        keyCalloutDismissed.value = false;
    }
});

const showKeyCallout = computed(() => !!plainApiKey.value && !keyCalloutDismissed.value);

const keyCopied = ref(false);

async function copyKey() {
    if (!plainApiKey.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(plainApiKey.value);
        keyCopied.value = true;
        setTimeout(() => (keyCopied.value = false), 2000);
    } catch {
        // Clipboard API not available (insecure context) — the key can be selected manually.
        keyCopied.value = false;
    }
}

const form = useForm({
    name: '',
    permission: 'read' as 'read' | 'write',
    expires_at: '',
});

const permissionOptions = [
    { value: 'read', label: 'Lesen' },
    { value: 'write', label: 'Schreiben' },
];

const columns: ColumnDef<ApiKeyRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'permission', label: 'Berechtigung' },
    { key: 'last_used_at', label: 'Zuletzt genutzt', sortAs: 'date', sortValue: (row) => row.last_used_at_iso },
    { key: 'expires_at', label: 'Ablauf', sortAs: 'date' },
    { key: 'actions', label: 'Aktion', sortable: false },
];

const table = useTableState<ApiKeyRow>({
    rows: () => props.apiKeys,
    columns,
    searchKeys: ['name'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        permission: {
            label: 'Berechtigung',
            options: permissionOptions,
            match: (row, value) => row.permission === value,
        },
    },
});

function submit() {
    form.transform((d) => ({ ...d, expires_at: d.expires_at || null })).post(route('api-keys.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
    });
}

function permissionLabel(permission: 'read' | 'write') {
    return permission === 'write' ? 'Schreiben' : 'Lesen';
}

function destroyApiKey(id: string) {
    router.delete(route('api-keys.destroy', id), {
        preserveScroll: true,
        onBefore: () => confirm('API-Key wirklich widerrufen?'),
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="API-Keys" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="API-Keys" description="Persönliche API-Keys für die REST-API (read/write)." />

                <div v-if="showKeyCallout" class="rounded-xl border border-copper/30 bg-copper/10 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1 space-y-2">
                            <p class="font-medium text-copper-hi">Neuer API-Key erstellt</p>
                            <p class="rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-sm break-all select-all">
                                {{ plainApiKey }}
                            </p>
                            <p class="text-sm text-muted-foreground">Dieser API-Key wird nur einmal angezeigt. Bewahre ihn sicher auf.</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Button variant="outline" size="sm" @click="copyKey">
                                <Copy class="size-4" />
                                {{ keyCopied ? 'Kopiert!' : 'Kopieren' }}
                            </Button>
                            <Button variant="ghost" size="sm" @click="keyCalloutDismissed = true">Schließen</Button>
                        </div>
                    </div>
                </div>

                <form
                    class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end dark:border-sidebar-border"
                    @submit.prevent="submit"
                >
                    <div class="grid gap-2">
                        <Label for="api_key_name">Name</Label>
                        <Input id="api_key_name" v-model="form.name" placeholder="deploy" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_key_permission">Berechtigung</Label>
                        <SearchableSelect
                            id="api_key_permission"
                            v-model="form.permission"
                            :options="[
                                { value: 'read', label: 'Lesen' },
                                { value: 'write', label: 'Schreiben' },
                            ]"
                        />
                        <InputError :message="form.errors.permission" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="api_key_expires_at">Ablauf</Label>
                        <input
                            id="api_key_expires_at"
                            v-model="form.expires_at"
                            type="date"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                        />
                        <InputError :message="form.errors.expires_at" />
                    </div>

                    <Button type="submit" :disabled="form.processing">
                        <Plus class="size-4" />
                        API-Key erstellen
                    </Button>
                </form>

                <DataTable :columns="columns" :state="table" empty-message="Noch keine API-Keys erstellt.">
                    <template #filters>
                        <SearchableSelect
                            :model-value="table.filterValues.permission.value"
                            :options="permissionOptions"
                            placeholder="Berechtigung"
                            class="w-40"
                            @update:model-value="(v) => table.setFilter('permission', String(v))"
                        />
                    </template>

                    <template #default="{ rows }">
                        <tr
                            v-for="apiKey in rows"
                            :key="apiKey.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3 font-mono">{{ apiKey.name }}</td>
                            <td class="px-4 py-3">{{ permissionLabel(apiKey.permission) }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ apiKey.last_used_at ?? 'nie' }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ apiKey.expires_at ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Button variant="ghost" size="icon" aria-label="API-Key widerrufen" @click="destroyApiKey(apiKey.id)">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
