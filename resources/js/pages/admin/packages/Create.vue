<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, provide, ref } from 'vue';
import Form from './Form.vue';
import {
    canChooseSourceMode,
    isGitMode as computeIsGitMode,
    packageFormKey,
    type GitCredentialOption,
    type GroupOption,
    type PackageFormData,
    type ProbeResult,
    type SourceModeMap,
} from './packageForm';

const props = defineProps<{
    groups: GroupOption[];
    registryTypes: string[];
    gitCredentials: GitCredentialOption[];
    sourceModes: SourceModeMap;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pakete', href: route('admin.packages.index') },
    { title: 'Hinzufügen', href: route('admin.packages.create') },
];

const form = useForm<PackageFormData>({
    type: 'composer',
    source_mode: 'publish',
    name: '',
    repository_url: '',
    is_private: false,
    repository_token: '',
    git_credential_id: '',
    group_ids: [],
});

provide(packageFormKey, form);

// Shared with Form.vue via v-model (see packageForm.ts) — the probe button lives there, but
// the submit button's disabled state and the probe-first gate below both need to read it too.
const probeResult = ref<ProbeResult | null>(null);

const isGitMode = computed(() => computeIsGitMode(props.sourceModes, form.type, form.source_mode));

// A package with no registry is invisible to its own creator and burns its name
// instance-wide, so at least one is mandatory. The server enforces this (StorePackageRequest);
// this only spares the operator the round trip.
const canSubmit = computed(() => form.name.trim() !== '' && form.group_ids.length > 0 && (!isGitMode.value || probeResult.value?.ok === true));

function submit() {
    // Enforce the probe-first gate here too — not only via the disabled button — so a
    // git-sourced package (Composer, or an npm/Python git mirror) can never be created by
    // pressing Enter before „Prüfen" succeeded.
    if (!canSubmit.value) {
        return;
    }
    form
        // The source selector is only rendered when canChooseSourceMode is true (Composer
        // hides it — it has exactly one allowed mode). Drop the field entirely rather than
        // send the default 'publish' for a type that doesn't allow it: the server rejects any
        // explicitly submitted mode a type doesn't allow (StorePackageRequest /
        // PackageSourceMode::allowedFor), and Composer would otherwise 422 on a field the
        // user never had a chance to touch.
        .transform((data) => (canChooseSourceMode(props.sourceModes, data.type) ? data : { ...data, source_mode: undefined }))
        .post(route('admin.packages.store'));
}
</script>

<template>
    <Head title="Paket hinzufügen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="mx-auto flex w-full max-w-2xl flex-col gap-4">
                <h1 class="text-xl font-semibold">Paket hinzufügen</h1>

                <form class="space-y-4" @submit.prevent="submit">
                    <Form
                        :groups="props.groups"
                        :registry-types="props.registryTypes"
                        :git-credentials="props.gitCredentials"
                        :source-modes="props.sourceModes"
                        v-model:probe-result="probeResult"
                    />

                    <div class="flex justify-end gap-2">
                        <Button as-child variant="outline">
                            <Link :href="route('admin.packages.index')">Abbrechen</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing || !canSubmit">Anlegen</Button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
