<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';
import { router, type InertiaForm } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface OrganizationOption {
    id: string;
    name: string;
}

interface Membership {
    id: string;
    name: string;
    role: string;
}

// `password` is optional because the edit form never carries it — the edit dialog never
// managed passwords, only create did, and moving the markup must not add a field the
// controller never validated for updates.
export interface UserFormData {
    name: string;
    email: string;
    organization_id: string;
    role: string;
    is_super_admin: boolean;
    password?: string;
}

const props = defineProps<{
    form: InertiaForm<UserFormData>;
    organizations: OrganizationOption[];
    roleOptions: { value: string; label: string }[];
    mode: 'create' | 'edit';
    // Edit mode only: identity of the record being edited and its current additional-org
    // memberships, needed for the "Weitere Organisationen" attach/detach section.
    userId?: string;
    memberships?: Membership[];
    // Edit mode only: the record's home organization as loaded from the server, used to
    // compute which organizations are still assignable. Deliberately not `form.organization_id`
    // — the dialog this replaces froze the same value at open time, so an in-progress (unsaved)
    // change to the home-org dropdown does not affect the assignable list, matching prior behaviour.
    homeOrganizationId?: string | null;
}>();

const orgOptions = computed(() => props.organizations.map((o) => ({ value: o.id, label: o.name })));

// --- Create-only: invite vs. set-password-directly ---
const accessMode = defineModel<'invite' | 'password'>('accessMode', { default: 'invite' });

function setAccessMode(next: 'invite' | 'password') {
    accessMode.value = next;
    if (next === 'invite') {
        props.form.password = '';
    }
}

// --- Edit-only: additional-organization memberships ---
const addOrgId = ref('');
const roleForNewMembership = ref('member');

const assignableOrgs = computed(() => {
    const taken = new Set([props.homeOrganizationId, ...(props.memberships ?? []).map((m) => m.id)]);
    return props.organizations.filter((o) => !taken.has(o.id));
});

function attachOrg() {
    if (!props.userId || !addOrgId.value) {
        return;
    }
    router.post(
        route('admin.users.organizations.store', props.userId),
        { organization_id: addOrgId.value, role: roleForNewMembership.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                addOrgId.value = '';
                roleForNewMembership.value = 'member';
            },
        },
    );
}

function detachOrg(orgId: string) {
    if (!props.userId) {
        return;
    }
    router.delete(route('admin.users.organizations.destroy', [props.userId, orgId]), { preserveScroll: true });
}
</script>

<template>
    <div class="grid gap-2">
        <Label for="name">Name</Label>
        <Input id="name" v-model="form.name" placeholder="Vor- und Nachname" autocomplete="off" />
        <InputError :message="form.errors.name" />
    </div>

    <div class="grid gap-2">
        <Label for="email">E-Mail</Label>
        <Input id="email" type="email" v-model="form.email" placeholder="name@kunde.de" autocomplete="off" />
        <InputError :message="form.errors.email" />
    </div>

    <div class="grid gap-2">
        <Label for="organization_id">{{ mode === 'create' ? 'Organisation' : 'Heim-Organisation' }}</Label>
        <SearchableSelect id="organization_id" v-model="form.organization_id" :options="orgOptions" placeholder="Bitte wählen" />
        <InputError :message="form.errors.organization_id" />
    </div>

    <div class="grid gap-2">
        <Label for="role">Rolle</Label>
        <SearchableSelect id="role" v-model="form.role" :options="roleOptions" />
        <p class="text-xs text-muted-foreground">Rolle innerhalb der Heim-Organisation.</p>
        <InputError :message="form.errors.role" />
        <InputError v-if="mode === 'edit'" :message="(form.errors as Record<string, string | undefined>).user" />
    </div>

    <label class="flex items-start gap-2 rounded-md border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
        <Switch v-model="form.is_super_admin" class="mt-1" />
        <span>
            Super-Admin
            <span class="block text-xs text-muted-foreground"> Voller Zugriff auf alle Organisationen und die Instanz-Verwaltung. </span>
        </span>
    </label>
    <InputError :message="form.errors.is_super_admin" />

    <template v-if="mode === 'create'">
        <div class="grid gap-2">
            <Label>Zugang</Label>
            <div class="flex flex-col gap-2">
                <label class="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="mode"
                        value="invite"
                        :checked="accessMode === 'invite'"
                        @change="setAccessMode('invite')"
                        class="mt-1"
                    />
                    <span>
                        Einladung per E-Mail senden
                        <span class="block text-xs text-muted-foreground">
                            Der Nutzer bekommt eine E-Mail mit einem Link, um sein Passwort selbst zu setzen.
                        </span>
                    </span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input
                        type="radio"
                        name="mode"
                        value="password"
                        :checked="accessMode === 'password'"
                        @change="setAccessMode('password')"
                        class="mt-1"
                    />
                    <span>Passwort direkt setzen</span>
                </label>
            </div>
        </div>

        <div v-if="accessMode === 'password'" class="grid gap-2">
            <Label for="password">Passwort</Label>
            <Input id="password" type="password" v-model="form.password" autocomplete="new-password" />
            <InputError :message="form.errors.password" />
        </div>
    </template>

    <div v-if="mode === 'edit'" class="grid gap-2 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
        <Label>Weitere Organisationen</Label>
        <p class="text-xs text-muted-foreground">Zusätzlicher Zugriff mit eigener Rolle je Organisation.</p>
        <div class="flex flex-wrap gap-1">
            <span
                v-for="m in memberships ?? []"
                :key="m.id"
                class="inline-flex items-center gap-1 rounded-md border border-copper/30 bg-copper/10 px-2 py-0.5 text-xs text-copper-hi"
            >
                {{ m.name }}
                <span class="rounded bg-copper/20 px-1 text-[10px] tracking-wide uppercase">{{ m.role }}</span>
                <button type="button" class="hover:text-destructive" aria-label="Entfernen" @click="detachOrg(m.id)">
                    <X class="size-3" />
                </button>
            </span>
            <span v-if="(memberships ?? []).length === 0" class="text-xs text-muted-foreground">Keine weiteren.</span>
        </div>
        <!-- Wraps rather than sitting in one fixed row: this is the row that used to be cut
             off by the dialog's fixed height, and the whole reason this form moved onto its
             own page. -->
        <div class="flex flex-wrap items-center gap-2">
            <SearchableSelect
                v-model="addOrgId"
                class="max-w-full flex-1 sm:min-w-64"
                :options="assignableOrgs.map((o) => ({ value: o.id, label: o.name }))"
                placeholder="Organisation hinzufügen …"
            />
            <SearchableSelect v-model="roleForNewMembership" class="w-40" :options="roleOptions" />
            <Button type="button" variant="outline" :disabled="!addOrgId" @click="attachOrg">Hinzufügen</Button>
        </div>
    </div>
</template>
