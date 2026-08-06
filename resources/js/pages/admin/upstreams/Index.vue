<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TypeBadge from '@/components/kontorfix/TypeBadge.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface UpstreamRow {
    id: string;
    group: string | null;
    group_id: string;
    type: 'composer' | 'npm' | 'python';
    url: string;
    policy: 'proxy' | 'strict';
    priority: number;
    enabled: boolean;
    has_auth: boolean;
    allowed_packages: string[];
}

interface GroupOption {
    id: string;
    name: string;
}

const props = defineProps<{
    upstreams: UpstreamRow[];
    groups: GroupOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Upstreams', href: '/admin/upstreams' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const dialogOpen = ref(false);
// null = create mode; a row id = editing that upstream.
const editingId = ref<string | null>(null);
// True once the edited upstream already had a stored token — enables the "remove" option.
const editingHasAuth = ref(false);

// Upstream types are the registry types — from the single source of truth.
const { options: registryTypeOptions } = useRegistryTypes();
const typeOptions = computed(() => registryTypeOptions());

const form = useForm({
    group_id: '',
    type: 'composer' as 'composer' | 'npm' | 'python',
    url: '',
    policy: 'proxy' as 'proxy' | 'strict',
    auth_token: '',
    remove_auth_token: false,
    priority: 0,
    allowed_packages_text: '',
});

function openCreate() {
    editingId.value = null;
    editingHasAuth.value = false;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(row: UpstreamRow) {
    editingId.value = row.id;
    editingHasAuth.value = row.has_auth;
    form.clearErrors();
    form.group_id = row.group_id;
    form.type = row.type;
    form.url = row.url;
    form.policy = row.policy;
    form.auth_token = '';
    form.remove_auth_token = false;
    form.priority = row.priority;
    form.allowed_packages_text = row.allowed_packages.join('\n');
    dialogOpen.value = true;
}

function submit() {
    form.transform((data) => ({
        group_id: data.group_id,
        type: data.type,
        url: data.url,
        policy: data.policy,
        auth_token: data.auth_token || null,
        remove_auth_token: data.remove_auth_token,
        priority: data.priority,
        allowed_packages:
            data.policy === 'strict'
                ? data.allowed_packages_text
                      .split('\n')
                      .map((n) => n.trim())
                      .filter((n) => n.length > 0)
                : [],
    }));

    const onSuccess = () => {
        dialogOpen.value = false;
        editingId.value = null;
        form.reset();
    };

    if (editingId.value !== null) {
        form.put(route('admin.upstreams.update', editingId.value), { onSuccess });
    } else {
        form.post(route('admin.upstreams.store'), { onSuccess });
    }
}

function policyLabel(policy: 'proxy' | 'strict') {
    return policy === 'strict' ? 'Strict' : 'Proxy';
}

function policyClasses(policy: 'proxy' | 'strict') {
    return policy === 'strict'
        ? 'bg-amber-500/15 text-amber-600 border-amber-500/30 dark:text-amber-400'
        : 'bg-emerald-500/15 text-emerald-600 border-emerald-500/30 dark:text-emerald-400';
}

function destroyUpstream(id: string) {
    router.delete(route('admin.upstreams.destroy', id), {
        onBefore: () => confirm('Upstream wirklich löschen?'),
    });
}
</script>

<template>
    <Head title="Upstreams" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Upstreams</h1>
                <Button @click="openCreate">
                    <Plus class="size-4" />
                    Upstream hinzufügen
                </Button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Gruppe</th>
                            <th class="px-4 py-3 font-medium">Typ</th>
                            <th class="px-4 py-3 font-medium">URL</th>
                            <th class="px-4 py-3 font-medium">Policy</th>
                            <th class="px-4 py-3 font-medium">Priorität</th>
                            <th class="px-4 py-3 font-medium">Aktiv</th>
                            <th class="px-4 py-3 font-medium">Auth</th>
                            <th class="px-4 py-3 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="upstream in props.upstreams"
                            :key="upstream.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3">{{ upstream.group ?? '—' }}</td>
                            <td class="px-4 py-3"><TypeBadge :type="upstream.type" /></td>
                            <td class="px-4 py-3 font-mono text-xs">{{ upstream.url }}</td>
                            <td class="px-4 py-3">
                                <span :class="cn('inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium', policyClasses(upstream.policy))">
                                    {{ policyLabel(upstream.policy) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ upstream.priority }}</td>
                            <td class="px-4 py-3">
                                <span
                                    :class="
                                        cn(
                                            'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                                            upstream.enabled
                                                ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                                : 'border-border bg-muted text-muted-foreground',
                                        )
                                    "
                                >
                                    {{ upstream.enabled ? 'Ja' : 'Nein' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="upstream.has_auth"
                                    class="inline-flex items-center rounded-md border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                                >
                                    Token
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1">
                                    <Button variant="ghost" size="icon" @click="openEdit(upstream)" aria-label="Upstream bearbeiten">
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button variant="ghost" size="icon" @click="destroyUpstream(upstream.id)" aria-label="Upstream löschen">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="props.upstreams.length === 0">
                            <td colspan="8" class="px-4 py-8 text-center text-muted-foreground">Noch keine Upstreams angelegt.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ editingId ? 'Upstream bearbeiten' : 'Upstream hinzufügen' }}</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="group_id">Gruppe</Label>
                        <SearchableSelect
                            id="group_id"
                            v-model="form.group_id"
                            placeholder="Bitte wählen"
                            :disabled="editingId !== null"
                            :options="props.groups.map((g) => ({ value: g.id, label: g.name }))"
                        />
                        <InputError :message="form.errors.group_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="type">Typ</Label>
                        <SearchableSelect id="type" v-model="form.type" :options="typeOptions" />
                        <InputError :message="form.errors.type" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="url">URL</Label>
                        <Input id="url" v-model="form.url" placeholder="https://repo.packagist.org" autocomplete="off" />
                        <InputError :message="form.errors.url" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="policy">Policy</Label>
                        <SearchableSelect
                            id="policy"
                            v-model="form.policy"
                            :options="[
                                { value: 'proxy', label: 'Proxy' },
                                { value: 'strict', label: 'Strict' },
                            ]"
                        />
                        <InputError :message="form.errors.policy" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="auth_token">Auth-Token (optional)</Label>
                        <Input
                            id="auth_token"
                            v-model="form.auth_token"
                            type="password"
                            autocomplete="off"
                            :placeholder="editingHasAuth ? 'Leer lassen, um den bestehenden Token zu behalten' : 'für private Upstreams'"
                        />
                        <label v-if="editingHasAuth" class="flex items-center gap-2 text-sm text-muted-foreground">
                            <input v-model="form.remove_auth_token" type="checkbox" class="rounded border-input" />
                            Gespeicherten Token entfernen
                        </label>
                        <InputError :message="form.errors.auth_token" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="priority">Priorität</Label>
                        <Input id="priority" v-model.number="form.priority" type="number" min="0" />
                        <InputError :message="form.errors.priority" />
                    </div>

                    <div v-if="form.policy === 'strict'" class="grid gap-2">
                        <Label for="allowed_packages_text">Allowlist (ein Paket pro Zeile)</Label>
                        <textarea
                            id="allowed_packages_text"
                            v-model="form.allowed_packages_text"
                            rows="4"
                            placeholder="symfony/console&#10;psr/log"
                            class="flex w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        />
                        <InputError :message="form.errors.allowed_packages" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="form.processing">{{ editingId ? 'Speichern' : 'Anlegen' }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
