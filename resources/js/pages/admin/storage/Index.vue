<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { PlugZap } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Driver = 'local' | 's3';

interface StorageSettings {
    driver: Driver;
    key: string | null;
    region: string | null;
    bucket: string | null;
    endpoint: string | null;
    url: string | null;
    use_path_style: boolean;
    has_secret: boolean;
}

const props = defineProps<{
    settings: StorageSettings;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Speicher', href: '/admin/storage' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const form = useForm({
    driver: props.settings.driver,
    key: props.settings.key ?? '',
    secret: '',
    region: props.settings.region ?? '',
    bucket: props.settings.bucket ?? '',
    endpoint: props.settings.endpoint ?? '',
    url: props.settings.url ?? '',
    use_path_style: props.settings.use_path_style,
});

const testing = ref(false);
const testResult = ref<{ ok: boolean; message: string } | null>(null);

function submit() {
    form.put(route('admin.storage.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.secret = '';
        },
    });
}

/** Liest das von Laravel gesetzte XSRF-TOKEN-Cookie für den CSRF-Header. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function testConnection() {
    testResult.value = null;
    testing.value = true;

    try {
        const response = await fetch(route('admin.storage.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            // Die aktuell eingegebenen Werte testen (nicht nur den Treiber) — leeres
            // Secret bedeutet serverseitig „bestehendes behalten".
            body: JSON.stringify({
                driver: form.driver,
                key: form.key,
                secret: form.secret,
                region: form.region,
                bucket: form.bucket,
                endpoint: form.endpoint,
                url: form.url,
                use_path_style: form.use_path_style,
            }),
        });

        const data = await response.json();

        testResult.value = {
            ok: response.ok && data?.ok === true,
            message: data?.message ?? 'Verbindungstest fehlgeschlagen.',
        };
    } catch {
        testResult.value = { ok: false, message: 'Verbindungstest fehlgeschlagen.' };
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <Head title="Speicher" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Speicher-Backend</h1>
            </div>

            <form class="max-w-2xl space-y-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="driver">Treiber</Label>
                    <select
                        id="driver"
                        v-model="form.driver"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        <option value="local">Lokal (Server-Dateisystem)</option>
                        <option value="s3">S3 / S3-kompatibel (z. B. MinIO)</option>
                    </select>
                    <InputError :message="form.errors.driver" />
                </div>

                <template v-if="form.driver === 's3'">
                    <div class="grid gap-2">
                        <Label for="key">Access-Key</Label>
                        <Input id="key" v-model="form.key" autocomplete="off" />
                        <InputError :message="form.errors.key" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label for="secret">Secret-Key</Label>
                            <span
                                v-if="props.settings.has_secret"
                                class="inline-flex items-center rounded-md border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                            >
                                Gesetzt
                            </span>
                        </div>
                        <Input id="secret" v-model="form.secret" type="password" autocomplete="off" placeholder="unverändert lassen" />
                        <InputError :message="form.errors.secret" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="region">Region</Label>
                        <Input id="region" v-model="form.region" placeholder="eu-central-1" autocomplete="off" />
                        <InputError :message="form.errors.region" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="bucket">Bucket</Label>
                        <Input id="bucket" v-model="form.bucket" autocomplete="off" />
                        <InputError :message="form.errors.bucket" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="endpoint">Endpoint (optional, S3-kompatibel)</Label>
                        <Input id="endpoint" v-model="form.endpoint" placeholder="https://minio.example.com" autocomplete="off" />
                        <InputError :message="form.errors.endpoint" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="url">Öffentliche URL (optional)</Label>
                        <Input id="url" v-model="form.url" placeholder="https://cdn.example.com" autocomplete="off" />
                        <InputError :message="form.errors.url" />
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="use_path_style" v-model="form.use_path_style" type="checkbox" class="size-4 rounded border-input" />
                        <Label for="use_path_style">Path-Style-Endpoint verwenden</Label>
                    </div>
                </template>

                <div class="flex items-center gap-2 pt-2">
                    <Button type="submit" :disabled="form.processing">Speichern</Button>
                    <Button type="button" variant="outline" :disabled="testing" @click="testConnection">
                        <PlugZap class="size-4" />
                        Verbindung testen
                    </Button>
                </div>

                <p
                    v-if="testResult"
                    :class="[
                        'text-sm',
                        testResult.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive',
                    ]"
                >
                    {{ testResult.message }}
                </p>
            </form>
        </div>
    </AppLayout>
</template>
