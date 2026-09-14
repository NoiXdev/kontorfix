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
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
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

function submit() {
    emit('submit', {
        id: id.value === '' ? null : id.value,
        available_until: availableUntil.value === '' ? null : availableUntil.value,
        version_min: versionMin.value === '' ? null : versionMin.value,
        version_max: versionMax.value === '' ? null : versionMax.value,
    });
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
