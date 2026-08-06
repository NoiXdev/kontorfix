<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusPill from '@/components/kontorfix/StatusPill.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import AppLayout from '@/layouts/AppLayout.vue';
import { useOperatorChannel, type PackagePayload } from '@/composables/useOperatorChannel';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface PackageRow {
    id: string;
    type: 'composer' | 'npm' | 'python';
    name: string;
    sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
    sync_error: string | null;
    groups_count: number;
    synced_at: string | null;
}

interface GroupOption {
    id: string;
    name: string;
    slug: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
}

interface Filters {
    q: string | null;
    type: string | null;
    status: string | null;
    group: string | null;
}

const props = defineProps<{
    packages: Paginated<PackageRow>;
    groups: GroupOption[];
    filters: Filters;
    registryTypes: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pakete', href: '/admin/packages' }];

// Only instance-enabled types are offered in the create dialog and the filter.
const typeOptions = computed(() => props.registryTypes.map((t) => ({ value: t, label: t })));

const filterQ = ref(props.filters.q ?? '');
const filterType = ref(props.filters.type ?? '');
const filterStatus = ref(props.filters.status ?? '');
const filterGroup = ref(props.filters.group ?? '');

const hasActiveFilters = computed(
    () => filterQ.value !== '' || filterType.value !== '' || filterStatus.value !== '' || filterGroup.value !== '',
);

function applyFilters() {
    router.get(
        route('admin.packages.index'),
        { q: filterQ.value, type: filterType.value, status: filterStatus.value, group: filterGroup.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
watch(filterQ, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 250);
});
watch([filterType, filterStatus, filterGroup], applyFilters);

function resetFilters() {
    filterQ.value = '';
    filterType.value = '';
    filterStatus.value = '';
    filterGroup.value = '';
}

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

// Live hint for sync events via the operator channel.
const liveHint = ref<{ message: string; failed: boolean } | null>(null);
let hintTimer: ReturnType<typeof setTimeout> | undefined;

function showHint(message: string, failed: boolean) {
    liveHint.value = { message, failed };
    clearTimeout(hintTimer);
    hintTimer = setTimeout(() => (liveHint.value = null), 5000);
}

function applyStatus(p: PackagePayload) {
    const row = props.packages.data.find((pkg) => pkg.id === p.id);
    if (row) {
        row.sync_status = p.sync_status as PackageRow['sync_status'];
        row.sync_error = p.error ?? null;
    }
}

const isOperator = page.props.auth.can?.console ?? false;
if (isOperator) {
    useOperatorChannel({
        onSynced: (p) => {
            applyStatus(p);
            showHint(`${p.name} synchronisiert`, false);
        },
        onFailed: (p) => {
            applyStatus(p);
            showHint(`${p.name}: Sync fehlgeschlagen`, true);
        },
    });
}

const dialogOpen = ref(false);

const form = useForm({
    type: 'composer' as 'composer' | 'npm' | 'python',
    name: '',
    repository_url: '',
    group_ids: [] as string[],
});

function submit() {
    form.post(route('admin.packages.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

function toggleGroup(groupId: string, checked: boolean) {
    if (checked) {
        form.group_ids.push(groupId);
    } else {
        form.group_ids = form.group_ids.filter((id) => id !== groupId);
    }
}

function destroyPackage(id: string) {
    router.delete(route('admin.packages.destroy', id), {
        onBefore: () => confirm('Paket wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Pakete" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div
                v-if="liveHint"
                class="fixed right-4 top-16 z-50 rounded-md border px-4 py-2 text-sm shadow-lg"
                :class="
                    liveHint.failed
                        ? 'border-destructive/30 bg-destructive/10 text-destructive'
                        : 'border-verdigris/30 bg-verdigris/15 text-verdigris'
                "
            >
                {{ liveHint.message }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Pakete</h1>
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Paket hinzufügen
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Input
                    v-model="filterQ"
                    type="search"
                    placeholder="Name suchen…"
                    autocomplete="off"
                    class="h-10 w-full sm:w-64"
                    aria-label="Nach Name suchen"
                />
                <SearchableSelect
                    v-model="filterType"
                    class="w-40"
                    placeholder="Alle Typen"
                    :options="[{ value: '', label: 'Alle Typen' }, ...typeOptions]"
                />
                <SearchableSelect
                    v-model="filterStatus"
                    class="w-40"
                    placeholder="Alle Status"
                    :options="[
                        { value: '', label: 'Alle Status' },
                        { value: 'pending', label: 'pending' },
                        { value: 'syncing', label: 'syncing' },
                        { value: 'synced', label: 'synced' },
                        { value: 'failed', label: 'failed' },
                    ]"
                />
                <SearchableSelect
                    v-model="filterGroup"
                    class="w-52"
                    placeholder="Alle Registries"
                    :options="[{ value: '', label: 'Alle Registries' }, ...props.groups.map((g) => ({ value: g.id, label: g.name }))]"
                />
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    @click="resetFilters"
                >
                    Zurücksetzen
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Typ</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Gruppen</th>
                            <th class="px-4 py-3 font-medium">Zuletzt synchronisiert</th>
                            <th class="px-4 py-3 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="pkg in props.packages.data"
                            :key="pkg.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3 font-mono">
                                <Link :href="route('admin.packages.show', pkg.id)" class="hover:underline">{{ pkg.name }}</Link>
                            </td>
                            <td class="px-4 py-3"><TypeBadge :type="pkg.type" /></td>
                            <td class="px-4 py-3">
                                <span :title="pkg.sync_status === 'failed' ? (pkg.sync_error ?? undefined) : undefined">
                                    <StatusPill :status="pkg.sync_status" />
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ pkg.groups_count }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ pkg.synced_at ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <Button variant="ghost" size="icon" @click="destroyPackage(pkg.id)" aria-label="Paket löschen">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="props.packages.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">Noch keine Pakete angelegt.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Paket hinzufügen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="type">Typ</Label>
                        <SearchableSelect
                            id="type"
                            v-model="form.type"
                            :options="typeOptions"
                        />
                        <InputError :message="form.errors.type" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" :placeholder="form.type === 'python' ? 'projektname' : 'vendor/paket'" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <!-- Publish-based (Python): no git repository — distributions arrive via twine. -->
                    <div v-if="form.type !== 'python'" class="grid gap-2">
                        <Label for="repository_url">Repository-URL</Label>
                        <Input
                            id="repository_url"
                            v-model="form.repository_url"
                            placeholder="https://git.example.com/vendor/paket.git"
                            autocomplete="off"
                        />
                        <InputError :message="form.errors.repository_url" />
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        Publish-basiert — Distributionen werden per <code>twine upload</code> hochgeladen (kein Repository nötig).
                    </p>

                    <div class="grid gap-2">
                        <Label>Gruppen</Label>
                        <div class="max-h-40 space-y-2 overflow-y-auto rounded-md border border-input p-3">
                            <div v-for="group in props.groups" :key="group.id" class="flex items-center gap-2">
                                <Checkbox
                                    :id="`group-${group.id}`"
                                    :model-value="form.group_ids.includes(group.id)"
                                    @update:model-value="(checked) => toggleGroup(group.id, checked === true)"
                                />
                                <Label :for="`group-${group.id}`" class="font-normal">{{ group.name }}</Label>
                            </div>
                            <p v-if="props.groups.length === 0" class="text-sm text-muted-foreground">Keine Gruppen vorhanden.</p>
                        </div>
                        <InputError :message="form.errors.group_ids" />
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
