<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import {
    availableUntilLabel,
    boundsLabel,
    boundsPreview,
    detectMajorLine,
    effectiveBounds,
    excludesAllVersions,
    groupByOrganization,
    revokeAssignmentConfirmation,
    statusLabel,
    type AssignableGroup,
    type AssignmentRow,
    type BoundsMode,
    type LicensableOrganization,
    type OrganizationLicenceRow,
} from './freigaben';
import { effectiveWindow, type Licence } from './lizenz';
import LizenzEditor from './LizenzEditor.vue';

const props = defineProps<{
    packageId: string;
    // Docker carries no version bounds concept at all (AssignmentWriter refuses any bound
    // on one) — the editor hides the bounds radios entirely for it rather than offering a
    // choice the server would only refuse. Named defensively: today no Docker package ever
    // reaches this component (PackageController::show() redirects one to its own page
    // before this tab's props are built), but the guard costs nothing to keep honest.
    packageType: 'composer' | 'npm' | 'python' | 'docker';
    canManage: boolean;
    assignments: AssignmentRow[];
    // Every version this package has — order does not matter (see highestAdmittedForPreview()) —
    // for the editor's preview line only, never for anything the server needs to agree with.
    versions: string[];
    majorLines: string[];
    assignableGroups: AssignableGroup[];
    organizationLicences: OrganizationLicenceRow[];
    licensableOrganizations: LicensableOrganization[];
}>();

const grouped = computed(() => groupByOrganization(props.assignments));

const offersBounds = computed(() => props.packageType !== 'docker');

// --- The effective-window rule: a registry row is shown THROUGH its organization's
// licence, not by its own raw bounds alone — see `lizenz.ts`'s `effectiveWindow()`. ---

// Maps the payload's `version_min`/`version_max`/`expired` onto `effectiveWindow`'s
// `min`/`max`/`expired` shape — deliberately not the same field names: the helper is
// ecosystem-agnostic (it knows nothing about "licences"), the payload is not, and renaming
// either side to make them match would blur that boundary rather than clarify it.
const licenceByOrg = computed<Record<string, Licence>>(() =>
    Object.fromEntries(
        props.organizationLicences.map((l) => [
            l.organization_id,
            { min: l.version_min, max: l.version_max, expired: l.expired },
        ]),
    ),
);

function effectiveFor(row: { organization_id: string; version_min: string | null; version_max: string | null }) {
    return effectiveWindow({ min: row.version_min, max: row.version_max }, licenceByOrg.value[row.organization_id] ?? null);
}

// --- The licence editor dialog: shared between "Organisation freigeben" (create) and
// editing/removing an existing organization licence. Embeds LizenzEditor.vue, the same
// component the customer-page entry point will use. ---

type LicenceEditorMode = 'create' | 'edit';

const licenceEditorOpen = ref(false);
const licenceEditorMode = ref<LicenceEditorMode>('create');
const licenceEditorTarget = ref<OrganizationLicenceRow | null>(null);
const licenceEditorErrors = ref<Record<string, string>>({});
const licenceEditorSaving = ref(false);

const licenceEditorOptions = computed(() => props.licensableOrganizations.map((o) => ({ value: o.id, label: o.name })));

const licenceEditorInitial = computed(() => {
    const target = licenceEditorTarget.value;

    return target === null
        ? { id: null, availableUntil: '', versionMin: '', versionMax: '' }
        : {
              id: target.organization_id,
              availableUntil: target.available_until ?? '',
              versionMin: target.version_min ?? '',
              versionMax: target.version_max ?? '',
          };
});

function openLicenceEditor(row?: OrganizationLicenceRow) {
    licenceEditorMode.value = row ? 'edit' : 'create';
    licenceEditorTarget.value = row ?? null;
    licenceEditorErrors.value = {};
    licenceEditorOpen.value = true;
}

function closeLicenceEditor() {
    licenceEditorOpen.value = false;
}

function saveLicenceEditor(payload: { id: string | null; available_until: string | null; version_min: string | null; version_max: string | null }) {
    licenceEditorSaving.value = true;
    licenceEditorErrors.value = {};

    const options = {
        preserveScroll: true,
        onSuccess: () => closeLicenceEditor(),
        onError: (errors: Record<string, string>) => {
            licenceEditorErrors.value = errors;
        },
        onFinish: () => {
            licenceEditorSaving.value = false;
        },
    };

    if (licenceEditorMode.value === 'create') {
        router.post(
            route('admin.organizations.licences.store', payload.id ?? ''),
            {
                package_id: props.packageId,
                available_until: payload.available_until,
                version_min: payload.version_min,
                version_max: payload.version_max,
            },
            options,
        );
    } else {
        const organizationId = licenceEditorTarget.value?.organization_id ?? '';
        router.put(
            route('admin.organizations.licences.update', [organizationId, props.packageId]),
            {
                available_until: payload.available_until,
                version_min: payload.version_min,
                version_max: payload.version_max,
            },
            options,
        );
    }
}

function removeLicence(row: OrganizationLicenceRow) {
    router.delete(route('admin.organizations.licences.destroy', [row.organization_id, props.packageId]), {
        preserveScroll: true,
        // Same confirm-then-cancel pattern removeAssignment() below already uses: a
        // declined dialog cancels the request outright, via onBefore.
        onBefore: () =>
            confirm(
                `Lizenz für „${row.organization_name}" wirklich entfernen?\n\n` +
                    'Anders als ein Ablaufdatum entfernt dies nur die organisationsweite Obergrenze: bestehende ' +
                    'Registry-Freigaben dieser Organisation bleiben unverändert bestehen.',
            ),
    });
}

// --- The editor dialog: shared between "Registry freigeben" (create) and editing a row. ---

type EditorMode = 'create' | 'edit';

const editorOpen = ref(false);
const editorMode = ref<EditorMode>('create');
const editorGroupId = ref('');
const editorGroupLabel = ref('');
const editorAvailableUntil = ref('');
const editorBoundsMode = ref<BoundsMode>('all');
const editorMajor = ref('');
const editorCustomMin = ref('');
const editorCustomMax = ref('');
const editorErrors = ref<Record<string, string>>({});
const editorSaving = ref(false);

const editorBounds = computed(() => effectiveBounds(editorBoundsMode.value, editorMajor.value, editorCustomMin.value, editorCustomMax.value));

const editorPreview = computed(() => boundsPreview(props.versions, editorBounds.value.min, editorBounds.value.max));

// The one thing this feature must never let an operator save without seeing — styled as a
// warning rather than the plain preview line once the bounds admit nothing at all.
const editorPreviewIsWarning = computed(() => props.versions.length > 0 && editorPreview.value.includes('schließt alle Versionen aus'));

function resetEditor() {
    editorGroupId.value = '';
    editorGroupLabel.value = '';
    editorAvailableUntil.value = '';
    editorBoundsMode.value = 'all';
    editorMajor.value = '';
    editorCustomMin.value = '';
    editorCustomMax.value = '';
    editorErrors.value = {};
}

function openCreateEditor() {
    resetEditor();
    editorMode.value = 'create';
    editorGroupId.value = props.assignableGroups[0]?.id ?? '';
    editorOpen.value = true;
}

function openEditEditor(row: AssignmentRow) {
    resetEditor();
    editorMode.value = 'edit';
    editorGroupId.value = row.group_id;
    editorGroupLabel.value = `${row.organization_name} – ${row.group_name}`;
    editorAvailableUntil.value = row.available_until ?? '';

    if (row.version_min === null && row.version_max === null) {
        editorBoundsMode.value = 'all';
    } else {
        const major = detectMajorLine(row.version_min, row.version_max);
        if (major !== null) {
            editorBoundsMode.value = 'major';
            editorMajor.value = major;
        } else {
            editorBoundsMode.value = 'custom';
            editorCustomMin.value = row.version_min ?? '';
            editorCustomMax.value = row.version_max ?? '';
        }
    }

    editorOpen.value = true;
}

function closeEditor() {
    editorOpen.value = false;
}

const groupPickerOptions = computed(() => props.assignableGroups.map((g) => ({ value: g.id, label: `${g.organization_name} – ${g.name}` })));

function saveEditor() {
    editorSaving.value = true;
    editorErrors.value = {};

    const payload = {
        available_until: editorAvailableUntil.value === '' ? null : editorAvailableUntil.value,
        version_min: editorBounds.value.min,
        version_max: editorBounds.value.max,
    };

    const options = {
        preserveScroll: true,
        onSuccess: () => closeEditor(),
        onError: (errors: Record<string, string>) => {
            editorErrors.value = errors;
        },
        onFinish: () => {
            editorSaving.value = false;
        },
    };

    if (editorMode.value === 'create') {
        router.post(route('admin.packages.assignments.store', props.packageId), { group_id: editorGroupId.value, ...payload }, options);
    } else {
        router.put(route('admin.packages.assignments.update', [props.packageId, editorGroupId.value]), payload, options);
    }
}

function removeAssignment(row: AssignmentRow) {
    router.delete(route('admin.packages.assignments.destroy', [props.packageId, row.group_id]), {
        preserveScroll: true,
        // Same pattern as the token revoke confirmations elsewhere in the console (e.g.
        // groups/Show.vue's "Token wirklich widerrufen?"): a native confirm in `onBefore`,
        // so a declined dialog cancels the request outright. Named explicitly here because
        // — per spec §4 as amended — detaching, unlike letting an assignment lapse, is the
        // one act that releases the package name back to the public index for this
        // customer, and that consequence is not obvious from a bare trash icon.
        onBefore: () => confirm(revokeAssignmentConfirmation(row.group_name)),
    });
}
</script>

<template>
    <section class="flex flex-col gap-4">
        <p v-if="!props.canManage" class="text-sm text-muted-foreground">
            Dieses Paket wird geteilt und Ihnen zur Verfügung gestellt. Wer es sonst noch erhält und mit welcher Versionsgrenze, entscheidet die
            Organisation, die es bereitstellt.
        </p>

        <template v-else>
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-medium">Freigaben</h3>
                <Button size="sm" :disabled="props.assignableGroups.length === 0" @click="openCreateEditor">Registry freigeben</Button>
            </div>
            <p v-if="props.assignableGroups.length === 0" class="text-xs text-muted-foreground">
                Keine weiteren Registries verfügbar — dieses Paket ist bereits überall zugewiesen, wo Sie es zuweisen dürfen.
            </p>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium">Organisationen</h3>
                    <Button size="sm" :disabled="props.licensableOrganizations.length === 0" @click="openLicenceEditor()">
                        Organisation freigeben
                    </Button>
                </div>
                <p
                    v-if="props.licensableOrganizations.length === 0 && props.organizationLicences.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    Dieses Paket ist nicht freigegeben. Aktivieren Sie zuerst „Für andere Organisationen freigeben".
                </p>
                <table v-else class="w-full text-sm">
                    <tbody>
                        <tr v-for="l in props.organizationLicences" :key="l.organization_id">
                            <td class="py-2">{{ l.organization_name }}</td>
                            <td class="font-mono text-xs text-muted-foreground">{{ l.version_min ?? '–' }} bis {{ l.version_max ?? '–' }}</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">
                                <span v-if="l.expired" class="text-destructive">abgelaufen</span>
                                <span v-else>{{ availableUntilLabel(l.available_until) }}</span>
                            </td>
                            <td class="text-right">
                                <Button size="sm" variant="ghost" @click="openLicenceEditor(l)">Bearbeiten</Button>
                                <Button size="sm" variant="ghost" @click="removeLicence(l)">Entfernen</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="grouped.length === 0"
                class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border"
            >
                Noch keiner Registry freigegeben.
            </div>

            <div v-for="org in grouped" :key="org.organization_id" class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="border-b border-sidebar-border/70 bg-muted/50 px-4 py-2 text-sm font-medium dark:border-sidebar-border">
                    {{ org.organization_name }}
                </div>
                <table class="w-full text-left text-sm">
                    <tbody>
                        <tr
                            v-for="row in org.rows"
                            :key="row.group_id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="py-2 pr-4 pl-8">{{ row.group_name }}</td>
                            <td v-if="offersBounds" class="px-4 py-2 font-mono text-xs text-muted-foreground">
                                <!-- The row's OWN bounds, always visible — the licence effect is
                                     stated separately below so a "narrows nothing" row still shows
                                     what it was actually configured to. -->
                                {{ boundsLabel(row.version_min, row.version_max) }}
                                <!-- The saved-typo case the spec's error table warns about: the live
                                     preview in the editor only warns while the dialog is open, so a
                                     bounds pair that admits nothing must stay visible here too, not
                                     just at save time. -->
                                <span
                                    v-if="excludesAllVersions(props.versions, row.version_min, row.version_max)"
                                    class="ml-1 inline-flex items-center rounded-md border border-destructive/30 bg-destructive/10 px-1.5 py-0.5 text-xs font-medium text-destructive"
                                >
                                    schließt alle Versionen aus
                                </span>
                                <!-- What this row actually SERVES once its organization's licence is
                                     applied — see lizenz.ts's effectiveWindow(). Distinct from the raw
                                     bounds line above: a row can be syntactically fine and still be
                                     narrowed, or emptied outright, by a licence the operator set
                                     separately in the "Organisationen" block above. -->
                                <span class="mt-0.5 block font-sans">
                                    <span>{{ effectiveFor(row).min ?? '–' }} bis {{ effectiveFor(row).max ?? '–' }}</span>
                                    <span v-if="effectiveFor(row).empty" class="block text-destructive">
                                        Durch die Lizenz dieser Organisation liefert diese Registry aktuell nichts.
                                    </span>
                                    <span v-else-if="effectiveFor(row).narrowedByLicence" class="block text-muted-foreground">
                                        Durch die Lizenz dieser Organisation eingegrenzt.
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">{{ availableUntilLabel(row.available_until) }}</td>
                            <td class="px-4 py-2">
                                <span
                                    :class="
                                        cn(
                                            'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium',
                                            row.in_force
                                                ? 'border-verdigris/30 bg-verdigris/15 text-verdigris'
                                                : 'border-destructive/30 bg-destructive/10 text-destructive',
                                        )
                                    "
                                >
                                    {{ statusLabel(row.in_force) }}
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                <!-- Hidden rather than disabled for a row outside the active console
                                     scope: the writer would refuse both actions with a 403 (it asks
                                     assertAdministersGroupInScope() per row), and an offered button
                                     that only ever 403s is worse than none — the operator's fix is to
                                     switch scope, not to retry. -->
                                <div v-if="row.can_edit" class="flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Freigabe für ${row.group_name} bearbeiten`"
                                        @click="openEditEditor(row)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Freigabe für ${row.group_name} entfernen`"
                                        @click="removeAssignment(row)"
                                    >
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </div>
                                <span v-else class="text-xs text-muted-foreground">In anderem Bereich verwaltbar</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <Dialog v-model:open="editorOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ editorMode === 'create' ? 'Registry freigeben' : `Freigabe bearbeiten: ${editorGroupLabel}` }}</DialogTitle>
                </DialogHeader>

                <div class="flex flex-col gap-4">
                    <div v-if="editorMode === 'create'" class="grid gap-2">
                        <Label for="freigabe-group">Registry</Label>
                        <SearchableSelect id="freigabe-group" v-model="editorGroupId" :options="groupPickerOptions" placeholder="Registry wählen…" />
                        <InputError :message="editorErrors.group_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="freigabe-until">Verfügbar bis (leer = unbegrenzt)</Label>
                        <Input id="freigabe-until" v-model="editorAvailableUntil" type="date" class="max-w-xs" />
                        <InputError :message="editorErrors.available_until" />
                    </div>

                    <div v-if="offersBounds" class="grid gap-2">
                        <Label>Versionen</Label>
                        <div class="flex flex-col gap-2 text-sm">
                            <label class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="freigabe-bounds-mode"
                                    value="all"
                                    :checked="editorBoundsMode === 'all'"
                                    @change="editorBoundsMode = 'all'"
                                />
                                Alle Versionen
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="freigabe-bounds-mode"
                                    value="major"
                                    :checked="editorBoundsMode === 'major'"
                                    :disabled="props.majorLines.length === 0"
                                    @change="editorBoundsMode = 'major'"
                                />
                                Nur eine Hauptversion
                                <select
                                    v-if="editorBoundsMode === 'major'"
                                    v-model="editorMajor"
                                    class="rounded-md border border-input bg-background px-2 py-1 text-sm"
                                >
                                    <option value="" disabled>Wählen…</option>
                                    <option v-for="major in props.majorLines" :key="major" :value="major">{{ major }}.x</option>
                                </select>
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="freigabe-bounds-mode"
                                    value="custom"
                                    :checked="editorBoundsMode === 'custom'"
                                    @change="editorBoundsMode = 'custom'"
                                />
                                Eigener Bereich
                            </label>
                            <div v-if="editorBoundsMode === 'custom'" class="ml-6 flex items-center gap-2">
                                <Input v-model="editorCustomMin" placeholder="ab (inkl.)" class="max-w-32 font-mono" />
                                <span class="text-muted-foreground">bis, ausschließlich</span>
                                <Input v-model="editorCustomMax" placeholder="bis (exkl.)" class="max-w-32 font-mono" />
                            </div>
                        </div>
                        <p :class="cn('text-xs', editorPreviewIsWarning ? 'font-medium text-destructive' : 'text-muted-foreground')">
                            {{ editorPreview }}
                        </p>
                        <InputError :message="editorErrors.version_min" />
                        <InputError :message="editorErrors.version_max" />
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" :disabled="editorSaving" @click="closeEditor">Abbrechen</Button>
                    <Button :disabled="editorSaving || (editorMode === 'create' && editorGroupId === '')" @click="saveEditor">
                        {{ editorSaving ? 'Speichert …' : 'Speichern' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <LizenzEditor
            :open="licenceEditorOpen"
            :mode="licenceEditorMode"
            :title="licenceEditorMode === 'create' ? 'Organisation freigeben' : `Lizenz bearbeiten: ${licenceEditorTarget?.organization_name}`"
            picker-label="Organisation"
            :options="licenceEditorOptions"
            :initial="licenceEditorInitial"
            :errors="licenceEditorErrors"
            :saving="licenceEditorSaving"
            @update:open="licenceEditorOpen = $event"
            @submit="saveLicenceEditor"
        />
    </section>
</template>
