<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';
import { DownloadCloud } from 'lucide-vue-next';
import { inject, ref } from 'vue';
import { oidcFormKey } from './oidcForm';

interface OrganizationOption {
    id: string;
    name: string;
}

const props = defineProps<{
    organizations: OrganizationOption[];
}>();

// Provided by Create.vue (see oidcForm.ts) rather than passed as a prop: this form object is
// meant to be written into (`v-model="form.xxx"`), and an injected value is not subject to
// Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(oidcFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via oidcFormKey — see Create.vue.');
}
const form = injectedForm;

const discoveryError = ref<string | null>(null);
const discovering = ref(false);

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
</script>

<template>
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
        <Switch id="enabled" v-model="form.enabled" />
        <Label for="enabled">Aktiv</Label>
    </div>

    <div class="flex items-center gap-2">
        <Switch id="allow_registration" v-model="form.allow_registration" />
        <Label for="allow_registration">Selbstregistrierung erlauben</Label>
    </div>

    <label class="flex items-start gap-2 text-sm">
        <Switch id="trusts_email_claim" v-model="form.trusts_email_claim" class="mt-1" />
        <span>
            E-Mail-Zusicherung vertrauen
            <span class="block text-xs text-muted-foreground">
                Erlaubt diesem Provider, ein bestehendes Konto allein anhand einer als verifiziert
                zugesicherten E-Mail-Adresse zu beanspruchen und automatisch damit zu verknüpfen. Nur für
                Provider aktivieren, denen bei der E-Mail-Verifizierung vertraut wird — ein weniger
                vertrauenswürdiger Provider könnte sonst eine fremde Adresse behaupten und sich so als
                dieses Konto anmelden.
            </span>
        </span>
    </label>
</template>
