<script setup lang="ts">
import DataTable from '@/components/kontorfix/DataTable.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { useTableState, type ColumnDef } from '@/composables/useTableState';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Copy, Plus, RefreshCw, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

type WebhookEventKey = 'package.synced' | 'sync.failed' | 'version.released';

interface DeliveryRow {
    event: string;
    status_code: number | null;
    success: boolean;
    attempts: number;
    delivered_at: string | null;
}

interface WebhookRow {
    id: string;
    url: string;
    events: string[];
    enabled: boolean;
    has_secret: boolean;
    recent_deliveries: DeliveryRow[];
}

interface IncomingRow {
    id: string;
    name: string;
    provider: string;
    enabled: boolean;
    url: string;
    last_received_at: string | null;
    // Raw ISO timestamp, sort-only — `last_received_at` is a relative string ("vor 3 Tagen")
    // that Date.parse cannot read.
    last_received_at_iso: string | null;
}

interface AuditIncoming {
    id: string;
    source: string | null;
    provider: string;
    repo_url: string | null;
    signature_valid: boolean;
    matched_packages: number;
    status_code: number | null;
    ip: string | null;
    payload: unknown;
    received_at: string | null;
}

interface AuditOutgoing {
    id: string;
    url: string | null;
    event: string;
    status_code: number | null;
    success: boolean;
    attempts: number;
    error: string | null;
    payload: unknown;
    delivered_at: string | null;
}

const props = defineProps<{
    webhooks: WebhookRow[];
    incoming: IncomingRow[];
    legacy: { configured: boolean; urls: Record<string, string> };
    audit: { incoming: AuditIncoming[]; outgoing: AuditOutgoing[] };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Webhooks', href: '/admin/webhooks' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const newSecret = computed(() => page.props.flash?.incomingWebhookSecret ?? null);
const newSecretUrl = computed(() => page.props.flash?.incomingWebhookUrl ?? null);

const eventOptions: { value: WebhookEventKey; label: string }[] = [
    { value: 'package.synced', label: 'Paket synchronisiert' },
    { value: 'sync.failed', label: 'Sync fehlgeschlagen' },
    { value: 'version.released', label: 'Version veröffentlicht' },
];

function eventLabel(value: string) {
    return eventOptions.find((o) => o.value === value)?.label ?? value;
}

function copyToClipboard(value: string) {
    navigator.clipboard?.writeText(value);
}

function pretty(payload: unknown): string {
    try {
        return JSON.stringify(payload, null, 2);
    } catch {
        return String(payload);
    }
}

const providerOptions = [
    { value: 'github', label: 'GitHub' },
    { value: 'gitlab', label: 'GitLab' },
    { value: 'gitea', label: 'Gitea' },
    { value: 'bitbucket', label: 'Bitbucket' },
];

const activeOptions = [
    { value: '1', label: 'Ja' },
    { value: '0', label: 'Nein' },
];

// Incoming and outgoing each get their own useTableState instance with a distinct
// prefix ('in' / 'out') — without it both tables would read and write the same
// sort/direction/q query keys, and sorting one would silently reorder the other.
const incomingColumns: ColumnDef<IncomingRow>[] = [
    { key: 'name', label: 'Name' },
    { key: 'provider', label: 'Provider' },
    { key: 'url', label: 'URL' },
    { key: 'last_received_at', label: 'Zuletzt empfangen', sortAs: 'date', sortValue: (row) => row.last_received_at_iso },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const incomingTable = useTableState<IncomingRow>({
    rows: () => props.incoming,
    columns: incomingColumns,
    prefix: 'in',
    searchKeys: ['name', 'url'],
    defaultSort: { key: 'name', direction: 'asc' },
    filters: {
        provider: {
            label: 'Provider',
            options: providerOptions,
            match: (row, value) => row.provider === value,
        },
        enabled: {
            label: 'Aktiv',
            options: activeOptions,
            match: (row, value) => row.enabled === (value === '1'),
        },
    },
});

// The outgoing row has no `name`/`provider` field (only IncomingWebhook carries those),
// so it searches over `url` only and filters on `enabled` only.
const outgoingColumns: ColumnDef<WebhookRow>[] = [
    { key: 'url', label: 'URL' },
    { key: 'events', label: 'Events', sortable: false },
    { key: 'has_secret', label: 'Auth', sortValue: (row) => (row.has_secret ? 'Signiert' : '—') },
    { key: 'enabled', label: 'Aktiv', sortValue: (row) => (row.enabled ? 'Ja' : 'Nein') },
    { key: 'recent_deliveries', label: 'Letzte Zustellungen', sortable: false },
    { key: 'actions', label: 'Aktionen', sortable: false },
];

const outgoingTable = useTableState<WebhookRow>({
    rows: () => props.webhooks,
    columns: outgoingColumns,
    prefix: 'out',
    searchKeys: ['url'],
    defaultSort: { key: 'url', direction: 'asc' },
    filters: {
        enabled: {
            label: 'Aktiv',
            options: activeOptions,
            match: (row, value) => row.enabled === (value === '1'),
        },
    },
});

function destroyWebhook(id: string) {
    router.delete(route('admin.webhooks.destroy', id), { onBefore: () => confirm('Webhook wirklich löschen?') });
}

// --- Incoming manage ---

function regenerateIncoming(id: string) {
    router.post(
        route('admin.incoming-webhooks.regenerate', id),
        {},
        { preserveScroll: true, onBefore: () => confirm('Neues Secret erzeugen? Das alte wird ungültig.') },
    );
}

function destroyIncoming(id: string) {
    router.delete(route('admin.incoming-webhooks.destroy', id), {
        preserveScroll: true,
        onBefore: () => confirm('Eingehenden Webhook wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Webhooks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <!-- Reveal-once secret callout after creating/regenerating an incoming hook -->
            <div v-if="newSecret" class="rounded-xl border border-copper/30 bg-copper/10 p-4">
                <p class="font-medium text-copper-hi">Secret erstellt — nur jetzt sichtbar</p>
                <div class="mt-2 space-y-2">
                    <div>
                        <span class="text-xs text-muted-foreground">Endpoint-URL (im Git-Host eintragen)</span>
                        <div class="flex items-center gap-2">
                            <code
                                class="flex-1 rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-xs break-all select-all"
                                >{{ newSecretUrl }}</code
                            >
                            <Button variant="outline" size="sm" @click="copyToClipboard(newSecretUrl ?? '')"><Copy class="size-4" /></Button>
                        </div>
                    </div>
                    <div>
                        <span class="text-xs text-muted-foreground">Secret (im Git-Host als Webhook-Secret hinterlegen)</span>
                        <div class="flex items-center gap-2">
                            <code
                                class="flex-1 rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-xs break-all select-all"
                                >{{ newSecret }}</code
                            >
                            <Button variant="outline" size="sm" @click="copyToClipboard(newSecret ?? '')"><Copy class="size-4" /></Button>
                        </div>
                    </div>
                </div>
            </div>

            <Tabs default-value="incoming">
                <TabsList>
                    <TabsTrigger value="incoming">Eingehend</TabsTrigger>
                    <TabsTrigger value="outgoing">Ausgehend</TabsTrigger>
                    <TabsTrigger value="audit">Audit</TabsTrigger>
                </TabsList>

                <!-- Incoming -->
                <TabsContent value="incoming" class="space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-xl font-semibold">Eingehende Webhooks</h1>
                            <p class="text-sm text-muted-foreground">
                                Pro Git-Host/Repo ein eigener Endpunkt mit eigenem Secret. Push löst den Sync passender Pakete aus.
                            </p>
                        </div>
                        <Button as-child>
                            <Link :href="route('admin.incoming-webhooks.create')">
                                <Plus class="size-4" />
                                Endpunkt anlegen
                            </Link>
                        </Button>
                    </div>

                    <DataTable :columns="incomingColumns" :state="incomingTable" empty-message="Noch keine eingehenden Endpunkte angelegt.">
                        <template #filters>
                            <SearchableSelect
                                :model-value="incomingTable.filterValues.provider.value"
                                :options="providerOptions"
                                placeholder="Provider"
                                class="w-40"
                                @update:model-value="(v) => incomingTable.setFilter('provider', String(v))"
                            />
                            <SearchableSelect
                                :model-value="incomingTable.filterValues.enabled.value"
                                :options="activeOptions"
                                placeholder="Aktiv"
                                class="w-32"
                                @update:model-value="(v) => incomingTable.setFilter('enabled', String(v))"
                            />
                        </template>

                        <template #default="{ rows }">
                            <tr
                                v-for="hook in rows"
                                :key="hook.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-medium">{{ hook.name }}</td>
                                <td class="px-4 py-3 capitalize">{{ hook.provider }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <code class="max-w-xs truncate font-mono text-xs text-muted-foreground">{{ hook.url }}</code>
                                        <Button variant="ghost" size="icon" aria-label="URL kopieren" @click="copyToClipboard(hook.url)"
                                            ><Copy class="size-4"
                                        /></Button>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">{{ hook.last_received_at ?? 'nie' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1">
                                        <Button variant="ghost" size="icon" aria-label="Secret neu erzeugen" @click="regenerateIncoming(hook.id)"
                                            ><RefreshCw class="size-4"
                                        /></Button>
                                        <Button variant="ghost" size="icon" aria-label="Löschen" @click="destroyIncoming(hook.id)"
                                            ><Trash2 class="size-4 text-destructive"
                                        /></Button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </DataTable>

                    <details class="rounded-xl border border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border">
                        <summary class="cursor-pointer text-muted-foreground">
                            Legacy-Endpunkte (globales <code class="font-mono">KONTORFIX_INCOMING_WEBHOOK_SECRET</code>)
                        </summary>
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ props.legacy.configured ? 'Konfiguriert.' : 'Kein globales Secret gesetzt.' }} Die neuen Endpunkte oben sind der
                            empfohlene Weg.
                        </p>
                        <div class="mt-2 space-y-1">
                            <div v-for="(url, provider) in props.legacy.urls" :key="provider" class="flex items-center gap-2">
                                <span class="w-20 shrink-0 text-xs font-medium capitalize">{{ provider }}</span>
                                <code class="flex-1 truncate font-mono text-xs text-muted-foreground">{{ url }}</code>
                            </div>
                        </div>
                    </details>
                </TabsContent>

                <!-- Outgoing -->
                <TabsContent value="outgoing" class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h1 class="text-xl font-semibold">Ausgehende Webhooks</h1>
                        <Button as-child>
                            <Link :href="route('admin.webhooks.create')">
                                <Plus class="size-4" />
                                Webhook hinzufügen
                            </Link>
                        </Button>
                    </div>

                    <DataTable :columns="outgoingColumns" :state="outgoingTable" empty-message="Noch keine Webhooks angelegt.">
                        <template #filters>
                            <SearchableSelect
                                :model-value="outgoingTable.filterValues.enabled.value"
                                :options="activeOptions"
                                placeholder="Aktiv"
                                class="w-32"
                                @update:model-value="(v) => outgoingTable.setFilter('enabled', String(v))"
                            />
                        </template>

                        <template #default="{ rows }">
                            <tr
                                v-for="webhook in rows"
                                :key="webhook.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-mono text-xs">{{ webhook.url }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="event in webhook.events"
                                            :key="event"
                                            class="inline-flex items-center rounded-md border border-border bg-muted px-2 py-0.5 text-xs font-medium"
                                        >
                                            {{ eventLabel(event) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="webhook.has_secret"
                                        class="inline-flex items-center rounded-md border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                                        >Signiert</span
                                    >
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="
                                            cn(
                                                'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                                webhook.enabled
                                                    ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                                    : 'border-border bg-muted text-muted-foreground',
                                            )
                                        "
                                    >
                                        {{ webhook.enabled ? 'Ja' : 'Nein' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <ul v-if="webhook.recent_deliveries.length > 0" class="space-y-1">
                                        <li
                                            v-for="(delivery, idx) in webhook.recent_deliveries"
                                            :key="idx"
                                            class="flex items-center gap-2 text-xs"
                                        >
                                            <span
                                                :class="
                                                    cn(
                                                        'inline-flex items-center rounded-full border px-2 py-0.5 font-medium',
                                                        delivery.success
                                                            ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                                            : 'border-destructive/30 bg-destructive/15 text-destructive',
                                                    )
                                                "
                                            >
                                                {{ delivery.status_code ?? '—' }}
                                            </span>
                                            <span>{{ eventLabel(delivery.event) }}</span>
                                            <span class="text-muted-foreground">{{ delivery.delivered_at }}</span>
                                        </li>
                                    </ul>
                                    <span v-else class="text-muted-foreground">Noch keine Zustellungen.</span>
                                </td>
                                <td class="px-4 py-3">
                                    <Button variant="ghost" size="icon" @click="destroyWebhook(webhook.id)" aria-label="Webhook löschen"
                                        ><Trash2 class="size-4 text-destructive"
                                    /></Button>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </TabsContent>

                <!-- Audit -->
                <TabsContent value="audit" class="space-y-6">
                    <section class="space-y-2">
                        <h2 class="text-base font-semibold">Eingehend (letzte {{ props.audit.incoming.length }})</h2>
                        <div class="space-y-2">
                            <details
                                v-for="e in props.audit.incoming"
                                :key="e.id"
                                class="rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border"
                            >
                                <summary class="flex cursor-pointer flex-wrap items-center gap-2">
                                    <span
                                        :class="
                                            cn(
                                                'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
                                                e.signature_valid
                                                    ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                                    : 'border-destructive/30 bg-destructive/15 text-destructive',
                                            )
                                        "
                                    >
                                        {{ e.status_code }}
                                    </span>
                                    <span class="capitalize">{{ e.provider }}</span>
                                    <span v-if="e.source" class="text-xs text-muted-foreground">· {{ e.source }}</span>
                                    <span class="truncate font-mono text-xs text-muted-foreground">{{ e.repo_url ?? '—' }}</span>
                                    <span class="ml-auto text-xs text-muted-foreground">{{ e.matched_packages }} Paket(e) · {{ e.received_at }}</span>
                                </summary>
                                <pre
                                    class="mt-2 max-h-80 overflow-auto rounded-md border border-sidebar-border/70 bg-muted/40 p-3 text-xs dark:border-sidebar-border"
                                    >{{ pretty(e.payload) }}</pre>
                            </details>
                            <p v-if="props.audit.incoming.length === 0" class="text-sm text-muted-foreground">Noch keine eingehenden Ereignisse.</p>
                        </div>
                    </section>

                    <section class="space-y-2">
                        <h2 class="text-base font-semibold">Ausgehend (letzte {{ props.audit.outgoing.length }})</h2>
                        <div class="space-y-2">
                            <details
                                v-for="d in props.audit.outgoing"
                                :key="d.id"
                                class="rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border"
                            >
                                <summary class="flex cursor-pointer flex-wrap items-center gap-2">
                                    <span
                                        :class="
                                            cn(
                                                'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
                                                d.success
                                                    ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                                    : 'border-destructive/30 bg-destructive/15 text-destructive',
                                            )
                                        "
                                    >
                                        {{ d.status_code ?? '—' }}
                                    </span>
                                    <span>{{ eventLabel(d.event) }}</span>
                                    <span class="truncate font-mono text-xs text-muted-foreground">{{ d.url }}</span>
                                    <span class="ml-auto text-xs text-muted-foreground">Versuch {{ d.attempts }} · {{ d.delivered_at }}</span>
                                </summary>
                                <p v-if="d.error" class="mt-2 text-xs text-destructive">{{ d.error }}</p>
                                <pre
                                    class="mt-2 max-h-80 overflow-auto rounded-md border border-sidebar-border/70 bg-muted/40 p-3 text-xs dark:border-sidebar-border"
                                    >{{ pretty(d.payload) }}</pre>
                            </details>
                            <p v-if="props.audit.outgoing.length === 0" class="text-sm text-muted-foreground">Noch keine ausgehenden Zustellungen.</p>
                        </div>
                    </section>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
