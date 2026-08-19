<script setup lang="ts" generic="T extends string | number">
import { cn } from '@/lib/utils';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

interface Option {
    value: T;
    label: string;
    disabled?: boolean;
}

const props = withDefaults(
    defineProps<{
        options: Option[];
        id?: string;
        placeholder?: string;
        searchPlaceholder?: string;
        disabled?: boolean;
        // Below this many options the search box is hidden — pointless for short lists.
        searchThreshold?: number;
    }>(),
    {
        placeholder: 'Bitte wählen',
        searchPlaceholder: 'Suchen…',
        disabled: false,
        searchThreshold: 7,
    },
);

// `required: true`: every caller (whether via `v-model` on a form field or the explicit
// `:model-value`/`@update:model-value` pair some filter dropdowns use) always supplies a
// concrete value — `pick()` below only ever writes a real `Option['value']`, never
// `undefined`. Without `required: true`, `defineModel` still types the prop (and so the
// emitted value) as `T | undefined`, which is a strictVModel mismatch against every
// caller's plain, always-defined `T` field. This corrects the declared type to match what
// the component has always actually done; it doesn't change any behaviour.
const model = defineModel<T>({ required: true });

const open = ref(false);
const query = ref('');
const activeIndex = ref(-1);
const root = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const listId = `ss-list-${Math.random().toString(36).slice(2)}`;

const selectedLabel = computed(() => props.options.find((o) => o.value === model.value)?.label ?? '');

const showSearch = computed(() => props.options.length >= props.searchThreshold);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (q === '') {
        return props.options;
    }
    return props.options.filter((o) => o.label.toLowerCase().includes(q));
});

function openMenu() {
    if (props.disabled) {
        return;
    }
    open.value = true;
    query.value = '';
    // Highlight the current selection, or the first item.
    const current = filtered.value.findIndex((o) => o.value === model.value);
    activeIndex.value = current >= 0 ? current : 0;
    nextTick(() => {
        if (showSearch.value) {
            searchInput.value?.focus();
        }
    });
}

function closeMenu() {
    open.value = false;
    activeIndex.value = -1;
}

function toggle() {
    open.value ? closeMenu() : openMenu();
}

function pick(option: Option) {
    if (option.disabled) {
        return;
    }
    model.value = option.value;
    closeMenu();
    // Return focus to the trigger for keyboard users.
    nextTick(() => (root.value?.querySelector('[data-trigger]') as HTMLElement | null)?.focus());
}

function move(delta: number) {
    if (!open.value) {
        openMenu();
        return;
    }
    const list = filtered.value;
    if (list.length === 0) {
        return;
    }
    let i = activeIndex.value;
    for (let step = 0; step < list.length; step++) {
        i = (i + delta + list.length) % list.length;
        if (!list[i]?.disabled) {
            break;
        }
    }
    activeIndex.value = i;
    scrollActiveIntoView();
}

function scrollActiveIntoView() {
    nextTick(() => {
        const el = document.getElementById(`${listId}-opt-${activeIndex.value}`);
        el?.scrollIntoView({ block: 'nearest' });
    });
}

function onEnter() {
    if (open.value && filtered.value[activeIndex.value]) {
        pick(filtered.value[activeIndex.value]);
    } else {
        openMenu();
    }
}

// Reset the active row to the top whenever the filter changes.
watch(query, () => {
    activeIndex.value = filtered.value.length > 0 ? 0 : -1;
});

function onDocumentClick(event: MouseEvent) {
    if (root.value && !root.value.contains(event.target as Node)) {
        closeMenu();
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('mousedown', onDocumentClick);
    } else {
        document.removeEventListener('mousedown', onDocumentClick);
    }
});

onBeforeUnmount(() => document.removeEventListener('mousedown', onDocumentClick));
</script>

<template>
    <div ref="root" class="relative">
        <button
            :id="id"
            type="button"
            data-trigger
            role="combobox"
            :aria-expanded="open"
            :aria-controls="listId"
            :disabled="disabled"
            :class="
                cn(
                    'flex h-10 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                )
            "
            @click="toggle"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="onEnter"
            @keydown.esc.prevent="closeMenu"
        >
            <span :class="cn('truncate', !selectedLabel && 'text-muted-foreground')">{{ selectedLabel || placeholder }}</span>
            <ChevronsUpDown class="size-4 shrink-0 opacity-50" />
        </button>

        <div
            v-if="open"
            class="absolute z-50 mt-1 w-full overflow-hidden rounded-md border border-input bg-popover text-popover-foreground shadow-md"
        >
            <div v-if="showSearch" class="flex items-center gap-2 border-b border-input px-3">
                <Search class="size-4 shrink-0 opacity-50" />
                <input
                    ref="searchInput"
                    v-model="query"
                    type="text"
                    :placeholder="searchPlaceholder"
                    class="h-9 w-full bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="onEnter"
                    @keydown.esc.prevent="closeMenu"
                />
            </div>

            <ul :id="listId" role="listbox" class="max-h-60 overflow-auto py-1">
                <li
                    v-for="(option, index) in filtered"
                    :id="`${listId}-opt-${index}`"
                    :key="String(option.value)"
                    role="option"
                    :aria-selected="option.value === model"
                    :class="
                        cn(
                            'flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm',
                            index === activeIndex && 'bg-accent text-accent-foreground',
                            option.disabled && 'cursor-not-allowed opacity-50',
                        )
                    "
                    @mouseenter="activeIndex = index"
                    @click="pick(option)"
                >
                    <span class="truncate">{{ option.label }}</span>
                    <Check v-if="option.value === model" class="size-4 shrink-0" />
                </li>
                <li v-if="filtered.length === 0" class="px-3 py-2 text-sm text-muted-foreground">Keine Treffer.</li>
            </ul>
        </div>
    </div>
</template>
