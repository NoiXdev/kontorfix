<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PackagePicker from '@/components/kontorfix/PackagePicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Must match `PackagePicker.vue`'s own (correct, wider) local `Pkg` — this component only
// ever receives package objects from `<PackagePicker v-model="selected">` below, and that
// component's search can return Python and Docker packages too. This file never reads
// `.type` (only `.id`, for `form.package_ids`), so a missing member here is never a live
// behaviour bug — strictVModel is what catches the two interfaces having silently drifted
// apart, most recently when Docker joined as a fourth type and this copy was not updated.
interface Pkg {
    id: string;
    name: string;
    type: 'composer' | 'npm' | 'python' | 'docker';
    shared: boolean;
}

interface OrgOption {
    id: string;
    name: string;
    // First segment of every registry URL, so the preview below can name it once the
    // operator has picked an owner.
    slug: string;
    // Whether this organization's customer portal exists at all — see
    // Organization::portal_enabled's docblock. Drives the hint under the "Im Kundenportal
    // anzeigen" switch below once this org is the picked (or default) owner.
    portal_enabled: boolean;
}

const props = withDefaults(
    defineProps<{
        organizations?: OrgOption[];
        // The registry URL form with both slugs left open, from RegistryUrl::template().
        // This sheet substitutes into it — it never assembles a registry URL itself.
        urlTemplate?: string;
        // What "Standard (Betreiber)" (the SearchableSelect's empty option) resolves to on
        // submit — the same organization ScopesToAdministeredOrgs::resolveCreationOrg()
        // picks: the active scope, else the caller's home organization. Needed because that
        // resolution happens server-side and cannot be recomputed from `organizations` alone.
        defaultOrganizationId?: string | null;
        defaultOrganizationPortalEnabled?: boolean;
        // Whether the current caller may open admin.organizations.show — customer/
        // organization management is super-admin only (see EnsureSuperAdmin).
        canManageOrganization?: boolean;
    }>(),
    {
        organizations: () => [],
        urlTemplate: '',
        defaultOrganizationId: null,
        defaultOrganizationPortalEnabled: true,
        canManageOrganization: false,
    },
);

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    close: [];
}>();

const form = useForm({
    name: '',
    slug: '',
    public: false,
    portal_enabled: true,
    organization_id: '',
    package_ids: [] as string[],
});

const origin = window.location.origin;

// The organization segment is only known once an owner is picked; "Standard (Betreiber)"
// leaves the choice to the server (active scope, else the user's own organization), so the
// preview names the segment instead of guessing a slug for it.
const orgSlug = computed(() => props.organizations.find((o) => o.id === form.organization_id)?.slug ?? '<organisation>');

const urlFormLabel = computed(() => props.urlTemplate.replace('{organization}', '<organisation>').replace('{registry}', '<slug>'));

const urlPreview = computed(() => origin + props.urlTemplate.replace('{organization}', orgSlug.value).replace('{registry}', form.slug || '…'));

// The owner this registry will actually belong to once submitted: the explicitly picked
// organization, or — for "Standard (Betreiber)" — whatever resolveCreationOrg() resolves to
// server-side (see `defaultOrganizationId`/`defaultOrganizationPortalEnabled` above). Reading
// the picked option from `organizations` rather than trusting the SearchableSelect's own
// state keeps this in one place with `orgSlug` above.
const selectedOrganization = computed(() => props.organizations.find((o) => o.id === form.organization_id));

const ownerPortalEnabled = computed(() =>
    form.organization_id ? (selectedOrganization.value?.portal_enabled ?? true) : props.defaultOrganizationPortalEnabled,
);

const ownerOrganizationId = computed(() => (form.organization_id ? form.organization_id : props.defaultOrganizationId));

const selected = ref<Pkg[]>([]);
const slugTouched = ref(false);

watch(
    () => form.name,
    (name) => {
        if (slugTouched.value) {
            return;
        }
        form.slug = name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    },
);

function onSlugInput() {
    slugTouched.value = true;
}

function resetForm() {
    form.reset();
    form.clearErrors();
    slugTouched.value = false;
    selected.value = [];
}

function submit() {
    form.package_ids = selected.value.map((p) => p.id);
    form.post(route('admin.groups.store'), {
        onSuccess: () => {
            emit('close');
            open.value = false;
            resetForm();
        },
    });
}

function close() {
    open.value = false;
    emit('close');
    resetForm();
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent side="right" class="w-full overflow-y-auto sm:max-w-lg" @escape-key-down="close" @pointer-down-outside="close">
            <SheetHeader>
                <SheetTitle>Neue Registry (Gruppe)</SheetTitle>
                <p class="text-sm text-muted-foreground">
                    Jede Gruppe ist eine Registry mit eigenem <span class="font-mono">{{ urlFormLabel }}</span
                    >-Endpunkt.
                </p>
            </SheetHeader>

            <form class="mt-6 space-y-5" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="group-name">Name</Label>
                    <Input id="group-name" v-model="form.name" placeholder="Kadenz GmbH" autocomplete="off" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="group-slug">Slug</Label>
                    <Input id="group-slug" v-model="form.slug" placeholder="kadenz" autocomplete="off" @input="onSlugInput" />
                    <p class="text-sm text-muted-foreground">
                        Erreichbar unter <span class="font-mono">{{ urlPreview }}</span>
                    </p>
                    <InputError :message="form.errors.slug" />
                </div>

                <div class="grid gap-2">
                    <Label for="group-organization">Kunde / Organisation</Label>
                    <SearchableSelect
                        id="group-organization"
                        v-model="form.organization_id"
                        :options="[{ value: '', label: 'Standard (Betreiber)' }, ...props.organizations.map((o) => ({ value: o.id, label: o.name }))]"
                    />
                    <InputError :message="form.errors.organization_id" />
                </div>

                <div class="flex items-center gap-2">
                    <Switch id="group-public" v-model="form.public" />
                    <Label for="group-public" class="font-normal">Öffentlich zugänglich</Label>
                </div>
                <InputError :message="form.errors.public" />

                <div class="flex items-start gap-2">
                    <Switch id="group-portal" v-model="form.portal_enabled" class="mt-1" />
                    <Label for="group-portal" class="font-normal">
                        Im Kundenportal als Registry anzeigen
                        <span class="block text-xs text-muted-foreground">
                            Deaktivieren für eine reine Paketsammlung, die anderen Registries derselben Organisation zugewiesen wird.
                        </span>
                    </Label>
                </div>
                <InputError :message="form.errors.portal_enabled" />

                <!-- The switch above only controls whether this registry appears inside the customer
                     portal — it says nothing about whether the OWNING organization's portal exists at
                     all. Reflects whichever organization the registry will actually belong to: the
                     picked owner, or — for "Standard (Betreiber)" — the server's own resolution. -->
                <p
                    v-if="!ownerPortalEnabled"
                    class="inline-flex w-fit items-start gap-1 rounded-md border border-border bg-muted px-2 py-1 text-xs text-muted-foreground"
                >
                    <span>
                        Das Kundenportal dieser Organisation ist deaktiviert — diese Registry erscheint dort erst, wenn es aktiviert wird.
                        <Link
                            v-if="canManageOrganization && ownerOrganizationId"
                            :href="route('admin.organizations.show', ownerOrganizationId)"
                            class="underline underline-offset-2 hover:text-foreground"
                        >
                            Organisation öffnen
                        </Link>
                    </span>
                </p>

                <div class="grid gap-2">
                    <Label>Pakete</Label>
                    <PackagePicker v-model="selected" />
                    <InputError :message="form.errors.package_ids" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="outline" @click="close">Abbrechen</Button>
                    <Button type="submit" :disabled="form.processing">Anlegen</Button>
                </div>
            </form>
        </SheetContent>
    </Sheet>
</template>
