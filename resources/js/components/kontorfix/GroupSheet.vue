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

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    close: [];
}>();

const form = useForm({
    name: '',
    slug: '',
    public: false,
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

function onCreateNewPackage(query: string) {
    // v0.1: das Anlegen neuer Pakete läuft weiterhin über /admin/packages.
    // Wir navigieren nicht automatisch weg, um den Zuweisungs-Flow nicht zu unterbrechen —
    // die Zeile signalisiert dem Nutzer nur, dass der Pool hier noch leer ist.
    console.info(`Neues Paket anlegen: ${query}`);
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

                <div class="flex items-center gap-2">
                    <Checkbox id="group-public" v-model:model-value="form.public" />
                    <Label for="group-public" class="font-normal">Öffentlich zugänglich</Label>
                </div>
                <InputError :message="form.errors.public" />

                <div class="grid gap-2">
                    <Label>Pakete</Label>
                    <PackagePicker v-model="selected" @create-new="onCreateNewPackage" />
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
