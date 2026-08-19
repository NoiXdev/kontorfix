<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import { computed, inject } from 'vue';
import { upstreamFormKey } from './upstreamForm';

interface GroupOption {
    id: string;
    name: string;
}

const props = defineProps<{
    groups: GroupOption[];
    mode: 'create' | 'edit';
    // Edit mode only: whether the upstream currently has a stored mirror token — enables
    // the "remove token" switch and swaps the input's placeholder. Never the token itself.
    hasAuth?: boolean;
}>();

// Provided by Create.vue / Edit.vue (see upstreamForm.ts) rather than passed as a prop: this
// form object is meant to be written into (`v-model="form.xxx"`), and an injected value is
// not subject to Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(upstreamFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via upstreamFormKey — see Create.vue / Edit.vue.');
}
const form = injectedForm;

// Upstream types are the registry types — from the single source of truth.
const { options: registryTypeOptions } = useRegistryTypes();
// `registryTypeOptions()` is generically `{ value: string; label: string }[]` (it's shared
// across call sites with different literal-union needs); `form.type` is the narrower
// `'composer' | 'npm' | 'python'`. This asserts the already-true invariant — the options
// always come from the registry-type enum — so `SearchableSelect`'s `v-model` lines up.
const upstreamTypeOptions = computed(() => registryTypeOptions() as { value: 'composer' | 'npm' | 'python'; label: string }[]);

const groupOptions = computed(() => props.groups.map((g) => ({ value: g.id, label: g.name })));

const policyOptions: { value: 'proxy' | 'strict'; label: string }[] = [
    { value: 'proxy', label: 'Proxy' },
    { value: 'strict', label: 'Strict' },
];
</script>

<template>
    <div class="grid gap-2">
        <Label for="group_id">Gruppe</Label>
        <SearchableSelect id="group_id" v-model="form.group_id" placeholder="Bitte wählen" :disabled="mode === 'edit'" :options="groupOptions" />
        <InputError :message="form.errors.group_id" />
    </div>

    <div class="grid gap-2">
        <Label for="type">Typ</Label>
        <SearchableSelect id="type" v-model="form.type" :options="upstreamTypeOptions" />
        <InputError :message="form.errors.type" />
    </div>

    <div class="grid gap-2">
        <Label for="url">URL</Label>
        <Input id="url" v-model="form.url" placeholder="https://repo.packagist.org" autocomplete="off" />
        <p class="text-xs text-muted-foreground">
            Zugangsdaten gehören in das Token-Feld, nicht in die URL. Eine URL der Form
            <code class="font-mono">https://user:passwort@host/…</code> funktioniert zwar, wird aber gegenüber Mitgliedern der Organisation
            ausgeblendet.
        </p>
        <InputError :message="form.errors.url" />
    </div>

    <div class="grid gap-2">
        <Label for="policy">Policy</Label>
        <SearchableSelect id="policy" v-model="form.policy" :options="policyOptions" />
        <InputError :message="form.errors.policy" />
    </div>

    <div class="grid gap-2">
        <Label for="auth_token">Auth-Token (optional)</Label>
        <Input
            id="auth_token"
            v-model="form.auth_token"
            type="password"
            autocomplete="off"
            :placeholder="hasAuth ? 'Leer lassen, um den bestehenden Token zu behalten' : 'für private Upstreams'"
        />
        <label v-if="hasAuth" class="flex items-center gap-2 text-sm text-muted-foreground">
            <Switch v-model="form.remove_auth_token" />
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
            class="flex w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
        />
        <!-- `form.transform()` submits this field under the server-side key `allowed_packages`
             (see UpstreamController), which isn't part of the form's own data shape, so
             `form.errors` doesn't statically know about it. -->
        <InputError :message="(form.errors as Record<string, string | undefined>).allowed_packages" />
    </div>
</template>
