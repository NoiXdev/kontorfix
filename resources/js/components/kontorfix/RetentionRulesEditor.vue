<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { KEEP_RULES_OR, PATTERN_HELP, SHIELD_EXPLANATION } from '@/pages/admin/retention/policies';
import { Plus, Shield, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * The three rule groups (keep / shield / untagged) as one editable unit, modelling the
 * merged rule array the server stores. Built for the package page's inline rules; the
 * policy Form.vue predates it and still carries its own markup — when that page is next
 * touched, fold it onto this component rather than editing both.
 */

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
    modelValue: RuleInput[];
    ruleTypes: RuleTypeOption[];
    /** Compact spacing for embedding in a card rather than a full page. */
    compact?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [rules: RuleInput[]] }>();

const shieldTypes = new Set(props.ruleTypes.filter((t) => t.shield).map((t) => t.value));
const untaggedTypes = new Set(props.ruleTypes.filter((t) => t.untagged).map((t) => t.value));

const keepRules = ref<RuleInput[]>(props.modelValue.filter((r) => !shieldTypes.has(r.type) && !untaggedTypes.has(r.type)));
const shields = ref<RuleInput[]>(props.modelValue.filter((r) => shieldTypes.has(r.type)));
const untaggedDays = ref<number | string>(props.modelValue.find((r) => untaggedTypes.has(r.type))?.days ?? '');

const keepTypeOptions = props.ruleTypes.filter((t) => !t.shield && !t.untagged).map((t) => ({ value: t.value, label: t.label }));

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

const merged = computed<RuleInput[]>(() => [
    ...keepRules.value.map(normalise),
    ...shields.value.map(normalise),
    ...(untaggedDays.value !== '' ? [normalise({ type: 'keep_untagged', days: untaggedDays.value })] : []),
]);

watch(merged, (rules) => emit('update:modelValue', rules), { deep: true });
</script>

<template>
    <div :class="props.compact ? 'space-y-3' : 'space-y-6'">
        <!-- Keep-rules: the OR is stated at the point where rules are added. -->
        <div class="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-medium">Behalte-Regeln</h3>
                    <p class="mt-1 text-xs text-muted-foreground">{{ KEEP_RULES_OR }}</p>
                </div>
                <Button type="button" variant="outline" size="sm" @click="keepRules.push({ type: 'keep_last', count: 10 })">
                    <Plus class="mr-1 size-4" /> Regel
                </Button>
            </div>

            <div v-for="(rule, index) in keepRules" :key="`keep-${index}`" class="flex items-end gap-3">
                <div class="grid w-56 gap-1">
                    <Label :for="`inline-keep-type-${index}`" class="text-xs">Regel</Label>
                    <SearchableSelect :id="`inline-keep-type-${index}`" v-model="rule.type" :options="keepTypeOptions" />
                </div>
                <div v-if="rule.type === 'keep_last'" class="grid w-28 gap-1">
                    <Label :for="`inline-keep-count-${index}`" class="text-xs">Anzahl</Label>
                    <Input :id="`inline-keep-count-${index}`" v-model="rule.count" type="number" min="1" />
                </div>
                <div v-else-if="rule.type === 'keep_newer_than_days'" class="grid w-28 gap-1">
                    <Label :for="`inline-keep-days-${index}`" class="text-xs">Tage</Label>
                    <Input :id="`inline-keep-days-${index}`" v-model="rule.days" type="number" min="1" />
                </div>
                <div v-else class="grid flex-1 gap-1">
                    <Label :for="`inline-keep-pattern-${index}`" class="text-xs">Muster</Label>
                    <Input :id="`inline-keep-pattern-${index}`" v-model="rule.pattern" type="text" :placeholder="PATTERN_HELP" />
                </div>
                <Button type="button" variant="ghost" size="icon" aria-label="Regel entfernen" @click="keepRules.splice(index, 1)">
                    <Trash2 class="size-4 text-destructive" />
                </Button>
            </div>
        </div>

        <!-- The shield: mechanically apart from the keep-rules, so visually apart. -->
        <div class="space-y-3 rounded-xl border border-copper/40 bg-copper/5 p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="flex items-center gap-2 text-sm font-medium"><Shield class="size-4" /> Nie löschen</h3>
                    <p class="mt-1 text-xs text-muted-foreground">{{ SHIELD_EXPLANATION }}</p>
                </div>
                <Button type="button" variant="outline" size="sm" @click="shields.push({ type: 'never_delete', pattern: '' })">
                    <Plus class="mr-1 size-4" /> Schutz
                </Button>
            </div>

            <div v-for="(shield, index) in shields" :key="`shield-${index}`" class="flex items-end gap-3">
                <div class="grid flex-1 gap-1">
                    <Label :for="`inline-shield-pattern-${index}`" class="text-xs">Muster</Label>
                    <Input :id="`inline-shield-pattern-${index}`" v-model="shield.pattern" type="text" :placeholder="PATTERN_HELP" />
                </div>
                <Button type="button" variant="ghost" size="icon" aria-label="Schutz entfernen" @click="shields.splice(index, 1)">
                    <Trash2 class="size-4 text-destructive" />
                </Button>
            </div>
        </div>

        <!-- Untagged images: decides MANIFESTS, not tags. -->
        <div class="space-y-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div>
                <h3 class="text-sm font-medium">Ungetaggte Images</h3>
                <p class="mt-1 text-xs text-muted-foreground">
                    Nur per Digest gepushte Images ohne Tag bleiben die angegebene Zahl von Tagen erhalten, statt nach der Schonfrist entfernt zu
                    werden.
                </p>
            </div>
            <div class="flex items-end gap-3">
                <div class="grid w-28 gap-1">
                    <Label for="inline-untagged-days" class="text-xs">Tage</Label>
                    <Input id="inline-untagged-days" v-model="untaggedDays" type="number" min="1" placeholder="—" />
                </div>
                <Button v-if="untaggedDays !== ''" type="button" variant="ghost" size="icon" aria-label="Regel entfernen" @click="untaggedDays = ''">
                    <Trash2 class="size-4 text-destructive" />
                </Button>
            </div>
        </div>
    </div>
</template>
