<script setup lang="ts">
import FlashToast from '@/components/kontorfix/FlashToast.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Clock, Package as PackageIcon, Recycle, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
import { GRACE_HELD_CAPTION, SWEEP_CONFIRMATION, SWEEPER_EXPLANATION } from './sweeper';

const props = defineProps<{
    pending: {
        manifests_removed: number;
        blobs_removed: number;
        bytes_reclaimed: number;
        blobs_held_by_grace: number;
        blobs_remaining: number;
        uploads_removed: number;
        repositories_removed: number;
    };
    grace_hours: number;
    blob_limit: number;
    last_run: {
        event: string | null;
        created_at: string | null;
        created_at_exact: string | null;
        properties: Record<string, number>;
    } | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Speicherbereinigung', href: '/admin/oci/sweeper' }];

const confirmOpen = ref(false);
const queueing = ref(false);

function runSweep() {
    queueing.value = true;

    router.post(
        route('admin.oci.sweeper.run'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                queueing.value = false;
                confirmOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Speicherbereinigung" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <FlashToast />

            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Speicherbereinigung</h1>
                    <p class="max-w-2xl text-sm text-muted-foreground">{{ SWEEPER_EXPLANATION }}</p>
                </div>
                <Button @click="confirmOpen = true">Jetzt bereinigen</Button>
            </div>

            <!-- The four figures, plate 6. The grace-held one carries its caption because a
                 bare non-zero number during a push reads as a fault — it is the opposite. -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground"><Recycle class="size-4" /> Unerreichbare Blobs</div>
                    <div class="mt-2 text-2xl font-semibold">{{ props.pending.blobs_remaining }}</div>
                    <p class="mt-1 text-xs text-muted-foreground">warten auf den nächsten Lauf (Budget: {{ props.blob_limit }} pro Lauf)</p>
                </div>
                <div class="rounded-xl border border-copper/40 bg-copper/5 p-5">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground"><Clock class="size-4" /> Von der Schonfrist gehalten</div>
                    <div class="mt-2 text-2xl font-semibold">{{ props.pending.blobs_held_by_grace }}</div>
                    <p class="mt-1 text-xs text-muted-foreground">{{ GRACE_HELD_CAPTION }}</p>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground"><Upload class="size-4" /> Abgelaufene Upload-Sessions</div>
                    <div class="mt-2 text-2xl font-semibold">{{ props.pending.uploads_removed }}</div>
                    <p class="mt-1 text-xs text-muted-foreground">abgebrochene Pushes, deren Reste entfernt werden</p>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground"><PackageIcon class="size-4" /> Leere Push-Repositories</div>
                    <div class="mt-2 text-2xl font-semibold">{{ props.pending.repositories_removed }}</div>
                    <p class="mt-1 text-xs text-muted-foreground">beim Push angelegt, nie ein Image erhalten</p>
                </div>
            </div>

            <div class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-5 text-sm dark:border-sidebar-border">
                <div>
                    <span class="text-muted-foreground">Schonfrist:</span>
                    <span class="ml-1 font-medium">{{ props.grace_hours }} Stunden</span>
                    <span class="ml-2 text-xs text-muted-foreground">(einstellbar in den Systemeinstellungen)</span>
                </div>
                <div v-if="props.last_run">
                    <span class="text-muted-foreground">Letzter Lauf:</span>
                    <span class="ml-1 font-medium" :title="props.last_run.created_at_exact ?? undefined">{{ props.last_run.created_at }}</span>
                    <span class="ml-2 text-xs text-muted-foreground">
                        {{ props.last_run.properties.manifests_removed ?? 0 }} Manifest(e),
                        {{ props.last_run.properties.blobs_removed ?? 0 }} Blob(s),
                        {{ props.last_run.properties.uploads_removed ?? 0 }} Session(s),
                        {{ props.last_run.properties.repositories_removed ?? 0 }} Repository/Repositories entfernt
                    </span>
                </div>
                <div v-else class="text-muted-foreground">Noch kein Lauf, der etwas entfernt hat.</div>
            </div>

            <Dialog v-model:open="confirmOpen">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Speicherbereinigung starten?</DialogTitle>
                    </DialogHeader>
                    <p class="text-sm">{{ SWEEP_CONFIRMATION }}</p>
                    <DialogFooter>
                        <Button variant="outline" :disabled="queueing" @click="confirmOpen = false">Abbrechen</Button>
                        <Button :disabled="queueing" @click="runSweep">{{ queueing ? 'Wird eingereiht …' : 'Bereinigen' }}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
