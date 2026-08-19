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

interface TokenRow {
    id: string;
    name: string;
    ability: 'read' | 'publish';
    group: string | null;
    last_used_at: string | null;
    // Raw ISO timestamp, sort-only — `last_used_at` is a relative string ("vor 3 Tagen")
    // that Date.parse cannot read.
    last_used_at_iso: string | null;
    expires_at: string | null;
}

const props = defineProps<{
    tokens: TokenRow[];
    groups: { id: string; name: string }[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Zugriffstokens',
        href: '/settings/tokens',
    },
];

const page = usePage<SharedData>();
const plainTextToken = computed(() => page.props.flash?.plainTextToken ?? null);

// Publish tokens are organization write credentials and are admin/maintainer-only on the
// server (RegistryTokenPolicy::create). Do not offer the option to plain members.
//
// The explicit return type keeps `value` as the literal `'read' | 'publish'` union (what
// `form.ability` is actually typed as) rather than the widened `string` a plain object
// literal would infer — `SearchableSelect`'s `v-model` needs the two to line up exactly.
const abilityOptions = computed((): { value: 'read' | 'publish'; label: string }[] =>
    page.props.auth.can?.console
        ? [
              { value: 'read', label: 'Lesen' },
              { value: 'publish', label: 'Veröffentlichen' },
          ]
        : [{ value: 'read', label: 'Lesen' }],
);

const columns: ColumnDef<TokenRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'group', label: 'Geltungsbereich' },
    { key: 'ability', label: 'Recht' },
    { key: 'last_used_at', label: 'Zuletzt genutzt', sortAs: 'date', sortValue: (row) => row.last_used_at_iso },
    { key: 'actions', label: 'Aktion', sortable: false },
];

const table = useTableState<TokenRow>({
    rows: () => props.tokens,
    columns,
    searchKeys: ['name'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        ability: {
            label: 'Recht',
            options: abilityOptions.value,
            match: (row, value) => row.ability === value,
        },
    },
});

const tokenCalloutDismissed = ref(false);
watch(plainTextToken, (value) => {
    if (value) {
        tokenCalloutDismissed.value = false;
    }
});

const showTokenCallout = computed(() => !!plainTextToken.value && !tokenCalloutDismissed.value);

const tokenCopied = ref(false);

async function copyToken() {
    if (!plainTextToken.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(plainTextToken.value);
        tokenCopied.value = true;
        setTimeout(() => (tokenCopied.value = false), 2000);
    } catch {
        // Clipboard API not available (insecure context) — the token can be selected manually.
        tokenCopied.value = false;
    }
}

const form = useForm({
    name: '',
    group_id: '',
    ability: 'read' as 'read' | 'publish',
    expires_at: '',
});

function submit() {
    form.transform((d) => ({ ...d, group_id: d.group_id || null, expires_at: d.expires_at || null })).post(route('tokens.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name', 'expires_at'),
    });
}

// Empty means open-ended; the earliest selectable expiry is tomorrow.
const earliestExpiry = computed(() => new Date(Date.now() + 86400000).toISOString().slice(0, 10));

function abilityLabel(ability: 'read' | 'publish') {
    return ability === 'publish' ? 'Veröffentlichen' : 'Lesen';
}

function destroyToken(id: string) {
    router.delete(route('tokens.destroy', id), {
        preserveScroll: true,
        onBefore: () => confirm('Token wirklich widerrufen?'),
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Zugriffstokens" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Zugriffstokens" description="Persönliche Tokens für Composer/npm — global oder auf eine Registry beschränkt." />

                <div v-if="showTokenCallout" class="rounded-xl border border-copper/30 bg-copper/10 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1 space-y-2">
                            <p class="font-medium text-copper-hi">Neuer Token erstellt</p>
                            <p class="rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-sm break-all select-all">
                                {{ plainTextToken }}
                            </p>
                            <p class="text-sm text-muted-foreground">Dieser Token wird nur einmal angezeigt. Bewahre ihn sicher auf.</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Button variant="outline" size="sm" @click="copyToken">
                                <Copy class="size-4" />
                                {{ tokenCopied ? 'Kopiert!' : 'Kopieren' }}
                            </Button>
                            <Button variant="ghost" size="sm" @click="tokenCalloutDismissed = true">Schließen</Button>
                        </div>
                    </div>
                </div>

                <form
                    class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-[1fr_1fr_auto_auto_auto] sm:items-end dark:border-sidebar-border"
                    @submit.prevent="submit"
                >
                    <div class="grid gap-2">
                        <Label for="token_name">Name</Label>
                        <Input id="token_name" v-model="form.name" placeholder="ci-token" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token_group">Geltungsbereich</Label>
                        <SearchableSelect
                            id="token_group"
                            v-model="form.group_id"
                            :options="[
                                { value: '', label: 'Global (alle Registries)' },
                                ...props.groups.map((g) => ({ value: g.id, label: g.name })),
                            ]"
                        />
                        <InputError :message="form.errors.group_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token_ability">Recht</Label>
                        <SearchableSelect id="token_ability" v-model="form.ability" :options="abilityOptions" />
                        <InputError :message="form.errors.ability" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token_expires_at">Gültig bis</Label>
                        <Input id="token_expires_at" v-model="form.expires_at" type="date" :min="earliestExpiry" />
                        <InputError :message="form.errors.expires_at" />
                    </div>

                    <Button type="submit" :disabled="form.processing">
                        <Plus class="size-4" />
                        Token erstellen
                    </Button>
                </form>

                <DataTable :columns="columns" :state="table" empty-message="Noch keine Tokens erstellt.">
                    <template #filters>
                        <SearchableSelect
                            :model-value="table.filterValues.ability.value"
                            :options="abilityOptions"
                            placeholder="Recht"
                            class="w-40"
                            @update:model-value="(v) => table.setFilter('ability', String(v))"
                        />
                    </template>

                    <template #default="{ rows }">
                        <tr
                            v-for="token in rows"
                            :key="token.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3 font-mono">{{ token.name }}</td>
                            <td class="px-4 py-3">{{ token.group ?? 'Global' }}</td>
                            <td class="px-4 py-3">{{ abilityLabel(token.ability) }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ token.last_used_at ?? 'nie' }}</td>
                            <td class="px-4 py-3">
                                <Button variant="ghost" size="icon" aria-label="Token widerrufen" @click="destroyToken(token.id)">
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
