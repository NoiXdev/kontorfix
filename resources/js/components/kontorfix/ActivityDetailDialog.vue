<script setup lang="ts">
import JsonViewer from '@/components/kontorfix/JsonViewer.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { ActivityEntry } from '@/lib/activityGroups';
import { computed } from 'vue';

const props = defineProps<{ entry: ActivityEntry | null }>();

const open = defineModel<boolean>('open', { default: false });

// Spatie stores the event in English. One map for the whole component so the wording
// cannot drift between the title and the marker, and so an event nobody mapped yet shows
// its raw name rather than disappearing.
const EVENT_LABELS: Record<string, string> = {
    created: 'Erstellt',
    updated: 'Aktualisiert',
    deleted: 'Gelöscht',
};

interface ChangeRow {
    key: string;
    before: unknown;
    after: unknown;
    /** The key is absent from `old` — it was set for the first time, which is not the same as being set to nothing. */
    isNew: boolean;
}

/** A plain object, or null for anything else — `changes` is `unknown` per key and arrives from the log. */
function asRecord(value: unknown): Record<string, unknown> | null {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    return value as Record<string, unknown>;
}

/** Objects and arrays are shown as structured rather than flattened into something that reads like a scalar. */
function isStructured(value: unknown): boolean {
    return value !== null && typeof value === 'object';
}

const title = computed(() => {
    if (!props.entry) {
        return '';
    }

    const event = props.entry.event;

    if (!event) {
        return props.entry.description;
    }

    return EVENT_LABELS[event] ?? event;
});

const subject = computed(() => {
    if (!props.entry?.subject_type) {
        return null;
    }

    return props.entry.subject_label ? `${props.entry.subject_type} · ${props.entry.subject_label}` : props.entry.subject_type;
});

const attributes = computed(() => asRecord(props.entry?.changes.attributes));
const previous = computed(() => asRecord(props.entry?.changes.old) ?? {});

const rows = computed<ChangeRow[]>(() =>
    Object.entries(attributes.value ?? {}).map(([key, after]) => ({
        key,
        after,
        before: previous.value[key],
        isNew: !Object.prototype.hasOwnProperty.call(previous.value, key),
    })),
);

// A deletion carries no `attributes` at all. Its description is the only thing left to
// show, and an empty table would read as "nothing changed".
const hasAttributes = computed(() => attributes.value !== null);

const hasRawPayload = computed(() => Object.keys(props.entry?.changes ?? {}).length > 0);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="entry" class="max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    <span v-if="subject">{{ subject }} · </span>
                    <span>von {{ entry.causer ?? 'System' }}</span>
                    <span v-if="entry.created_at_exact"> · {{ entry.created_at_exact }}</span>
                </DialogDescription>
            </DialogHeader>

            <div v-if="!hasAttributes" class="rounded-lg border border-border px-3 py-4 text-sm text-muted-foreground">
                {{ entry.description }}
            </div>

            <p v-else-if="rows.length === 0" class="rounded-lg border border-border px-3 py-4 text-sm text-muted-foreground">
                Keine geänderten Felder aufgezeichnet.
            </p>

            <div v-else class="overflow-hidden rounded-lg border border-border">
                <div class="hidden bg-muted/40 px-3 py-2 text-xs font-medium text-muted-foreground sm:grid sm:grid-cols-[10rem_1fr_1fr] sm:gap-3">
                    <span>Feld</span>
                    <span>Vorher</span>
                    <span>Nachher</span>
                </div>
                <dl class="divide-y divide-border">
                    <div v-for="row in rows" :key="row.key" class="grid gap-1 px-3 py-2 text-sm sm:grid-cols-[10rem_1fr_1fr] sm:gap-3">
                        <dt class="font-mono text-xs break-all text-muted-foreground sm:pt-0.5">{{ row.key }}</dt>
                        <dd class="min-w-0">
                            <span v-if="row.isNew" class="text-xs text-muted-foreground italic">Neu gesetzt</span>
                            <span v-else-if="isStructured(row.before)" class="text-xs text-muted-foreground italic">
                                Strukturierter Wert — siehe Rohdaten
                            </span>
                            <span v-else-if="row.before === null" class="text-xs text-muted-foreground italic">null</span>
                            <span v-else-if="row.before === ''" class="text-xs text-muted-foreground italic">leer</span>
                            <span v-else class="break-words text-muted-foreground">{{ row.before }}</span>
                        </dd>
                        <dd class="min-w-0">
                            <span v-if="isStructured(row.after)" class="text-xs text-muted-foreground italic">
                                Strukturierter Wert — siehe Rohdaten
                            </span>
                            <span v-else-if="row.after === null" class="text-xs text-muted-foreground italic">null</span>
                            <span v-else-if="row.after === ''" class="text-xs text-muted-foreground italic">leer</span>
                            <span v-else class="break-words">{{ row.after }}</span>
                        </dd>
                    </div>
                </dl>
            </div>

            <details v-if="hasRawPayload" class="rounded-lg border border-border px-3 py-2">
                <summary class="cursor-pointer text-xs text-muted-foreground select-none hover:text-foreground">Rohdaten anzeigen</summary>
                <div class="mt-2 max-h-72 overflow-auto">
                    <JsonViewer :value="entry.changes" :max-depth="3" />
                </div>
            </details>
        </DialogContent>
    </Dialog>
</template>
