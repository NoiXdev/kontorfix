<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { DownloadCloud, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Role = 'member' | 'maintainer' | 'admin';

interface ProviderRow {
    id: string;
    name: string;
    slug: string;
    issuer: string;
    enabled: boolean;
    allow_registration: boolean;
    has_secret: boolean;
    default_role: Role | null;
    default_organization_id: string | null;
}

interface OrganizationOption {
    id: string;
    name: string;
}

const props = defineProps<{
    providers: ProviderRow[];
    organizations: OrganizationOption[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'OIDC-Provider', href: '/admin/oidc' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const dialogOpen = ref(false);
const discoveryError = ref<string | null>(null);
const discovering = ref(false);

const form = useForm({
    name: '',
    slug: '',
    client_id: '',
    client_secret: '',
    issuer: '',
    authorization_endpoint: '',
    token_endpoint: '',
    userinfo_endpoint: '',
    jwks_uri: '',
    scopes: 'openid email profile',
    enabled: false,
    allow_registration: false,
    default_organization_id: '',
    default_role: 'member' as Role,
});

function submit() {
    form.transform((data) => ({
        ...data,
        userinfo_endpoint: data.userinfo_endpoint || null,
        default_organization_id: data.default_organization_id || null,
    })).post(route('admin.oidc.store'), {
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

/** Reads the XSRF-TOKEN cookie set by Laravel for the CSRF header. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function loadFromDiscovery() {
    discoveryError.value = null;

    if (!form.issuer) {
        discoveryError.value = 'Bitte zuerst eine Issuer-URL eingeben.';
        return;
    }

    discovering.value = true;

    try {
        const response = await fetch(route('admin.oidc.discover'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ issuer: form.issuer }),
        });

        const data = await response.json();

        if (!response.ok) {
            discoveryError.value = data?.message ?? 'Discovery ist fehlgeschlagen.';
            return;
        }

        form.issuer = data.issuer || form.issuer;
        form.authorization_endpoint = data.authorization_endpoint ?? '';
        form.token_endpoint = data.token_endpoint ?? '';
        form.userinfo_endpoint = data.userinfo_endpoint ?? '';
        form.jwks_uri = data.jwks_uri ?? '';
    } catch {
        discoveryError.value = 'Discovery ist fehlgeschlagen.';
    } finally {
        discovering.value = false;
    }
}

function destroyProvider(id: string) {
    router.delete(route('admin.oidc.destroy', id), {
        onBefore: () => confirm('OIDC-Provider wirklich löschen?'),
    });
}

const badgeClasses = (on: boolean) =>
    cn(
        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
        on
            ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
            : 'border-border bg-muted text-muted-foreground',
    );
</script>

<template>
    <Head title="OIDC-Provider" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">OIDC-Provider</h1>
                <Button @click="dialogOpen = true">
                    <Plus class="size-4" />
                    Provider hinzufügen
                </Button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Slug</th>
                            <th class="px-4 py-3 font-medium">Issuer</th>
                            <th class="px-4 py-3 font-medium">Aktiv</th>
                            <th class="px-4 py-3 font-medium">Registrierung</th>
                            <th class="px-4 py-3 font-medium">Secret</th>
                            <th class="px-4 py-3 font-medium">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="provider in props.providers"
                            :key="provider.id"
                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                        >
                            <td class="px-4 py-3">{{ provider.name }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ provider.slug }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ provider.issuer }}</td>
                            <td class="px-4 py-3">
                                <span :class="badgeClasses(provider.enabled)">{{ provider.enabled ? 'Ja' : 'Nein' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="badgeClasses(provider.allow_registration)">
                                    {{ provider.allow_registration ? 'Erlaubt' : 'Gesperrt' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="provider.has_secret"
                                    class="inline-flex items-center rounded-md border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                                >
                                    Gesetzt
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <Button variant="ghost" size="icon" @click="destroyProvider(provider.id)" aria-label="Provider löschen">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="props.providers.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">Noch keine OIDC-Provider angelegt.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>OIDC-Provider hinzufügen</DialogTitle>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="slug">Slug</Label>
                        <Input id="slug" v-model="form.slug" placeholder="keycloak" autocomplete="off" />
                        <InputError :message="form.errors.slug" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="client_id">Client-ID</Label>
                        <Input id="client_id" v-model="form.client_id" autocomplete="off" />
                        <InputError :message="form.errors.client_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="client_secret">Client-Secret</Label>
                        <Input id="client_secret" v-model="form.client_secret" type="password" autocomplete="off" />
                        <InputError :message="form.errors.client_secret" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="issuer">Issuer</Label>
                        <div class="flex gap-2">
                            <Input id="issuer" v-model="form.issuer" placeholder="https://idp.example.com" autocomplete="off" class="flex-1" />
                            <Button type="button" variant="outline" :disabled="discovering" @click="loadFromDiscovery">
                                <DownloadCloud class="size-4" />
                                Aus Discovery laden
                            </Button>
                        </div>
                        <p v-if="discoveryError" class="text-sm text-destructive">{{ discoveryError }}</p>
                        <InputError :message="form.errors.issuer" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="authorization_endpoint">Authorization-Endpoint</Label>
                        <Input id="authorization_endpoint" v-model="form.authorization_endpoint" autocomplete="off" />
                        <InputError :message="form.errors.authorization_endpoint" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token_endpoint">Token-Endpoint</Label>
                        <Input id="token_endpoint" v-model="form.token_endpoint" autocomplete="off" />
                        <InputError :message="form.errors.token_endpoint" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="userinfo_endpoint">Userinfo-Endpoint (optional)</Label>
                        <Input id="userinfo_endpoint" v-model="form.userinfo_endpoint" autocomplete="off" />
                        <InputError :message="form.errors.userinfo_endpoint" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="jwks_uri">JWKS-URI</Label>
                        <Input id="jwks_uri" v-model="form.jwks_uri" autocomplete="off" />
                        <InputError :message="form.errors.jwks_uri" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="scopes">Scopes</Label>
                        <Input id="scopes" v-model="form.scopes" placeholder="openid email profile" autocomplete="off" />
                        <InputError :message="form.errors.scopes" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="default_organization_id">Standard-Organisation (optional)</Label>
                        <SearchableSelect
                            id="default_organization_id"
                            v-model="form.default_organization_id"
                            :options="[{ value: '', label: 'Keine' }, ...props.organizations.map((o) => ({ value: o.id, label: o.name }))]"
                        />
                        <InputError :message="form.errors.default_organization_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="default_role">Standard-Rolle</Label>
                        <SearchableSelect
                            id="default_role"
                            v-model="form.default_role"
                            :options="[
                                { value: 'member', label: 'Member' },
                                { value: 'maintainer', label: 'Maintainer' },
                                { value: 'admin', label: 'Admin' },
                            ]"
                        />
                        <InputError :message="form.errors.default_role" />
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="enabled" v-model="form.enabled" type="checkbox" class="size-4 rounded border-input" />
                        <Label for="enabled">Aktiv</Label>
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="allow_registration" v-model="form.allow_registration" type="checkbox" class="size-4 rounded border-input" />
                        <Label for="allow_registration">Selbstregistrierung erlauben</Label>
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
