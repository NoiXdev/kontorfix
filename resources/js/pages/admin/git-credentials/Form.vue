<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';
import { computed, inject } from 'vue';
import { gitCredentialFormKey } from './gitCredentialForm';

interface OrganizationOption {
    id: string;
    name: string;
    is_operator: boolean;
}

interface ShareTargetOption {
    id: string;
    name: string;
}

interface ProviderOption {
    value: string;
    label: string;
    default_host: string | null;
}

const props = defineProps<{
    organizations: OrganizationOption[];
    // Only ever non-empty when the currently targeted organization (see targetIsOperator
    // below) actually is the operator organization — see GitCredentialController.
    shareableOrganizations: ShareTargetOption[];
    providers: ProviderOption[];
    mode: 'create' | 'edit';
}>();

// Provided by Create.vue / Edit.vue (see gitCredentialForm.ts) rather than passed as a prop:
// this form object is meant to be written into (`v-model="form.xxx"`), and an injected value
// is not subject to Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(gitCredentialFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via gitCredentialFormKey — see Create.vue / Edit.vue.');
}
const form = injectedForm;

const providerOptions = computed(() => props.providers.map((p) => ({ value: p.value, label: p.label })));
const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

// A credential's token is only ever sent to this host. Known providers prefill it;
// self-hosted ("generic") installations must name their host explicitly.
const defaultHost = computed(() => props.providers.find((p) => p.value === form.provider)?.default_host ?? null);
const hostPlaceholder = computed(() => defaultHost.value ?? 'z. B. git.example.com');

// The organization picker only renders while creating (never editing, where the credential's
// organization is fixed and the field isn't shown), so this always reads back a plain
// string — the fallback here never actually fires.
const createOrgId = computed({
    get: () => form.organization_id ?? '',
    set: (value: string) => (form.organization_id = value),
});

// Sharing only ever applies to an operator-owned credential. `form.organization_id` names
// the targeted organization in both modes: it changes live while creating (the picker
// above), and is fixed to the credential's own organization while editing (there is no
// picker then) — either way this reads the right answer without a separate prop.
const targetIsOperator = computed(() => props.organizations.find((o) => o.id === form.organization_id)?.is_operator ?? false);

// Sharing to the organization that already owns the credential grants nothing (it can
// already use its own token) — filtered out so the list only ever offers organizations
// the toggle would actually change something for.
const shareTargets = computed(() => props.shareableOrganizations.filter((o) => o.id !== form.organization_id));

function toggleShare(organizationId: string, checked: boolean) {
    form.shared_organization_ids = checked
        ? [...form.shared_organization_ids, organizationId]
        : form.shared_organization_ids.filter((id) => id !== organizationId);
}
</script>

<template>
    <div class="grid gap-2">
        <Label for="cred_name">Name</Label>
        <Input id="cred_name" v-model="form.name" placeholder="z. B. GitHub Deploy" autocomplete="off" />
        <InputError :message="form.errors.name" />
    </div>

    <div v-if="mode === 'create' && orgOptions.length > 1" class="grid gap-2">
        <Label for="cred_org">Organisation</Label>
        <SearchableSelect id="cred_org" v-model="createOrgId" :options="orgOptions" />
        <InputError :message="form.errors.organization_id" />
    </div>

    <div class="grid gap-2">
        <Label for="cred_provider">Provider</Label>
        <SearchableSelect id="cred_provider" v-model="form.provider" :options="providerOptions" />
        <InputError :message="form.errors.provider" />
    </div>

    <div class="grid gap-2">
        <Label for="cred_host">Host</Label>
        <Input id="cred_host" v-model="form.host" :placeholder="hostPlaceholder" autocomplete="off" />
        <p class="text-xs text-muted-foreground">Der Token wird ausschliesslich an diesen Host gesendet.</p>
        <InputError :message="form.errors.host" />
    </div>

    <div class="grid gap-2">
        <Label for="cred_username">Benutzername <span class="text-muted-foreground">(optional)</span></Label>
        <Input id="cred_username" v-model="form.username" placeholder="leer = Provider-Standard" autocomplete="off" />
        <InputError :message="form.errors.username" />
    </div>

    <div class="grid gap-2">
        <Label for="cred_token">Token{{ mode === 'edit' ? ' (leer lassen = unverändert)' : '' }}</Label>
        <Input id="cred_token" v-model="form.token" type="password" placeholder="ghp_… / glpat-… / …" autocomplete="off" class="font-mono" />
        <InputError :message="form.errors.token" />
    </div>

    <!-- Sharing only ever applies to a credential owned by the operator organization —
         a customer's own token cannot be shared with other customers at all. -->
    <div v-if="targetIsOperator" class="grid gap-3 rounded-md border border-input p-3">
        <label class="flex items-start gap-2 text-sm">
            <Switch v-model="form.is_global" class="mt-1" />
            <span>
                Für alle Organisationen freigeben
                <span class="block text-xs text-muted-foreground">
                    Global freigegebene Tokens kann jede Organisation ihren eigenen Paketen zuweisen — nur lesend, bearbeiten kann sie
                    weiterhin ausschliesslich der Betreiber.
                </span>
            </span>
        </label>
        <InputError :message="form.errors.is_global" />

        <div v-if="!form.is_global" class="grid gap-2">
            <Label>Freigegeben für</Label>
            <div class="max-h-40 space-y-2 overflow-y-auto rounded-md border border-input p-3">
                <div v-for="org in shareTargets" :key="org.id" class="flex items-center gap-2">
                    <Checkbox
                        :id="`share-${org.id}`"
                        :checked="form.shared_organization_ids.includes(org.id)"
                        @update:checked="(checked) => toggleShare(org.id, checked === true)"
                    />
                    <Label :for="`share-${org.id}`" class="font-normal">{{ org.name }}</Label>
                </div>
                <p v-if="shareTargets.length === 0" class="text-sm text-muted-foreground">Keine weiteren Organisationen vorhanden.</p>
            </div>
            <InputError :message="form.errors.shared_organization_ids" />
        </div>
    </div>
</template>
