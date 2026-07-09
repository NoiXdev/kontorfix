<script setup lang="ts">
import StatusPill from '@/components/kontorfix/StatusPill.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Copy, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface GroupInfo {
    id: string;
    name: string;
    slug: string;
    public: boolean;
    organization: string | null;
}

interface PackageRow {
    id: string;
    name: string;
    type: string;
    sync_status: 'pending' | 'syncing' | 'synced' | 'failed';
}

interface DomainRow {
    id: string;
    hostname: string;
}

interface UpstreamRow {
    id: string;
    type: string;
    url: string;
    policy: string;
}

interface TokenRow {
    id: string;
    name: string;
    ability: string;
}

interface Setup {
    composer: string;
    auth: string;
    npm: string;
}

const props = defineProps<{
    group: GroupInfo;
    packages: PackageRow[];
    domains: DomainRow[];
    upstreams: UpstreamRow[];
    tokens: TokenRow[];
    setup: Setup;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Gruppen', href: route('admin.groups.index') },
    { title: props.group.name, href: route('admin.groups.show', props.group.id) },
];

const steps = [
    { key: 'composer', title: 'Composer einrichten', content: () => props.setup.composer },
    { key: 'auth', title: 'Zugang einrichten', content: () => props.setup.auth },
    { key: 'npm', title: 'npm einrichten', content: () => props.setup.npm },
];

const form = useForm({
    name: props.group.name,
    public: props.group.public,
});

function save() {
    form.put(route('admin.groups.update', props.group.id), { preserveScroll: true });
}

const newDomain = ref('');

function addDomain() {
    router.post(
        route('admin.domains.store'),
        { group_id: props.group.id, hostname: newDomain.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                newDomain.value = '';
            },
        },
    );
}

function removeDomain(id: string) {
    router.delete(route('admin.domains.destroy', id), { preserveScroll: true });
}

const newUpstream = ref({
    type: 'composer' as 'composer' | 'npm',
    url: '',
    policy: 'proxy' as 'proxy' | 'strict',
});

function addUpstream() {
    router.post(
        route('admin.upstreams.store'),
        { group_id: props.group.id, type: newUpstream.value.type, url: newUpstream.value.url, policy: newUpstream.value.policy },
        {
            preserveScroll: true,
            onSuccess: () => {
                newUpstream.value = { type: 'composer', url: '', policy: 'proxy' };
            },
        },
    );
}

function removeUpstream(id: string) {
    router.delete(route('admin.upstreams.destroy', id), { preserveScroll: true });
}

const copiedKey = ref<string | null>(null);

async function copy(text: string, key: string) {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => {
            if (copiedKey.value === key) {
                copiedKey.value = null;
            }
        }, 2000);
    } catch {
        // Clipboard-API nicht verfügbar (unsicherer Kontext) — der Inhalt ist markierbar.
        copiedKey.value = null;
    }
}
</script>

<template>
    <Head :title="props.group.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold">{{ props.group.name }}</h1>
                    <span
                        v-if="props.group.public"
                        class="inline-flex items-center rounded-full border border-verdigris/30 bg-verdigris/15 px-2.5 py-0.5 text-xs font-medium text-verdigris"
                    >
                        Öffentlich
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center rounded-full border border-border bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                    >
                        Privat
                    </span>
                </div>
                <p v-if="props.group.organization" class="text-sm text-muted-foreground">
                    Kunde / Org: {{ props.group.organization }}
                </p>
            </div>

            <section class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <h2 class="text-lg font-medium">Bearbeiten</h2>
                <form class="flex flex-col gap-4" @submit.prevent="save">
                    <div class="flex flex-col gap-1.5">
                        <label for="registry-name" class="text-sm font-medium">Name</label>
                        <input
                            id="registry-name"
                            v-model="form.name"
                            type="text"
                            class="w-full max-w-md rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        />
                        <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.public" type="checkbox" class="size-4 rounded border-input" />
                        Öffentlich (ohne Token lesbar)
                    </label>

                    <div class="flex flex-col gap-1.5">
                        <span class="text-sm font-medium">Slug</span>
                        <p class="font-mono text-sm text-muted-foreground">/r/{{ props.group.slug }}</p>
                        <p class="text-xs text-muted-foreground">
                            Der Slug ist der feste Registry-Endpunkt und kann nicht geändert werden.
                        </p>
                    </div>

                    <div>
                        <Button type="submit" :disabled="form.processing">Speichern</Button>
                    </div>
                </form>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Pakete</h2>
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Typ</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="pkg in props.packages"
                                :key="pkg.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-mono">
                                    <Link :href="route('admin.packages.show', pkg.id)" class="hover:underline">
                                        {{ pkg.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">{{ pkg.type }}</td>
                                <td class="px-4 py-3"><StatusPill :status="pkg.sync_status" /></td>
                            </tr>
                            <tr v-if="props.packages.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">Noch keine Pakete in dieser Registry.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Domains</h2>
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <ul v-if="props.domains.length > 0" class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <li
                            v-for="domain in props.domains"
                            :key="domain.id"
                            class="flex items-center justify-between gap-4 px-4 py-3"
                        >
                            <span class="font-mono text-sm">{{ domain.hostname }}</span>
                            <Button variant="ghost" size="icon" aria-label="Domain entfernen" @click="removeDomain(domain.id)">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">Keine Domains hinterlegt.</p>
                </div>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="addDomain">
                    <div class="flex flex-col gap-1.5">
                        <label for="new-domain" class="text-sm font-medium">Hostname</label>
                        <input
                            id="new-domain"
                            v-model="newDomain"
                            type="text"
                            placeholder="packages.example.test"
                            class="w-full max-w-md rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        />
                    </div>
                    <Button type="submit">Hinzufügen</Button>
                </form>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Upstreams</h2>
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Typ</th>
                                <th class="px-4 py-3 font-medium">URL</th>
                                <th class="px-4 py-3 font-medium">Policy</th>
                                <th class="px-4 py-3 font-medium">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="upstream in props.upstreams"
                                :key="upstream.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3">{{ upstream.type }}</td>
                                <td class="px-4 py-3 font-mono text-muted-foreground">{{ upstream.url }}</td>
                                <td class="px-4 py-3">{{ upstream.policy }}</td>
                                <td class="px-4 py-3">
                                    <Button variant="ghost" size="icon" aria-label="Upstream entfernen" @click="removeUpstream(upstream.id)">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="props.upstreams.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Keine Upstreams konfiguriert.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="addUpstream">
                    <div class="flex flex-col gap-1.5">
                        <label for="new-upstream-type" class="text-sm font-medium">Typ</label>
                        <select
                            id="new-upstream-type"
                            v-model="newUpstream.type"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="composer">composer</option>
                            <option value="npm">npm</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="new-upstream-url" class="text-sm font-medium">URL</label>
                        <input
                            id="new-upstream-url"
                            v-model="newUpstream.url"
                            type="text"
                            placeholder="https://repo.packagist.org"
                            class="w-full min-w-64 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="new-upstream-policy" class="text-sm font-medium">Policy</label>
                        <select
                            id="new-upstream-policy"
                            v-model="newUpstream.policy"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="proxy">proxy</option>
                            <option value="strict">strict</option>
                        </select>
                    </div>
                    <Button type="submit">Hinzufügen</Button>
                </form>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Tokens</h2>
                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Recht</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="token in props.tokens"
                                :key="token.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-mono">{{ token.name }}</td>
                                <td class="px-4 py-3">{{ token.ability }}</td>
                            </tr>
                            <tr v-if="props.tokens.length === 0">
                                <td colspan="2" class="px-4 py-8 text-center text-muted-foreground">Noch keine Tokens erstellt.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-medium">Einrichtung</h2>
                <div class="grid gap-4">
                    <div
                        v-for="step in steps"
                        :key="step.key"
                        class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
                    >
                        <div class="flex items-center justify-between gap-4 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                            <h3 class="font-medium">{{ step.title }}</h3>
                            <Button variant="outline" size="sm" @click="copy(step.content(), step.key)">
                                <component :is="copiedKey === step.key ? Check : Copy" class="size-4" />
                                {{ copiedKey === step.key ? 'Kopiert!' : 'Kopieren' }}
                            </Button>
                        </div>
                        <pre class="overflow-x-auto px-4 py-3 font-mono text-sm">{{ step.content() }}</pre>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
