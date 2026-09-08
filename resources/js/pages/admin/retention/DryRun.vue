<script setup lang="ts">
import FlashToast from '@/components/kontorfix/FlashToast.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { DRY_RUN_EXPLANATION, NO_SPACE_FREED_YET } from './policies';

interface ReportTag {
    name: string;
    pushed_at: string | null;
    keep: boolean;
    reason: string | null;
}

interface PackageReport {
    package: { id: string; name: string };
    policy: { id: string; name: string };
    kept_count: number;
    removed_count: number;
    tags: ReportTag[];
}

const props = defineProps<{
    policy: { id: string; name: string };
    reports: PackageReport[];
    totals: { removed: number; kept: number };
    packages_evaluated: number;
    packages_truncated: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Retention', href: '/admin/retention-policies' },
    { title: `Probelauf: ${props.policy.name}`, href: '#' },
];

const confirmOpen = ref(false);
const applying = ref(false);

function apply() {
    applying.value = true;

    router.post(
        route('admin.retention-policies.apply', props.policy.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                applying.value = false;
                confirmOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Probelauf: ${props.policy.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <FlashToast />

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Probelauf: {{ props.policy.name }}</h1>
                    <p class="text-sm text-muted-foreground">{{ DRY_RUN_EXPLANATION }}</p>
                </div>
                <Button variant="destructive" :disabled="props.totals.removed === 0" @click="confirmOpen = true">
                    Jetzt anwenden
                </Button>
            </div>

            <div class="flex gap-6 rounded-xl border border-sidebar-border/70 p-4 text-sm dark:border-sidebar-border">
                <div><span class="font-semibold">{{ props.totals.removed }}</span> Tag(s) würden entfernt</div>
                <div><span class="font-semibold">{{ props.totals.kept }}</span> bleiben erhalten</div>
                <div class="text-muted-foreground">{{ props.packages_evaluated }} Repository/Repositories ausgewertet</div>
            </div>

            <!-- A bounded report SAYS it is bounded — silent truncation reads as full coverage. -->
            <div
                v-if="props.packages_truncated > 0"
                class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-400"
            >
                {{ props.packages_truncated }} weitere(s) Repository/Repositories fallen ebenfalls unter diese Richtlinie, wurden hier aber nicht
                ausgewertet. Der echte Lauf erfasst alle.
            </div>

            <div v-if="props.reports.length === 0" class="rounded-xl border border-sidebar-border/70 p-6 text-sm text-muted-foreground dark:border-sidebar-border">
                Kein Repository fällt unter diese Richtlinie.
            </div>

            <div
                v-for="report in props.reports"
                :key="report.package.id"
                class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <div class="flex items-center justify-between border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                    <span class="font-medium">{{ report.package.name }}</span>
                    <span class="text-sm text-muted-foreground">{{ report.removed_count }} entfernt · {{ report.kept_count }} bleibt</span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/40 text-left dark:border-sidebar-border/40">
                            <th class="px-4 py-2 font-medium">Tag</th>
                            <th class="px-4 py-2 font-medium">Gepusht</th>
                            <th class="px-4 py-2 font-medium">Entscheidung</th>
                            <th class="px-4 py-2 font-medium">Begründung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tag in report.tags" :key="tag.name" class="border-b border-sidebar-border/40 last:border-b-0 dark:border-sidebar-border/40">
                            <td class="px-4 py-2 font-mono">{{ tag.name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ tag.pushed_at ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <span v-if="tag.keep" class="text-emerald-600 dark:text-emerald-400">bleibt</span>
                                <span v-else class="text-destructive">wird entfernt</span>
                            </td>
                            <!-- An em dash for a removed tag, never an empty cell: empty reads
                                 like a missing value, the dash reads as "no rule keeps it". -->
                            <td class="px-4 py-2 text-muted-foreground">{{ tag.reason ?? '—' }}</td>
                        </tr>
                        <tr v-if="report.tags.length === 0">
                            <td colspan="4" class="px-4 py-2 text-muted-foreground">Keine Tags.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Dialog v-model:open="confirmOpen">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Richtlinie „{{ props.policy.name }}“ anwenden?</DialogTitle>
                    </DialogHeader>
                    <p class="text-sm">
                        {{ props.totals.removed }} Tag(s) werden entfernt. Beim Anwenden wird neu ausgewertet — pusht jemand zwischenzeitlich, zählt
                        der Stand von jetzt.
                    </p>
                    <p class="text-sm text-muted-foreground">{{ NO_SPACE_FREED_YET }}</p>
                    <DialogFooter>
                        <Button variant="outline" :disabled="applying" @click="confirmOpen = false">Abbrechen</Button>
                        <Button variant="destructive" :disabled="applying" @click="apply">{{ applying ? 'Läuft …' : 'Anwenden' }}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
