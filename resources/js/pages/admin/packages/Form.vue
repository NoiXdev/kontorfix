<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { computed, inject, ref, watch } from 'vue';
import {
    canChooseSourceMode,
    isGitMode as computeIsGitMode,
    describeManifestOutcome,
    describeProbeFailure,
    modesFor,
    packageFormKey,
    type GitCredentialOption,
    type GroupOption,
    type PackageFormData,
    type ProbeResult,
    type SourceModeMap,
} from './packageForm';

const props = defineProps<{
    groups: GroupOption[];
    registryTypes: string[];
    gitCredentials: GitCredentialOption[];
    sourceModes: SourceModeMap;
}>();

// Provided by Create.vue (see packageForm.ts) rather than passed as a prop: this form object
// is meant to be written into (`v-model="form.xxx"`), and an injected value is not subject to
// Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(packageFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via packageFormKey — see Create.vue.');
}
const form = injectedForm;

// The probe result also has to be readable from Create.vue: the submit button is disabled,
// and the probe-first gate re-checked, using the same value — so it travels as a v-model
// rather than living only in this component's local state (same technique as users'
// `accessMode`).
const probeResult = defineModel<ProbeResult | null>('probeResult', { default: null });

// What the success banner says about the manifest beyond „Repository erreichbar". Null when
// the probe discovered the name and there is nothing left to tell the operator.
const manifestNote = computed(() => (probeResult.value ? describeManifestOutcome(probeResult.value) : null));

// Type metadata (labels) comes from the shared single source.
const { options: typeOptionsFor } = useRegistryTypes();
// Only instance-enabled types are offered.
const typeOptions = computed(() => typeOptionsFor(props.registryTypes));
// `typeOptionsFor()` is generically `{ value: string; label: string }[]` (it's shared across
// call sites with different literal-union needs); `form.type` is the narrower
// `'composer' | 'npm' | 'python'`. This asserts the already-true invariant — the options
// always come from the registry-type enum — so `SearchableSelect`'s `v-model` lines up.
const packageTypeOptions = computed(() => typeOptions.value as { value: 'composer' | 'npm' | 'python'; label: string }[]);

const modesForType = computed(() => modesFor(props.sourceModes, form.type));
// Same reasoning as `packageTypeOptions` above.
const sourceModeOptions = computed(() => modesForType.value as { value: 'publish' | 'git'; label: string }[]);
const canChooseSource = computed(() => canChooseSourceMode(props.sourceModes, form.type));
const isGitMode = computed(() => computeIsGitMode(props.sourceModes, form.type, form.source_mode));

function resetProbe() {
    probeResult.value = null;
}

// Switching type resets the source mode to that type's first (default) allowed mode —
// composer → git, npm → publish — instead of a hardcoded 'publish', so a type with no
// publish option never lands on an invalid value. Also resets the probe.
function onTypeChange() {
    form.source_mode = (props.sourceModes[form.type]?.[0]?.value ?? 'publish') as 'publish' | 'git';
    onSourceModeChange();
}

// Leaving git-mirror mode discards the repository config so a publish package isn't created
// with stale git fields.
function onSourceModeChange() {
    if (!isGitMode.value) {
        form.repository_url = '';
        form.is_private = false;
        form.repository_token = '';
        form.git_credential_id = '';
    }
    resetProbe();
}

// Clearing the private toggle discards any entered token/credential. `Switch` has no
// `change` event of its own — it only updates `form.is_private` — so this runs off a
// watcher on that value instead of a `<input type="checkbox" @change="...">`.
function onPrivateToggle() {
    if (!form.is_private) {
        form.repository_token = '';
        form.git_credential_id = '';
        resetProbe();
    }
}

watch(() => form.is_private, onPrivateToggle);

const credentialOptions = computed(() => [
    { value: '', label: 'Kein Token / öffentlich' },
    ...props.gitCredentials.map((c) => ({ value: c.id, label: `${c.name} (${c.provider})` })),
]);

// Probe-first for git types: check the repository (and read its manifest) before creating.
const probing = ref(false);

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

// Renders a failed probe: the real reason in the probe banner, plus — for a 422 — the
// message under the field it belongs to, where the operator is already looking. Pass null
// when fetch threw and there is no response.
async function showProbeFailure(response: Response | null) {
    const failure = await describeProbeFailure(response);
    probeResult.value = { ok: false, error: failure.message, versions: [] };
    // Only fields this form actually has: the errors bag is whatever the server sent, and
    // `setError` on a key that is not part of the form data would put a message nowhere any
    // InputError renders. The banner above carries the message either way.
    const fields = Object.keys(form.data());
    for (const [field, message] of Object.entries(failure.errors)) {
        if (fields.includes(field)) {
            form.setError(field as keyof PackageFormData, message);
        }
    }
}

async function probeRepository() {
    if (probing.value || form.repository_url.trim() === '') {
        return;
    }
    probing.value = true;
    probeResult.value = null;
    form.clearErrors();

    try {
        const response = await fetch('/admin/packages/probe', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                type: form.type,
                repository_url: form.repository_url,
                repository_token: form.repository_token,
                git_credential_id: form.git_credential_id || null,
            }),
        });

        if (!response.ok) {
            // The reason lives in the response body — reporting every failure as "bitte
            // erneut versuchen" hid it, and for a git-sourced type „Anlegen" stays disabled
            // until the probe succeeds, so an unreadable rejection is an unusable mask.
            // Same helper as PackagePicker.vue, which calls this same endpoint.
            await showProbeFailure(response);
            return;
        }

        const result: ProbeResult = await response.json();
        probeResult.value = result;
        if (result.ok && result.name) {
            form.name = result.name;
        }
    } catch {
        // No response at all (offline, DNS, aborted): the one case a retry can fix.
        await showProbeFailure(null);
    } finally {
        probing.value = false;
    }
}

function toggleGroup(groupId: string, checked: boolean) {
    if (checked) {
        form.group_ids.push(groupId);
    } else {
        form.group_ids = form.group_ids.filter((id) => id !== groupId);
    }
}
</script>

<template>
    <div class="grid gap-2">
        <Label for="type">Typ</Label>
        <SearchableSelect id="type" v-model="form.type" :options="packageTypeOptions" @update:model-value="onTypeChange" />
        <InputError :message="form.errors.type" />
    </div>

    <!-- Rendered only for a type with more than one allowed mode, which today means Python.
         Composer is always git, npm always publish — offering either a choice would offer
         one the server refuses. -->
    <div v-if="canChooseSource" class="grid gap-2">
        <Label for="source_mode">Quelle</Label>
        <SearchableSelect id="source_mode" v-model="form.source_mode" :options="sourceModeOptions" @update:model-value="onSourceModeChange" />
        <p class="text-xs text-muted-foreground">
            <strong>Publish (Push):</strong> Versionen entstehen beim Upload. <strong>Git-Mirror:</strong> Versionen werden aus den Tags eines
            Repositories gespiegelt (keine Build-/Prepare-Skripte).
        </p>
    </div>

    <div class="grid gap-2">
        <Label for="name">Name</Label>
        <Input
            id="name"
            v-model="form.name"
            :placeholder="{ composer: 'vendor/paket', npm: '@scope/name', python: 'projektname' }[form.type]"
            autocomplete="off"
        />
        <p v-if="isGitMode" class="text-xs text-muted-foreground">Wird beim „Prüfen" automatisch aus dem Repository übernommen.</p>
        <InputError :message="form.errors.name" />
    </div>

    <!-- Publish-based: no git repo — the name is the reserved identifier; versions/metadata
         arrive with each upload. -->
    <p v-if="!isGitMode" class="text-xs text-muted-foreground">
        Publish-basiert: Der Name ist der <strong>reservierte Paketname</strong>. Versionen und Metadaten entstehen beim Upload (<code>{{
            form.type === 'npm' ? 'npm publish' : 'twine upload'
        }}</code
        >) — kein Repository nötig.
    </p>
    <template v-else>
        <div class="grid gap-2">
            <Label for="repository_url">Repository-URL</Label>
            <div class="flex gap-2">
                <Input
                    id="repository_url"
                    v-model="form.repository_url"
                    placeholder="https://git.example.com/vendor/paket.git"
                    autocomplete="off"
                    @update:model-value="resetProbe"
                    @keyup.enter.prevent="probeRepository"
                />
                <Button type="button" variant="outline" :disabled="probing || form.repository_url.trim() === ''" @click="probeRepository">
                    {{ probing ? 'Prüfe…' : 'Prüfen' }}
                </Button>
            </div>
            <InputError :message="form.errors.repository_url" />
        </div>

        <label class="flex items-center gap-2 text-sm">
            <Switch v-model="form.is_private" />
            Privates Repository (Token nötig)
        </label>

        <template v-if="form.is_private">
            <div v-if="props.gitCredentials.length" class="grid gap-2">
                <Label for="git_credential_id">Gespeicherter Token</Label>
                <SearchableSelect id="git_credential_id" v-model="form.git_credential_id" :options="credentialOptions" @update:model-value="resetProbe" />
                <p class="text-xs text-muted-foreground">
                    Verwaltete Git-Tokens unter „Git-Tokens" (org-weit wiederverwendbar). Oder unten ein Einmal-Token einfügen.
                </p>
            </div>

            <div v-if="!form.git_credential_id" class="grid gap-2">
                <Label for="repository_token">Token einfügen</Label>
                <Input
                    id="repository_token"
                    v-model="form.repository_token"
                    type="password"
                    placeholder="z. B. GitHub PAT (ghp_…)"
                    autocomplete="off"
                    @update:model-value="resetProbe"
                />
                <p class="text-xs text-muted-foreground">Nur für HTTPS. Wird verschlüsselt gespeichert und für Prüfen/Sync verwendet.</p>
            </div>
        </template>

        <div v-if="probeResult && !probeResult.ok" class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            {{ probeResult.error ?? 'Repository konnte nicht gelesen werden.' }}
        </div>
        <div v-else-if="probeResult && probeResult.ok" class="space-y-1 rounded-md border border-verdigris/30 bg-verdigris/10 px-3 py-2 text-sm">
            <div>
                <span class="font-medium text-verdigris">Repository erreichbar.</span>
                <span v-if="probeResult.versions.length" class="text-muted-foreground"> {{ probeResult.versions.length }} Version(en) gefunden. </span>
                <span v-else class="text-muted-foreground">Keine Tags gefunden — Sync läuft nach dem Anlegen trotzdem.</span>
            </div>
            <!-- Reachable is only half the answer: the probe also reads the manifest to fill
                 the name in, and that half used to fail silently. -->
            <div v-if="manifestNote" :class="manifestNote.tone === 'warning' ? 'font-medium text-destructive' : 'text-muted-foreground'">
                {{ manifestNote.text }}
            </div>
        </div>
        <p v-else class="text-xs text-muted-foreground">Repository zuerst „Prüfen", dann anlegen.</p>
    </template>

    <div class="grid gap-2">
        <Label>Gruppen</Label>
        <div class="max-h-40 space-y-2 overflow-y-auto rounded-md border border-input p-3">
            <div v-for="group in props.groups" :key="group.id" class="flex items-center gap-2">
                <Checkbox
                    :id="`group-${group.id}`"
                    :checked="form.group_ids.includes(group.id)"
                    @update:checked="(checked) => toggleGroup(group.id, checked === true)"
                />
                <Label :for="`group-${group.id}`" class="font-normal">{{ group.name }}</Label>
            </div>
            <p v-if="props.groups.length === 0" class="text-sm text-muted-foreground">Keine Gruppen vorhanden.</p>
        </div>
        <InputError :message="form.errors.group_ids" />
    </div>
</template>
