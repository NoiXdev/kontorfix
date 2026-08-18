<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PackagePicker from '@/components/kontorfix/PackagePicker.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

interface Pkg {
    id: string;
    name: string;
    type: 'composer' | 'npm';
}

interface OrgOption {
    id: string;
    name: string;
}

const props = withDefaults(
    defineProps<{
        organizations?: OrgOption[];
    }>(),
    { organizations: () => [] },
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
                    Jede Gruppe ist eine Registry mit eigenem <span class="font-mono">/r/&lt;slug&gt;</span>-Endpunkt.
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
                        Erreichbar unter <span class="font-mono">{{ origin }}/r/{{ form.slug || '…' }}</span>
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
                    <Checkbox id="group-public" v-model:model-value="form.public" />
                    <Label for="group-public" class="font-normal">Öffentlich zugänglich</Label>
                </div>
                <InputError :message="form.errors.public" />

                <div class="flex items-start gap-2">
                    <Checkbox id="group-portal" v-model:model-value="form.portal_enabled" class="mt-1" />
                    <Label for="group-portal" class="font-normal">
                        Im Kundenportal als Registry anzeigen
                        <span class="block text-xs text-muted-foreground">
                            Deaktivieren für eine reine Paketsammlung, die anderen Registries derselben Organisation zugewiesen wird.
                        </span>
                    </Label>
                </div>
                <InputError :message="form.errors.portal_enabled" />

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
