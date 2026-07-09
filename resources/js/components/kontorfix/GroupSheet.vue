<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PackagePicker from '@/components/kontorfix/PackagePicker.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
                <SheetTitle>Neue Gruppe</SheetTitle>
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
                    <select
                        id="group-organization"
                        v-model="form.organization_id"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        <option value="">Standard (Betreiber)</option>
                        <option v-for="org in props.organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
                    </select>
                    <InputError :message="form.errors.organization_id" />
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox id="group-public" v-model:model-value="form.public" />
                    <Label for="group-public" class="font-normal">Öffentlich zugänglich</Label>
                </div>
                <InputError :message="form.errors.public" />

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
