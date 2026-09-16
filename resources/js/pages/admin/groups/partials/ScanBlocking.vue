<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { createPreviewEngine } from '@/composables/previewEngine';
import { postPreviewJson } from '@/composables/previewTransport';
import { SEVERITY_OPTIONS, severityClass, type Severity } from '@/lib/severity';
import { useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { parseScanPreview, scanBlockingPayload, type ScanPreview } from '../scanBlocking';

const props = defineProps<{
    groupId: string;
    blocking: { enabled: boolean; severity: Severity | null; grace_days: number };
}>();

// `scan_block_grace_days` is typed `number | string`, the same accommodation
// RetentionRulesEditor.vue's `count`/`days` fields make: the Input component's v-model
// always emits a string (see Input.vue's own docblock on why), so a plain `number` type
// here would make every keystroke a type error. `scanBlockingPayload()` converts with
// `Number()` before either request reaches the server.
const form = useForm<{ scan_block_severity: Severity | null; scan_block_grace_days: number | string }>({
    scan_block_severity: props.blocking.severity,
    scan_block_grace_days: props.blocking.grace_days,
});

const preview = ref<ScanPreview | null>(null);
const previewError = ref<string | null>(null);
const previewLoading = ref(false);

// The debounce/abort state machine itself is `createPreviewEngine()` — the same one
// `useRetentionPreview.ts` uses, generalised out of it rather than hand-rolled here a
// second time. `onBeforeUnmount` is wired explicitly (not `usePreviewEngine()`) only because
// this file already imports `ref`/`watch` from 'vue' directly and there is no lifecycle
// wrapper savings otherwise; the abort-on-unmount guarantee is identical either way.
const previewEngine = createPreviewEngine<ScanPreview>(
    {
        onResult: (result) => {
            preview.value = result;
            previewError.value = null;
        },
        onError: (message) => {
            previewError.value = message;
        },
        onLoadingChange: (value) => {
            previewLoading.value = value;
        },
    },
    { fallbackMessage: 'Die Vorschau ist fehlgeschlagen.' },
);
onBeforeUnmount(previewEngine.cancel);

/**
 * Every change to either field re-runs the preview, debounced ~600ms with the stale request
 * aborted — see `previewEngine.ts`. THROTTLED SERVER-SIDE TOO (`throttle:10,1` on
 * `admin.groups.scan-preview`): the debounce only stops the BROWSER from racing itself,
 * aborting a client-side fetch does not stop the PHP request already running each finding
 * and OciTag query to completion.
 *
 * `null` severity previews nothing — the server has nothing to compare against, and
 * `ScanBlockGuard::preview()` itself would answer the same empty shape, so this only saves
 * the round trip. `cancel()`, not a plain `return`, so a request already in flight when the
 * severity is cleared does not leave `previewLoading` stuck true — see `previewEngine.ts`'s
 * own docblock on why `cancel()` resets it unconditionally where a merely-superseded
 * request's own `finally` deliberately does not.
 */
function refreshPreview(): void {
    if (form.scan_block_severity === null) {
        previewEngine.cancel();
        preview.value = null;
        previewError.value = null;
        return;
    }

    const severity = form.scan_block_severity;
    const graceDays = form.scan_block_grace_days;

    previewEngine.schedule((signal) =>
        postPreviewJson(
            route('admin.groups.scan-preview', props.groupId),
            scanBlockingPayload(severity, graceDays),
            signal,
            'Die Vorschau ist fehlgeschlagen.',
        ).then(parseScanPreview),
    );
}

watch(() => [form.scan_block_severity, form.scan_block_grace_days], refreshPreview, { immediate: true });

function save(): void {
    form.transform((data) => scanBlockingPayload(data.scan_block_severity, data.scan_block_grace_days)).put(
        route('admin.groups.scan-blocking', props.groupId),
        { preserveScroll: true },
    );
}
</script>

<template>
    <section class="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
        <header>
            <h3 class="font-medium">Auslieferung bei Schwachstellen blockieren</h3>
            <p class="text-sm text-muted-foreground">
                Ab welchem Schweregrad diese Registry ein Image nicht mehr ausliefert — und wie lange ein neuer Fund
                vorher nur gemeldet wird.
            </p>
        </header>

        <p v-if="!blocking.enabled" class="rounded border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950">
            Für diese Instanz ist keine Schwachstellenprüfung eingerichtet. Es werden keine neuen Scans ausgeführt —
            bereits gefundene Schwachstellen blockieren die Auslieferung aber weiterhin.
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1 text-sm">
                    <span>Ab Schweregrad</span>
                    <select
                        v-model="form.scan_block_severity"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-ring focus:ring-1 focus:ring-ring focus:outline-hidden"
                    >
                        <option :value="null">Nicht blockieren (nur melden)</option>
                        <option v-for="option in SEVERITY_OPTIONS" :key="option.value" :value="option.value">
                            {{ option.label }} und darüber
                        </option>
                    </select>
                    <InputError :message="form.errors.scan_block_severity" />
                </label>

                <label class="space-y-1 text-sm">
                    <span>Schonfrist in Tagen</span>
                    <Input v-model="form.scan_block_grace_days" type="number" min="0" max="365" />
                    <span class="block text-xs text-muted-foreground">
                        Ein neuer Fund wird so lange nur angezeigt, bevor er die Auslieferung stoppt.
                    </span>
                    <InputError :message="form.errors.scan_block_grace_days" />
                </label>
            </div>

            <div v-if="form.scan_block_severity !== null" class="space-y-2 rounded-md bg-muted/40 p-3 text-sm">
                <p v-if="previewLoading">Vorschau wird berechnet …</p>
                <p v-else-if="previewError" class="text-destructive">{{ previewError }}</p>
                <template v-else-if="preview">
                    <p>
                        <strong>{{ preview.blocking_now }}</strong> Image(s) würden ab dem Speichern sofort nicht mehr
                        ausgeliefert, <strong>{{ preview.blocking_later }}</strong> später.
                    </p>
                    <ul class="space-y-1">
                        <li v-for="artifact in preview.artifacts" :key="artifact.digest" class="flex flex-wrap items-center gap-2">
                            <span class="rounded px-1.5 py-0.5 text-xs" :class="severityClass(artifact.severity)">
                                {{ artifact.severity_label }}
                            </span>
                            <span class="font-mono">{{ artifact.package }}</span>
                            <span class="text-muted-foreground">{{ artifact.tags.join(', ') || artifact.digest.slice(7, 19) }}</span>
                            <span>{{ artifact.vulnerability_id }}</span>
                            <span class="text-muted-foreground">
                                {{ artifact.blocked ? 'blockiert sofort' : `blockiert ab ${artifact.blocks_at}` }}
                            </span>
                        </li>
                    </ul>
                    <p v-if="preview.artifacts.length === 0" class="text-muted-foreground">
                        Mit dieser Einstellung würde derzeit kein Image blockiert.
                    </p>
                </template>
            </div>

            <div>
                <Button type="submit" :disabled="form.processing">Blockierung speichern</Button>
            </div>
        </form>
    </section>
</template>
