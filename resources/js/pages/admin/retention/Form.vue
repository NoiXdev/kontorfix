<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FlashToast from '@/components/kontorfix/FlashToast.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { postRetentionPreview, useRetentionPreview, type RetentionPreviewRequest } from '@/composables/useRetentionPreview';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { FlaskConical, Plus, Shield, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { DRY_RUN_EXPLANATION, KEEP_RULES_OR, PATTERN_HELP, SHIELD_EXPLANATION } from './policies';

interface RuleInput {
    type: string;
    count?: number | string;
    days?: number | string;
    pattern?: string;
}

interface RuleTypeOption {
    value: string;
    label: string;
    shield: boolean;
    untagged: boolean;
}

const props = defineProps<{
    policy: { id: string; name: string; rules: RuleInput[]; is_global: boolean } | null;
    // From RetentionRuleType::options() — which types are shields is the server's fact,
    // never restated here: the split below reads the flag.
    ruleTypes: RuleTypeOption[];
    dockerPackages: { id: string; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Retention', href: '/admin/retention-policies' },
    { title: props.policy ? 'Bearbeiten' : 'Neu', href: '#' },
];

const shieldTypes = new Set(props.ruleTypes.filter((t) => t.shield).map((t) => t.value));
const untaggedTypes = new Set(props.ruleTypes.filter((t) => t.untagged).map((t) => t.value));

// Three local groups, one submitted array: the editor renders keep-rules, shields and the
// untagged window as the mechanically different things they are, and merges them on save.
const keepRules = ref<RuleInput[]>((props.policy?.rules ?? []).filter((r) => !shieldTypes.has(r.type) && !untaggedTypes.has(r.type)));
const shields = ref<RuleInput[]>((props.policy?.rules ?? []).filter((r) => shieldTypes.has(r.type)));
// At most one per set (the server refuses two), so this is a value, not a list.
const untaggedDays = ref<number | string>((props.policy?.rules ?? []).find((r) => untaggedTypes.has(r.type))?.days ?? '');

const form = useForm({
    name: props.policy?.name ?? '',
    rules: [] as RuleInput[],
    is_global: props.policy?.is_global ?? false,
});

const keepTypeOptions = props.ruleTypes.filter((t) => !t.shield && !t.untagged).map((t) => ({ value: t.value, label: t.label }));

function addKeepRule() {
    keepRules.value.push({ type: 'keep_last', count: 10 });
}

function addShield() {
    shields.value.push({ type: 'never_delete', pattern: '' });
}

/** Only the fields the chosen type actually carries — stray keys are not stored. */
function normalise(rule: RuleInput): RuleInput {
    if (rule.type === 'keep_last') {
        return { type: rule.type, count: Number(rule.count) };
    }
    if (rule.type === 'keep_newer_than_days' || rule.type === 'keep_untagged') {
        return { type: rule.type, days: Number(rule.days) };
    }

    return { type: rule.type, pattern: rule.pattern ?? '' };
}

/** The merged rule set as the server stores it — save and preview submit the same array. */
function mergedRules(): RuleInput[] {
    return [
        ...keepRules.value.map(normalise),
        ...shields.value.map(normalise),
        ...(untaggedDays.value !== '' ? [normalise({ type: 'keep_untagged', days: untaggedDays.value })] : []),
    ];
}

// A keep-rule OR the untagged window: both do something real; shields alone do not — the
// same reading the server's validation enforces.
const canSave = computed(() => keepRules.value.length > 0 || untaggedDays.value !== '');

function save() {
    form.rules = mergedRules();

    if (props.policy) {
        form.put(route('admin.retention-policies.update', props.policy.id), { preserveScroll: true });
    } else {
        form.post(route('admin.retention-policies.store'), { preserveScroll: true });
    }
}

// --- The preview panel: the UNSAVED rules above, live. ---
//
// `package_id` is optional server-side (see RetentionPolicyController::preview()): the
// summary line — RetentionRule::describe(), read from the server rather than
// re-implemented here — is worth showing the moment the rules validate, before a
// repository has even been picked. Once one IS picked, the same call also answers the
// tag-by-tag table.

const previewPackageId = ref<string>('');
const {
    summary: previewSummary,
    tags: previewTags,
    error: previewError,
    loading: previewLoading,
    schedule: schedulePreview,
    runNow: runPreviewNow,
    cancel: cancelPreview,
} = useRetentionPreview();

const packageOptions = computed(() => props.dockerPackages.map((p) => ({ value: p.id, label: p.name })));

function previewRequest(): RetentionPreviewRequest {
    const rules = mergedRules();
    const packageId = previewPackageId.value || undefined;

    return (signal) => postRetentionPreview(route('admin.retention-policies.preview'), { package_id: packageId, rules }, signal);
}

// Every rule edit and every repository pick re-runs the preview, debounced ~600ms with the
// stale request aborted — see useRetentionPreview.ts. An empty rule set previews nothing
// (the server would only answer 422 "Mindestens eine Behalte-Regel…", not a useful state
// to show while the operator is still building the first rule).
watch(
    () => [mergedRules(), previewPackageId.value] as const,
    () => {
        if (mergedRules().length === 0) {
            cancelPreview();
            previewSummary.value = [];
            previewTags.value = null;
            previewError.value = null;
            return;
        }

        schedulePreview(previewRequest());
    },
    { immediate: true },
);

/** The manual fallback: skips the debounce wait, e.g. right after picking a repository. */
function runPreview() {
    if (mergedRules().length === 0) {
        return;
    }

    void runPreviewNow(previewRequest());
}
</script>

<template>
    <Head :title="props.policy ? `Richtlinie: ${props.policy.name}` : 'Neue Richtlinie'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <FlashToast />

            <div>
                <h1 class="text-xl font-semibold">{{ props.policy ? 'Richtlinie bearbeiten' : 'Neue Richtlinie' }}</h1>
                <p class="text-sm text-muted-foreground">
                    Eine Richtlinie entscheidet, welche Tags erhalten bleiben. Alles, was keine Regel behält, wird beim nächsten Lauf entfernt.
                </p>
            </div>

            <form class="max-w-3xl space-y-6" @submit.prevent="save">
                <div class="grid max-w-md gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" type="text" placeholder="z. B. Standard, Nur Releases" />
                    <InputError :message="form.errors.name" />
                </div>

                <label class="flex max-w-md items-start gap-2 text-sm">
                    <Switch v-model="form.is_global" class="mt-1" />
                    <span>
                        Für alle Organisationen veröffentlichen
                        <span class="block text-xs text-muted-foreground">
                            Veröffentlichte Richtlinien kann jede Organisation ihren eigenen Repositories zuweisen — nur lesend, bearbeiten kann sie
                            weiterhin ausschließlich der Betreiber.
                        </span>
                    </span>
                </label>
                <InputError :message="form.errors.is_global" />

                <!-- Keep-rules: the OR is stated at the point where rules are added. -->
                <div class="space-y-3 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="font-medium">Behalte-Regeln</h2>
                            <p class="mt-1 text-sm text-muted-foreground">{{ KEEP_RULES_OR }}</p>
                        </div>
                        <Button type="button" variant="outline" size="sm" @click="addKeepRule"><Plus class="mr-1 size-4" /> Regel</Button>
                    </div>

                    <div v-for="(rule, index) in keepRules" :key="`keep-${index}`" class="flex items-end gap-3">
                        <div class="grid w-56 gap-1">
                            <Label :for="`keep-type-${index}`" class="text-xs">Regel</Label>
                            <SearchableSelect :id="`keep-type-${index}`" v-model="rule.type" :options="keepTypeOptions" />
                        </div>
                        <div v-if="rule.type === 'keep_last'" class="grid w-32 gap-1">
                            <Label :for="`keep-count-${index}`" class="text-xs">Anzahl</Label>
                            <Input :id="`keep-count-${index}`" v-model="rule.count" type="number" min="1" />
                        </div>
                        <div v-else-if="rule.type === 'keep_newer_than_days'" class="grid w-32 gap-1">
                            <Label :for="`keep-days-${index}`" class="text-xs">Tage</Label>
                            <Input :id="`keep-days-${index}`" v-model="rule.days" type="number" min="1" />
                        </div>
                        <div v-else class="grid flex-1 gap-1">
                            <Label :for="`keep-pattern-${index}`" class="text-xs">Muster</Label>
                            <Input :id="`keep-pattern-${index}`" v-model="rule.pattern" type="text" :placeholder="PATTERN_HELP" />
                        </div>
                        <Button type="button" variant="ghost" size="icon" aria-label="Regel entfernen" @click="keepRules.splice(index, 1)">
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>

                    <p v-if="keepRules.length === 0" class="text-sm text-muted-foreground">
                        Noch keine Behalte-Regel. Ohne mindestens eine entfernt diese Richtlinie nichts — und lässt sich deshalb nicht speichern.
                    </p>
                </div>

                <!-- The shield: mechanically apart from the keep-rules, so visually apart (plate 4). -->
                <div class="space-y-3 rounded-xl border border-copper/40 bg-copper/5 p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="flex items-center gap-2 font-medium"><Shield class="size-4" /> Nie löschen</h2>
                            <p class="mt-1 text-sm text-muted-foreground">{{ SHIELD_EXPLANATION }}</p>
                        </div>
                        <Button type="button" variant="outline" size="sm" @click="addShield"><Plus class="mr-1 size-4" /> Schutz</Button>
                    </div>

                    <div v-for="(shield, index) in shields" :key="`shield-${index}`" class="flex items-end gap-3">
                        <div class="grid flex-1 gap-1">
                            <Label :for="`shield-pattern-${index}`" class="text-xs">Muster</Label>
                            <Input :id="`shield-pattern-${index}`" v-model="shield.pattern" type="text" :placeholder="PATTERN_HELP" />
                        </div>
                        <Button type="button" variant="ghost" size="icon" aria-label="Schutz entfernen" @click="shields.splice(index, 1)">
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>
                </div>

                <!-- Untagged images: decides MANIFESTS, not tags — a per-digest-pushed image
                     with no tag survives this many days instead of only the grace period. -->
                <div class="space-y-3 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    <div>
                        <h2 class="font-medium">Ungetaggte Images</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Nur per Digest gepushte Images ohne Tag werden normalerweise nach der Schonfrist entfernt. Mit dieser Regel bleiben sie
                            die angegebene Zahl von Tagen erhalten. Sie betrifft keine Tags und taucht deshalb nie in der Begründungsspalte auf.
                        </p>
                    </div>
                    <div class="flex items-end gap-3">
                        <div class="grid w-32 gap-1">
                            <Label for="untagged-days" class="text-xs">Tage</Label>
                            <Input id="untagged-days" v-model="untaggedDays" type="number" min="1" placeholder="—" />
                        </div>
                        <Button
                            v-if="untaggedDays !== ''"
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Regel entfernen"
                            @click="untaggedDays = ''"
                        >
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>
                </div>

                <InputError :message="form.errors.rules" />

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing || !canSave">Speichern</Button>
                    <p v-if="!canSave" class="text-sm text-muted-foreground">Mindestens eine Behalte-Regel oder die Ungetaggt-Regel ist nötig.</p>
                </div>
            </form>

            <!-- The preview panel: evaluates the rules as they stand in the form, saved or not. -->
            <div class="max-w-3xl space-y-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <div>
                    <h2 class="flex items-center gap-2 font-medium"><FlaskConical class="size-4" /> Probelauf</h2>
                    <p class="mt-1 text-sm text-muted-foreground">{{ DRY_RUN_EXPLANATION }}</p>
                </div>

                <div class="flex items-end gap-3">
                    <div class="grid w-72 gap-1">
                        <Label for="preview-package" class="text-xs">Repository</Label>
                        <SearchableSelect id="preview-package" v-model="previewPackageId" placeholder="Bitte wählen" :options="packageOptions" />
                    </div>
                    <Button type="button" variant="outline" :disabled="previewLoading || mergedRules().length === 0" @click="runPreview">
                        {{ previewLoading ? 'Läuft …' : 'Probelauf starten' }}
                    </Button>
                </div>

                <!-- Live, from RetentionRule::describe() — never a client-side restatement of the grammar. -->
                <ul v-if="previewSummary.length > 0" class="list-inside list-disc text-sm text-muted-foreground">
                    <li v-for="line in previewSummary" :key="line">{{ line }}</li>
                </ul>

                <p v-if="previewError" class="text-sm text-destructive">{{ previewError }}</p>

                <p v-if="!previewPackageId && previewSummary.length > 0" class="text-sm text-muted-foreground">
                    Repository wählen, um zu sehen, welche Tags entfernt würden.
                </p>

                <table v-if="previewTags" class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 text-left dark:border-sidebar-border">
                            <th class="py-2 pr-4 font-medium">Tag</th>
                            <th class="py-2 pr-4 font-medium">Gepusht</th>
                            <th class="py-2 pr-4 font-medium">Entscheidung</th>
                            <th class="py-2 font-medium">Begründung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="tag in previewTags"
                            :key="tag.name"
                            class="border-b border-sidebar-border/40 last:border-b-0 dark:border-sidebar-border/40"
                        >
                            <td class="py-2 pr-4 font-mono">{{ tag.name }}</td>
                            <td class="py-2 pr-4 text-muted-foreground">{{ tag.pushed_at ?? '—' }}</td>
                            <td class="py-2 pr-4">
                                <span v-if="tag.keep" class="text-emerald-600 dark:text-emerald-400">bleibt</span>
                                <span v-else class="text-destructive">wird entfernt</span>
                            </td>
                            <td class="py-2 text-muted-foreground">{{ tag.reason ?? '—' }}</td>
                        </tr>
                        <tr v-if="previewTags.length === 0">
                            <td colspan="4" class="py-2 text-muted-foreground">Dieses Repository hat keine Tags.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
