<script setup lang="ts">
/**
 * The one licence editor, embedded by BOTH entry points. Only the picker's contents
 * differ — organizations on the package page, packages on the customer page — so the
 * options are a prop and this component owns no routing and no request: the host decides
 * where a submit goes. That is what keeps "two entry points" from becoming two UIs that
 * drift.
 */
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    mode: 'create' | 'edit';
    title: string;
    pickerLabel: string;
    options: Array<{ value: string; label: string }>;
    initial: { id: string | null; availableUntil: string; versionMin: string; versionMax: string };
    errors: Record<string, string>;
    saving: boolean;
    /**
     * What the currently entered window would do to the registries that already carry the
     * package. Computed by the server and handed down — this component never compares
     * versions itself, because an ecosystem-aware rule stated twice diverges, and the last
     * client-side comparator rated `2.0.0-beta1` equal to `2.0.0`.
     *
     * `null` means "not asked yet, or in flight": the preview then says nothing at all
     * rather than showing a count that may be stale.
     */
    preview: LicencePreview | null;
}>();

export type LicencePreview = {
    registries: Array<{ id: string; name: string; narrowed: boolean; emptied: boolean }>;
    narrowed_count: number;
    emptied_count: number;
};

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    /** The host fetches; this component only says what the operator has typed so far. */
    (e: 'preview', payload: { id: string | null; available_until: string | null; version_min: string | null; version_max: string | null }): void;
    (e: 'submit', payload: { id: string | null; available_until: string | null; version_min: string | null; version_max: string | null }): void;
}>();

// The picker's own model is a plain `string` (SearchableSelect's `T` is constrained to
// `string | number`), not `string | null` — `props.initial.id` is `null` only for a
// create-mode dialog with nothing pre-selected, which maps to the empty string here and
// back to `null` on submit, exactly as the "leave blank" fields below already do.
const id = ref(props.initial.id ?? '');
const availableUntil = ref(props.initial.availableUntil);
const versionMin = ref(props.initial.versionMin);
const versionMax = ref(props.initial.versionMax);

watch(
    () => props.initial,
    (next) => {
        id.value = next.id ?? '';
        availableUntil.value = next.availableUntil;
        versionMin.value = next.versionMin;
        versionMax.value = next.versionMax;
    },
);

let previewTimer: ReturnType<typeof setTimeout> | undefined;

// Debounced: the operator types a version a character at a time, and every keystroke is a
// round trip otherwise. 400ms is long enough to stop mid-word requests and short enough
// that the answer is there before they reach for the save button.
watch([id, availableUntil, versionMin, versionMax], () => {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => emit('preview', payload()), 400);
});

function payload() {
    return {
        id: id.value === '' ? null : id.value,
        available_until: availableUntil.value === '' ? null : availableUntil.value,
        version_min: versionMin.value === '' ? null : versionMin.value,
        version_max: versionMax.value === '' ? null : versionMax.value,
    };
}

function submit() {
    emit('submit', payload());
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader><DialogTitle>{{ props.title }}</DialogTitle></DialogHeader>

            <div class="grid gap-4">
                <div v-if="props.mode === 'create'" class="grid gap-2">
                    <Label for="lizenz-ziel">{{ props.pickerLabel }}</Label>
                    <SearchableSelect id="lizenz-ziel" v-model="id" :options="props.options" placeholder="Auswählen…" />
                    <InputError :message="props.errors.package_id ?? props.errors.organization_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="lizenz-bis">Verfügbar bis (leer = unbegrenzt)</Label>
                    <Input id="lizenz-bis" v-model="availableUntil" type="date" />
                    <InputError :message="props.errors.available_until" />
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label for="lizenz-min">Version ab</Label>
                        <Input id="lizenz-min" v-model="versionMin" placeholder="z. B. 1.0.0" />
                        <InputError :message="props.errors.version_min" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="lizenz-max">Version bis</Label>
                        <Input id="lizenz-max" v-model="versionMax" placeholder="z. B. 2.9.9" />
                        <InputError :message="props.errors.version_max" />
                    </div>
                </div>

                <div v-if="props.preview" class="rounded-md border border-sidebar-border/70 p-3 text-xs dark:border-sidebar-border">
                    <p v-if="props.preview.registries.length === 0" class="text-muted-foreground">
                        Keine Registry dieser Organisation führt dieses Paket — diese Lizenz ändert an
                        bestehenden Zuordnungen nichts.
                    </p>
                    <template v-else>
                        <p :class="props.preview.emptied_count > 0 ? 'text-destructive' : 'text-muted-foreground'">
                            Wirkung auf bestehende Zuordnungen:
                            {{ props.preview.narrowed_count }} eingegrenzt,
                            {{ props.preview.emptied_count }} ohne verbleibende Version.
                        </p>
                        <ul class="mt-1 space-y-0.5">
                            <li
                                v-for="registry in props.preview.registries"
                                :key="registry.id"
                                :class="registry.emptied ? 'text-destructive' : 'text-muted-foreground'"
                            >
                                {{ registry.name }}
                                <span v-if="registry.emptied">— liefert dann nichts mehr</span>
                                <span v-else-if="registry.narrowed">— wird eingegrenzt</span>
                                <span v-else>— unverändert</span>
                            </li>
                        </ul>
                    </template>
                </div>

                <p class="text-xs text-muted-foreground">
                    Die Lizenz wird über die organisationsweite Quelle ausgeliefert und ist zugleich die Obergrenze für
                    jede Registry dieser Organisation. Eine Lizenz zu entfernen ist nicht dasselbe, wie sie ablaufen zu
                    lassen: Entfernen hebt nur die Obergrenze auf, Ablaufen entzieht das Paket überall.
                </p>

                <div><Button :disabled="props.saving" @click="submit">Speichern</Button></div>
            </div>
        </DialogContent>
    </Dialog>
</template>
