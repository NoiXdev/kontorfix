<script setup lang="ts">
import { cn } from '@/lib/utils';

interface Activity {
    id: number;
    log_name: string | null;
    event: string | null;
    description: string;
    subject_type: string | null;
    subject_label: string | null;
    causer: string | null;
    changes: Record<string, unknown>;
    created_at: string | null;
    created_at_exact: string | null;
}

withDefaults(defineProps<{ activities: Activity[]; showSubject?: boolean }>(), { showSubject: false });

function pretty(value: unknown): string {
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

function hasChanges(a: Activity): boolean {
    return !!a.changes && Object.keys(a.changes).length > 0;
}

function eventClass(event: string | null): string {
    if (event === 'created') return 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400';
    if (event === 'deleted') return 'border-destructive/30 bg-destructive/15 text-destructive';
    return 'border-copper/30 bg-copper/15 text-copper-hi';
}
</script>

<template>
    <div class="space-y-2">
        <div v-for="a in activities" :key="a.id" class="rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm dark:border-sidebar-border">
            <div class="flex flex-wrap items-center gap-2">
                <span :class="cn('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium', eventClass(a.event))">
                    {{ a.event ?? a.description }}
                </span>
                <span v-if="showSubject && a.subject_type" class="text-muted-foreground">
                    {{ a.subject_type }}<span v-if="a.subject_label"> · {{ a.subject_label }}</span>
                </span>
                <span class="text-muted-foreground">von {{ a.causer ?? 'System' }}</span>
                <span class="ml-auto text-xs text-muted-foreground" :title="a.created_at_exact ?? ''">{{ a.created_at }}</span>
            </div>
            <details v-if="hasChanges(a)" class="mt-1">
                <summary class="cursor-pointer text-xs text-muted-foreground">Änderungen anzeigen</summary>
                <pre
                    class="mt-1 max-h-64 overflow-auto rounded-md border border-sidebar-border/70 bg-muted/40 p-2 text-xs dark:border-sidebar-border"
                    >{{ pretty(a.changes) }}</pre>
            </details>
        </div>
        <p v-if="activities.length === 0" class="px-1 py-6 text-center text-sm text-muted-foreground">Noch keine Aktivität.</p>
    </div>
</template>
