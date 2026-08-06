<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface CredentialRow {
    id: string;
    name: string;
    provider: string;
    username: string | null;
    organization: string | null;
    organization_id: string | null;
    packages_count: number;
    last_used_at: string | null;
}

const props = defineProps<{
    credentials: CredentialRow[];
    organizations: { id: string; name: string }[];
    providers: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Git-Tokens', href: '/admin/git-credentials' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const providerOptions = computed(() => props.providers.map((p) => ({ value: p.value, label: p.label })));
const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

// --- Create / edit ---
const dialogOpen = ref(false);
const editing = ref<CredentialRow | null>(null);

const form = useForm({
    name: '',
    organization_id: '' as string | null,
    provider: 'github',
    username: '',
    token: '',
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.organization_id = props.organizations[0]?.id ?? '';
    dialogOpen.value = true;
}

function openEdit(row: CredentialRow) {
    editing.value = row;
    form.clearErrors();
    form.name = row.name;
    form.provider = row.provider;
    form.username = row.username ?? '';
    form.token = '';
    form.organization_id = row.organization_id;
    dialogOpen.value = true;
}

function submit() {
    if (editing.value) {
        form.put(route('admin.git-credentials.update', editing.value.id), {
            preserveScroll: true,
            onSuccess: () => (dialogOpen.value = false),
        });
    } else {
        form.post(route('admin.git-credentials.store'), {
            preserveScroll: true,
            onSuccess: () => (dialogOpen.value = false),
        });
    }
}

function destroyCredential(id: string) {
    router.delete(route('admin.git-credentials.destroy', id), {
        preserveScroll: true,
        onBefore: () => confirm('Git-Token wirklich löschen?'),
    });
}

// --- Test ---
const testOpenFor = ref<string | null>(null);
const testUrl = ref('');
const testing = ref(false);
const testResult = ref<{ ok: boolean; error?: string | null } | null>(null);

function openTest(id: string) {
    testOpenFor.value = testOpenFor.value === id ? null : id;
    testUrl.value = '';
    testResult.value = null;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function runTest(id: string) {
    if (testing.value || testUrl.value.trim() === '') {
        return;
    }
    testing.value = true;
    testResult.value = null;
    try {
        const response = await fetch(route('admin.git-credentials.test', id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ repository_url: testUrl.value }),
        });
        if (!response.ok) {
            testResult.value = { ok: false, error: 'Test fehlgeschlagen.' };
            return;
        }
        testResult.value = await response.json();
    } catch {
        testResult.value = { ok: false, error: 'Test fehlgeschlagen.' };
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <Head title="Git-Tokens" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Git-Tokens</h1>
                    <p class="text-sm text-muted-foreground">Zugriffs-Tokens für private Repositories, wiederverwendbar und Paketen zuweisbar.</p>
                </div>
                <Button @click="openCreate">
                    <Plus class="size-4" />
                    Token hinterlegen
                </Button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Provider</th>
                            <th class="px-4 py-3 font-medium">Organisation</th>
                            <th class="px-4 py-3 font-medium">Pakete</th>
                            <th class="px-4 py-3 font-medium">Zuletzt genutzt</th>
                            <th class="px-4 py-3 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="cred in props.credentials" :key="cred.id">
                            <tr class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border">
                                <td class="px-4 py-3 font-medium">{{ cred.name }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase text-muted-foreground">{{ cred.provider }}</span>
                                </td>
                                <td class="px-4 py-3">{{ cred.organization ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ cred.packages_count }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ cred.last_used_at ?? 'nie' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1">
                                        <Button variant="ghost" size="sm" @click="openTest(cred.id)"><KeyRound class="size-4" /> Testen</Button>
                                        <Button variant="ghost" size="icon" aria-label="Bearbeiten" @click="openEdit(cred)"><Pencil class="size-4" /></Button>
                                        <Button variant="ghost" size="icon" aria-label="Löschen" @click="destroyCredential(cred.id)">
                                            <Trash2 class="size-4 text-destructive" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="testOpenFor === cred.id" class="border-b border-sidebar-border/70 bg-muted/30 dark:border-sidebar-border">
                                <td colspan="6" class="px-4 py-3">
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div class="grid flex-1 gap-1.5">
                                            <Label :for="`test-url-${cred.id}`">Repository-URL zum Testen (HTTPS)</Label>
                                            <Input
                                                :id="`test-url-${cred.id}`"
                                                v-model="testUrl"
                                                placeholder="https://github.com/acme/private.git"
                                                autocomplete="off"
                                                class="font-mono"
                                                @keyup.enter="runTest(cred.id)"
                                            />
                                        </div>
                                        <Button type="button" variant="outline" :disabled="testing || testUrl.trim() === ''" @click="runTest(cred.id)">
                                            {{ testing ? 'Teste…' : 'Test starten' }}
                                        </Button>
                                    </div>
                                    <p v-if="testResult?.ok" class="mt-2 text-sm text-verdigris">Erreichbar — der Token funktioniert.</p>
                                    <p v-else-if="testResult" class="mt-2 text-sm text-destructive">{{ testResult.error ?? 'Zugriff fehlgeschlagen.' }}</p>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="props.credentials.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">Noch keine Git-Tokens hinterlegt.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Git-Token bearbeiten' : 'Git-Token hinterlegen' }}</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="cred_name">Name</Label>
                        <Input id="cred_name" v-model="form.name" placeholder="z. B. GitHub Deploy" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div v-if="!editing && orgOptions.length > 1" class="grid gap-2">
                        <Label for="cred_org">Organisation</Label>
                        <SearchableSelect id="cred_org" v-model="form.organization_id" :options="orgOptions" />
                        <InputError :message="form.errors.organization_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="cred_provider">Provider</Label>
                        <SearchableSelect id="cred_provider" v-model="form.provider" :options="providerOptions" />
                        <InputError :message="form.errors.provider" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="cred_username">Benutzername <span class="text-muted-foreground">(optional)</span></Label>
                        <Input id="cred_username" v-model="form.username" placeholder="leer = Provider-Standard" autocomplete="off" />
                        <InputError :message="form.errors.username" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="cred_token">Token{{ editing ? ' (leer lassen = unverändert)' : '' }}</Label>
                        <Input id="cred_token" v-model="form.token" type="password" placeholder="ghp_… / glpat-… / …" autocomplete="off" class="font-mono" />
                        <InputError :message="form.errors.token" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">Abbrechen</Button>
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
