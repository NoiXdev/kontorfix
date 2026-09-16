<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postPreviewJson } from '@/composables/previewTransport';
import { SEVERITY_OPTIONS, severityClass, severityLabel, type Severity } from '@/lib/severity';
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface PreviewArtifact {
    package: string | null;
    digest: string;
    tags: string[];
    vulnerability_id: string;
    severity: Severity;
    severity_label: string;
    blocked: boolean;
    blocks_at: string;
}

interface Preview {
    blocking_now: number;
    blocking_later: number;
    artifacts: PreviewArtifact[];
}

const props = defineProps<{
    groupId: string;
    blocking: { enabled: boolean; severity: Severity | null; grace_days: number; default_grace_days: number };
}>();

// `scan_block_grace_days` is typed `number | string`, the same accommodation
// RetentionRulesEditor.vue's `count`/`days` fields make: the Input component's v-model
// always emits a string (see Input.vue's own docblock on why), so a plain `number` type
// here would make every keystroke a type error. The server's `integer` rule accepts a
// numeric string just as well, so no conversion is needed before either request below.
const form = useForm<{ scan_block_severity: Severity | null; scan_block_grace_days: number | string }>({
    scan_block_severity: props.blocking.severity,
    scan_block_grace_days: props.blocking.grace_days,
});

const preview = ref<Preview | null>(null);
const previewError = ref<string | null>(null);
const loading = ref(false);
let inFlight: AbortController | undefined;

/**
 * Deliberately NOT debounced per keystroke: both inputs commit discrete values (a select,
 * and a number field on `change`), so one request per committed value is both cheaper and
 * more honest — the operator sees the preview for the value they actually chose.
 */
async function refresh(): Promise<void> {
    inFlight?.abort();

    if (form.scan_block_severity === null) {
        preview.value = null;
        previewError.value = null;
        return;
    }

    const own = new AbortController();
    inFlight = own;
    loading.value = true;

    try {
        const result = await postPreviewJson(
            route('admin.groups.scan-preview', props.groupId),
            { scan_block_severity: form.scan_block_severity, scan_block_grace_days: form.scan_block_grace_days },
            own.signal,
        );

        if (own.signal.aborted) return;

        preview.value = result as unknown as Preview;
        previewError.value = null;
    } catch (err) {
        if (own.signal.aborted) return;
        previewError.value = err instanceof Error ? err.message : 'Die Vorschau ist fehlgeschlagen.';
    } finally {
        if (!own.signal.aborted) loading.value = false;
    }
}

watch(() => [form.scan_block_severity, form.scan_block_grace_days], refresh, { immediate: true });

function submit(): void {
    form.put(route('admin.groups.scan-blocking', props.groupId), { preserveScroll: true });
}
</script>

<template>
    <section class="space-y-4 rounded-lg border p-4">
        <header>
            <h3 class="font-medium">Auslieferung bei Schwachstellen blockieren</h3>
            <p class="text-sm text-muted-foreground">
                Ab welchem Schweregrad diese Registry ein Image nicht mehr ausliefert — und wie lange ein neuer Fund
                vorher nur gemeldet wird.
            </p>
        </header>

        <p v-if="!blocking.enabled" class="rounded border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950">
            Für diese Instanz ist keine Schwachstellenprüfung eingerichtet. Solange das so ist, wird hier nichts
            blockiert, unabhängig von der Einstellung.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm">
                <span>Ab Schweregrad</span>
                <select v-model="form.scan_block_severity" class="w-full rounded-md border px-3 py-2">
                    <option :value="null">Nicht blockieren (nur melden)</option>
                    <option v-for="option in SEVERITY_OPTIONS" :key="option.value" :value="option.value">
                        {{ option.label }} und darüber
                    </option>
                </select>
            </label>

            <label class="space-y-1 text-sm">
                <span>Schonfrist in Tagen</span>
                <Input v-model="form.scan_block_grace_days" type="number" min="0" max="365" />
                <span class="text-xs text-muted-foreground">
                    Ein neuer Fund wird so lange nur angezeigt, bevor er die Auslieferung stoppt.
                </span>
            </label>
        </div>

        <div v-if="form.scan_block_severity !== null" class="space-y-2 rounded-md bg-muted/40 p-3 text-sm">
            <p v-if="loading">Vorschau wird berechnet …</p>
            <p v-else-if="previewError" class="text-destructive">{{ previewError }}</p>
            <template v-else-if="preview">
                <p>
                    <strong>{{ preview.blocking_now }}</strong> Image(s) würden ab dem Speichern sofort nicht mehr
                    ausgeliefert, <strong>{{ preview.blocking_later }}</strong> später.
                </p>
                <ul class="space-y-1">
                    <li v-for="artifact in preview.artifacts" :key="artifact.digest" class="flex flex-wrap items-center gap-2">
                        <span class="rounded px-1.5 py-0.5 text-xs" :class="severityClass(artifact.severity)">
                            {{ severityLabel(artifact.severity) }}
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

        <Button :disabled="form.processing" @click="submit">Blockierung speichern</Button>
    </section>
</template>
