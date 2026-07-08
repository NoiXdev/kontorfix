<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface DomainRow {
    id: string;
    hostname: string;
    group: string | null;
    group_id: string;
}

interface GroupOption {
    id: string;
    name: string;
}

const props = defineProps<{
    domains: DomainRow[];
    groups: GroupOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Domains', href: '/admin/domains' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const dialogOpen = ref(false);

const form = useForm({
    group_id: '',
    hostname: '',
});

function submit() {
    form.post(route('admin.domains.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

function destroyDomain(id: string) {
    router.delete(route('admin.domains.destroy', id), {
        onBefore: () => confirm('Domain wirklich entfernen?'),
    });
}
</script>

<template>
    <Head title="Domains" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Domains</h1>
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Domain hinzufügen
                </Button>
            </div>

            <p class="text-sm text-muted-foreground">
                Eine Gruppe ist unter <code class="font-mono">https://&lt;Hostname&gt;/</code> erreichbar, sobald DNS und Reverse-Proxy auf diesen
                Host zeigen.
            </p>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Hostname</th>
                            <th class="px-4 py-3 font-medium">Gruppe</th>
                            <th class="px-4 py-3 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="domain in props.domains"
                            :key="domain.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3 font-mono text-xs">{{ domain.hostname }}</td>
                            <td class="px-4 py-3">{{ domain.group ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Button variant="ghost" size="icon" @click="destroyDomain(domain.id)" aria-label="Domain entfernen">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="props.domains.length === 0">
                            <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">Noch keine Domains angelegt.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Domain hinzufügen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="group_id">Gruppe</Label>
                        <select
                            id="group_id"
                            v-model="form.group_id"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option value="" disabled>Bitte wählen</option>
                            <option v-for="group in props.groups" :key="group.id" :value="group.id">{{ group.name }}</option>
                        </select>
                        <InputError :message="form.errors.group_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="hostname">Hostname</Label>
                        <Input id="hostname" v-model="form.hostname" placeholder="packages.kunde.de" autocomplete="off" />
                        <InputError :message="form.errors.hostname" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="form.processing">Anlegen</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
