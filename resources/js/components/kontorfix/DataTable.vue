<script setup lang="ts" generic="T extends Record<string, unknown>">
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import type { ColumnDef, TableState } from '@/composables/useTableState';
import { ArrowDown, ArrowUp, ArrowUpDown, X } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        columns: ColumnDef<T>[];
        state: TableState<T>;
        emptyMessage?: string;
        searchPlaceholder?: string;
        showFilterBar?: boolean;
    }>(),
    {
        emptyMessage: 'Keine Einträge vorhanden.',
        searchPlaceholder: 'Suchen…',
        showFilterBar: true,
    },
);

function ariaSort(key: string): 'ascending' | 'descending' | 'none' {
    if (props.state.sortKey.value !== key) return 'none';
    return props.state.sortDirection.value === 'asc' ? 'ascending' : 'descending';
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div v-if="showFilterBar" class="flex flex-wrap items-center gap-2">
            <Input
                v-model="state.search.value"
                type="search"
                :placeholder="searchPlaceholder"
                class="h-9 w-full sm:max-w-64"
            />
            <slot name="filters" />
            <!-- A filtered table that looks short is otherwise indistinguishable from an
                 empty one, so the count appears only while a filter narrows the result. -->
            <span v-if="state.hasActiveFilters.value" class="text-xs text-muted-foreground">
                {{ state.matchCount.value }} von {{ state.totalCount.value }}
            </span>
            <Button
                v-if="state.hasActiveFilters.value"
                type="button"
                variant="ghost"
                size="sm"
                class="h-9"
                @click="state.reset()"
            >
                <X class="size-3.5" />
                Zurücksetzen
            </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-3 font-medium"
                            :aria-sort="column.sortable === false ? undefined : ariaSort(column.key)"
                        >
                            <button
                                v-if="column.sortable !== false"
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-foreground/80"
                                @click="state.toggleSort(column.key)"
                            >
                                {{ column.label }}
                                <ArrowUp v-if="ariaSort(column.key) === 'ascending'" class="size-3" />
                                <ArrowDown v-else-if="ariaSort(column.key) === 'descending'" class="size-3" />
                                <ArrowUpDown v-else class="size-3 opacity-40" />
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <slot :rows="state.visibleRows.value" />
                    <tr v-if="state.visibleRows.value.length === 0">
                        <td :colspan="columns.length" class="px-4 py-6 text-center text-sm text-muted-foreground">
                            {{ state.hasActiveFilters.value ? 'Keine Treffer für diese Filter.' : emptyMessage }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
