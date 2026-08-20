<script setup lang="ts">
import { computed } from 'vue';

// `depth` is internal — it drives recursion and is never set by a consumer. Only `value`
// and `maxDepth` are the public surface described in the component's usage.
const props = withDefaults(
    defineProps<{
        value: unknown;
        maxDepth?: number;
        depth?: number;
    }>(),
    { maxDepth: 3, depth: 0 },
);

interface Row {
    key: string | null;
    value: unknown;
}

type Classified =
    | { kind: 'string'; text: string }
    | { kind: 'number'; text: string }
    | { kind: 'boolean'; text: string }
    | { kind: 'null' }
    | { kind: 'array'; rows: Row[] }
    | { kind: 'object'; rows: Row[] }
    | { kind: 'other'; text: string };

// Classifies by runtime type rather than trusting any shape the caller claims — `value` is
// `unknown` because it ultimately comes from the activity log's `changes` payload, which is
// package metadata, repository URLs and sync error messages an outside party can influence.
function classify(input: unknown): Classified {
    if (input === null || input === undefined) {
        return { kind: 'null' };
    }
    if (Array.isArray(input)) {
        return { kind: 'array', rows: input.map((item) => ({ key: null, value: item })) };
    }
    if (typeof input === 'string') {
        return { kind: 'string', text: input };
    }
    if (typeof input === 'number') {
        return { kind: 'number', text: String(input) };
    }
    if (typeof input === 'boolean') {
        return { kind: 'boolean', text: String(input) };
    }
    if (typeof input === 'object') {
        return { kind: 'object', rows: Object.entries(input as Record<string, unknown>).map(([key, value]) => ({ key, value })) };
    }
    return { kind: 'other', text: String(input) };
}

const node = computed(() => classify(props.value));
const isCollection = computed(() => node.value.kind === 'array' || node.value.kind === 'object');
const rows = computed<Row[]>(() => (node.value.kind === 'array' || node.value.kind === 'object' ? node.value.rows : []));
const isEmpty = computed(() => isCollection.value && rows.value.length === 0);

// Beyond `maxDepth`, a collection collapses to a one-line summary instead of recursing
// further. Once the reader opens it, its children render with no further collapsing
// (`childMaxDepth` below) — one expand shows the whole subtree, not a chain of toggles.
const isCollapsed = computed(() => isCollection.value && !isEmpty.value && props.depth >= props.maxDepth);
const childMaxDepth = computed(() => (isCollapsed.value ? Number.POSITIVE_INFINITY : props.maxDepth));

const bracket = computed(() => (node.value.kind === 'array' ? { open: '[', close: ']' } : { open: '{', close: '}' }));

const summary = computed(() => {
    const count = rows.value.length;
    if (node.value.kind === 'array') {
        return `[${count} ${count === 1 ? 'Eintrag' : 'Einträge'}]`;
    }
    return `{${count} ${count === 1 ? 'Feld' : 'Felder'}}`;
});
</script>

<template>
    <div class="font-mono text-xs leading-relaxed">
        <span v-if="node.kind === 'string'" class="text-chart-2">"{{ node.text }}"</span>
        <span v-else-if="node.kind === 'number'" class="text-chart-1">{{ node.text }}</span>
        <span v-else-if="node.kind === 'boolean'" class="text-chart-4">{{ node.text }}</span>
        <span v-else-if="node.kind === 'null'" class="text-chart-5">null</span>
        <span v-else-if="node.kind === 'other'" class="text-muted-foreground">{{ node.text }}</span>

        <span v-else-if="isEmpty" class="text-muted-foreground">{{ bracket.open }}{{ bracket.close }}</span>

        <details v-else-if="isCollapsed">
            <summary class="cursor-pointer select-none text-muted-foreground hover:text-foreground">{{ summary }}</summary>
            <div class="mt-1 border-l border-border/60 pl-4">
                <div v-for="(row, index) in rows" :key="row.key ?? index">
                    <template v-if="row.key !== null"><span class="text-chart-3">"{{ row.key }}"</span><span class="text-muted-foreground">: </span></template>
                    <JsonViewer :value="row.value" :max-depth="childMaxDepth" :depth="depth + 1" />
                </div>
            </div>
        </details>

        <div v-else>
            <span class="text-muted-foreground">{{ bracket.open }}</span>
            <div class="border-l border-border/60 pl-4">
                <div v-for="(row, index) in rows" :key="row.key ?? index">
                    <template v-if="row.key !== null"><span class="text-chart-3">"{{ row.key }}"</span><span class="text-muted-foreground">: </span></template>
                    <JsonViewer :value="row.value" :max-depth="maxDepth" :depth="depth + 1" />
                </div>
            </div>
            <span class="text-muted-foreground">{{ bracket.close }}</span>
        </div>
    </div>
</template>
