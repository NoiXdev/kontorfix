<script setup lang="ts">
import FlashToast from '@/components/kontorfix/FlashToast.vue';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { FlaskConical, Pencil, Plus, Trash2 } from 'lucide-vue-next';

interface PolicyRow {
    id: string;
    name: string;
    // Already-described rules (RetentionRule::describe()), one string per rule — the same
    // wording the dry run's reason column and the portal use.
    rules: string[];
    is_global: boolean;
    // Null for an org admin: the governed count spans the instance and is operator info.
    package_count: number | null;
    is_instance_default: boolean;
}

const props = defineProps<{
    policies: PolicyRow[];
    // False for an org admin: the page lists the PUBLISHED policies read-only; every
    // mutating route stays in the super group regardless of what is rendered here.
    can_manage: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Retention', href: '/admin/retention-policies' }];

function destroy(policy: PolicyRow) {
    if (!confirm(`Richtlinie „${policy.name}“ löschen? Pakete, die sie nutzen, behalten danach alle Tags.`)) {
        return;
    }

    router.delete(route('admin.retention-policies.destroy', policy.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Retention" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <FlashToast />

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Aufbewahrungsrichtlinien</h1>
                    <p class="text-sm text-muted-foreground">
                        Benannte Regelsätze, die entscheiden, welche Tags eines Image-Repositorys erhalten bleiben.
                    </p>
                </div>
                <Button v-if="props.can_manage" as-child>
                    <Link :href="route('admin.retention-policies.create')"><Plus class="mr-1 size-4" /> Neue Richtlinie</Link>
                </Button>
            </div>

            <div
                v-if="props.policies.length === 0"
                class="rounded-xl border border-sidebar-border/70 p-6 text-sm text-muted-foreground dark:border-sidebar-border"
            >
                Noch keine Richtlinien. Ohne Richtlinie wird nichts entfernt — jedes Repository behält alle Tags.
            </div>

            <div v-else class="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 bg-muted/40 text-left dark:border-sidebar-border">
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Regeln</th>
                            <th class="px-4 py-2 font-medium">Repositories</th>
                            <th class="px-4 py-2 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="policy in props.policies"
                            :key="policy.id"
                            class="border-b border-sidebar-border/40 last:border-b-0 dark:border-sidebar-border/40"
                        >
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ policy.name }}</span>
                                <span
                                    v-if="policy.is_instance_default"
                                    class="ml-2 inline-flex items-center rounded-full border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                                >
                                    Instanz-Vorgabe
                                </span>
                                <span
                                    v-if="policy.is_global"
                                    class="ml-2 inline-flex items-center rounded-full border border-emerald-500/30 bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                                >
                                    Veröffentlicht
                                </span>
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">{{ policy.rules.join(' · ') }}</td>
                            <td class="px-4 py-2">{{ policy.package_count ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <div v-if="props.can_manage" class="flex items-center gap-1">
                                    <Button variant="ghost" size="icon" as-child :aria-label="`Probelauf für ${policy.name}`">
                                        <Link :href="route('admin.retention-policies.dry-run', policy.id)">
                                            <FlaskConical class="size-4" />
                                        </Link>
                                    </Button>
                                    <Button variant="ghost" size="icon" as-child :aria-label="`${policy.name} bearbeiten`">
                                        <Link :href="route('admin.retention-policies.edit', policy.id)">
                                            <Pencil class="size-4" />
                                        </Link>
                                    </Button>
                                    <!-- Disabled for the instance default WITH the reason in reach: an
                                         unexplained dead button sends the operator hunting. The server
                                         refuses the delete too (409); this is the polite layer. -->
                                    <Tooltip v-if="policy.is_instance_default">
                                        <TooltipTrigger as-child>
                                            <span>
                                                <Button variant="ghost" size="icon" disabled aria-label="Löschen nicht möglich">
                                                    <Trash2 class="size-4" />
                                                </Button>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent> Instanz-Vorgabe — zuerst in den Systemeinstellungen entfernen. </TooltipContent>
                                    </Tooltip>
                                    <Button v-else variant="ghost" size="icon" :aria-label="`${policy.name} löschen`" @click="destroy(policy)">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </div>
                                <span v-else class="text-xs text-muted-foreground">nur lesend</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
