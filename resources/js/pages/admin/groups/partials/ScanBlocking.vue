<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { createPreviewEngine, type PreviewRequest } from '@/composables/previewEngine';
import { postPreviewJson } from '@/composables/previewTransport';
import { SEVERITY_OPTIONS, severityClass, type Severity } from '@/lib/severity';
import { useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { hiddenArtifacts, parseScanPreview, scanBlockingPayload, type ScanPreview } from '../scanBlocking';

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
// second time. `onBeforeUnmount(previewEngine.cancel)` below is this component's own
// lifecycle wiring, the same one `useRetentionPreview()` does for its callers.
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
 * Builds one preview request, closing over the currently-committed form values. Shared by
 * the debounced path (`refreshPreview()`, below) and the immediate first pass (see the
 * `runNow` call at the bottom of this block) so the request itself is stated once.
 */
function previewRequest(): PreviewRequest<ScanPreview> {
    const severity = form.scan_block_severity as Severity;
    const graceDays = form.scan_block_grace_days;

    return (signal) =>
        postPreviewJson(
            route('admin.groups.scan-preview', props.groupId),
            scanBlockingPayload(severity, graceDays),
            signal,
            'Die Vorschau ist fehlgeschlagen.',
        ).then(parseScanPreview);
}

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

    previewEngine.schedule(previewRequest());
}

watch(() => [form.scan_block_severity, form.scan_block_grace_days], refreshPreview);

// The mount-time pass skips the debounce (same idiom as retention/Form.vue's own
// `runPreviewNow`, used there "right after picking a repository"): a registry that already
// has a configured threshold should show its preview immediately, not an empty box for
// ~600ms while `previewLoading` and `preview` both sit at their initial falsy/null values.
if (form.scan_block_severity !== null) {
    void previewEngine.runNow(previewRequest());
}

/**
 * The instance-wide-disabled hint's text, which has to be honest about TWO independent
 * facts and must not conflate them:
 *
 *  - `blocking.enabled` says whether anything on this instance still evaluates the rule at
 *    all (see GroupController::show()'s docblock on the field of the same name).
 *  - `blocking.severity` — the STORED setting, not whatever the operator is currently
 *    drafting in the form — says whether THIS registry already has a threshold that
 *    `ScanBlockGuard::blockingFinding()` is enforcing (or would be, were scanning on).
 *
 * A registry with no stored threshold blocks nothing regardless of the instance-wide switch,
 * so telling its operator that "previously found vulnerabilities keep blocking" would be a
 * claim about vulnerabilities that cannot exist here — this control cannot express one
 * without a severity, and `ScanBlockGuard::blockingFinding()` returns immediately for a null
 * threshold. Read against the STORED value rather than the form's, because a threshold the
 * operator has only drafted, not saved, is not yet doing anything for this sentence to be
 * honest about either.
 */
const scannerDisabledHint = computed<string | null>(() => {
    if (props.blocking.enabled) {
        return null;
    }

    return props.blocking.severity !== null
        ? 'Für diese Instanz ist keine Schwachstellenprüfung eingerichtet. Es werden keine neuen Scans ausgeführt — bereits gefundene Schwachstellen blockieren die Auslieferung aber weiterhin.'
        : 'Für diese Instanz ist keine Schwachstellenprüfung eingerichtet. Es werden keine neuen Scans ausgeführt.';
});

/**
 * The tail of a capped list, as a sentence rather than as silence. `ScanBlockGuard::preview()`
 * names at most fifty artifacts however many qualify; without this the operator would read a
 * list of fifty as the complete answer while `blocking_now` said otherwise.
 */
const hiddenCount = computed<number>(() => (preview.value === null ? 0 : hiddenArtifacts(preview.value)));

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

        <p v-if="scannerDisabledHint" class="rounded border border-amber-300 bg-amber-50 p-3 text-sm dark:bg-amber-950">
            {{ scannerDisabledHint }}
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
                    <p v-if="hiddenCount > 0" class="text-muted-foreground">… und {{ hiddenCount }} weitere.</p>
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
