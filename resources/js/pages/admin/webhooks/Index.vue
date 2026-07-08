<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

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

interface IncomingInfo {
    configured: boolean;
    urls: {
        github: string;
        gitlab: string;
        gitea: string;
        bitbucket: string;
    };
}

const props = defineProps<{
    webhooks: WebhookRow[];
    incoming: IncomingInfo;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Webhooks', href: '/admin/webhooks' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const dialogOpen = ref(false);

const eventOptions: { value: WebhookEventKey; label: string }[] = [
    { value: 'package.synced', label: 'Paket synchronisiert' },
    { value: 'sync.failed', label: 'Sync fehlgeschlagen' },
    { value: 'version.released', label: 'Version veröffentlicht' },
];

const form = useForm({
    url: '',
    secret: '',
    events: [] as WebhookEventKey[],
});

function toggleEvent(value: WebhookEventKey, checked: boolean) {
    if (checked) {
        if (!form.events.includes(value)) {
            form.events.push(value);
        }
    } else {
        form.events = form.events.filter((e) => e !== value);
    }
}

function submit() {
    form.transform((data) => ({
        url: data.url,
        secret: data.secret || null,
        events: data.events,
    })).post(route('admin.webhooks.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

function eventLabel(value: string) {
    return eventOptions.find((o) => o.value === value)?.label ?? value;
}

function destroyWebhook(id: string) {
    router.delete(route('admin.webhooks.destroy', id), {
        onBefore: () => confirm('Webhook wirklich löschen?'),
    });
}

function copyToClipboard(value: string) {
    navigator.clipboard?.writeText(value);
}
</script>

<template>
    <Head title="Webhooks" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold">Eingehende Webhooks</h2>
                    <span
                        :class="
                            cn(
                                'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                props.incoming.configured
                                    ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                    : 'border-destructive/30 bg-destructive/15 text-destructive',
                            )
                        "
                    >
                        {{ props.incoming.configured ? 'Konfiguriert' : 'Kein Secret gesetzt' }}
                    </span>
                </div>

                <div class="space-y-2">
                    <div
                        v-for="(url, provider) in props.incoming.urls"
                        :key="provider"
                        class="flex items-center justify-between gap-2 rounded-md border border-sidebar-border/70 px-3 py-2 dark:border-sidebar-border"
                    >
                        <span class="w-24 shrink-0 text-sm font-medium capitalize">{{ provider }}</span>
                        <code class="flex-1 truncate font-mono text-xs text-muted-foreground">{{ url }}</code>
                        <Button variant="ghost" size="sm" @click="copyToClipboard(url)">Kopieren</Button>
                    </div>
                </div>

                <p class="mt-3 text-xs text-muted-foreground">
                    Damit eingehende Webhooks angenommen werden, muss <code class="font-mono">KONTORFIX_INCOMING_WEBHOOK_SECRET</code>
                    gesetzt und im Git-Host als Webhook-Secret hinterlegt sein.
                </p>
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Ausgehende Webhooks</h1>
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Webhook hinzufügen
                </Button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">URL</th>
                            <th class="px-4 py-3 font-medium">Events</th>
                            <th class="px-4 py-3 font-medium">Auth</th>
                            <th class="px-4 py-3 font-medium">Aktiv</th>
                            <th class="px-4 py-3 font-medium">Letzte Zustellungen</th>
                            <th class="px-4 py-3 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="webhook in props.webhooks"
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
                                >
                                    Signiert
                                </span>
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
                                <Button variant="ghost" size="icon" @click="destroyWebhook(webhook.id)" aria-label="Webhook löschen">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="props.webhooks.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">Noch keine Webhooks angelegt.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Webhook hinzufügen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="url">URL</Label>
                        <Input id="url" v-model="form.url" placeholder="https://hooks.example.com/kfx" autocomplete="off" />
                        <InputError :message="form.errors.url" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="secret">Secret (optional)</Label>
                        <Input id="secret" v-model="form.secret" type="password" autocomplete="off" />
                        <InputError :message="form.errors.secret" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Events</Label>
                        <div class="space-y-2">
                            <label v-for="option in eventOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    :checked="form.events.includes(option.value)"
                                    class="size-4 rounded border-input"
                                    @change="toggleEvent(option.value, ($event.target as HTMLInputElement).checked)"
                                />
                                {{ option.label }}
                            </label>
                        </div>
                        <InputError :message="form.errors.events" />
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
