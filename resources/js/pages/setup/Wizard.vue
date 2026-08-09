<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, LoaderCircle, Send } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    appName: string;
    locked?: boolean;
    defaults: {
        from_address: string | null;
        from_name: string | null;
    };
}>();

// When a setup token is configured, the wizard stays locked until it is presented.
const tokenInput = ref('');

function unlock() {
    if (tokenInput.value.trim() === '') {
        return;
    }
    // POST, never a query parameter: the token grants the whole instance, and a URL is
    // copied into proxy logs, APM traces and the browser's own history. The server
    // verifies it and remembers the result in the session.
    router.post(route('setup.unlock'), { token: tokenInput.value.trim() }, { preserveState: false });
}

const form = useForm({
    admin_name: '',
    admin_email: '',
    admin_password: '',
    admin_password_confirmation: '',

    organization_name: '',
    registry_name: '',
    registry_slug: '',
    registry_public: false,

    mailer: 'log' as 'log' | 'smtp' | 'postal',
    from_address: props.defaults.from_address ?? '',
    from_name: props.defaults.from_name ?? props.appName,
    smtp_host: '',
    smtp_port: 587,
    smtp_username: '',
    smtp_password: '',
    smtp_encryption: 'tls' as 'tls' | 'ssl' | '',
    postal_domain: '',
    postal_key: '',

    storage_driver: 'local' as 'local' | 's3',
    storage_key: '',
    storage_secret: '',
    storage_region: '',
    storage_bucket: '',
    storage_endpoint: '',
    storage_url: '',
    storage_use_path_style: false,
});

const steps = [
    { title: 'Administrator', fields: ['admin_name', 'admin_email', 'admin_password'] },
    { title: 'Organisation & Registry', fields: ['organization_name', 'registry_name', 'registry_slug'] },
    {
        title: 'E-Mail',
        fields: ['mailer', 'from_address', 'from_name', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'postal_domain', 'postal_key'],
    },
    {
        title: 'Speicher',
        fields: ['storage_driver', 'storage_key', 'storage_secret', 'storage_region', 'storage_bucket', 'storage_endpoint', 'storage_url'],
    },
] as const;

const step = ref(0);
const isLastStep = computed(() => step.value === steps.length - 1);

// Server-side validation is authoritative, so when it rejects something the wizard
// has to jump back to the step that actually owns the offending field — otherwise the
// error renders on a panel the user cannot see.
watch(
    () => form.errors,
    (errors) => {
        const keys = Object.keys(errors);

        if (keys.length === 0) {
            return;
        }

        const firstBadStep = steps.findIndex((s) => s.fields.some((f) => keys.includes(f)));

        if (firstBadStep !== -1) {
            step.value = firstBadStep;
        }
    },
);

/** Derives the registry slug from its name until the user edits the slug by hand. */
const slugTouched = ref(false);

watch(
    () => form.registry_name,
    (name) => {
        if (!slugTouched.value) {
            form.registry_slug = name
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
    },
);

const testRecipient = ref('');
const testingMail = ref(false);
const mailTestResult = ref<{ ok: boolean; message: string } | null>(null);

/** Reads the XSRF-TOKEN cookie set by Laravel for the CSRF header. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function sendTestMail() {
    mailTestResult.value = null;
    testingMail.value = true;

    try {
        const response = await fetch(route('setup.mail-test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                mailer: form.mailer,
                from_address: form.from_address,
                from_name: form.from_name,
                smtp_host: form.smtp_host,
                smtp_port: form.smtp_port,
                smtp_username: form.smtp_username,
                smtp_password: form.smtp_password,
                smtp_encryption: form.smtp_encryption,
                postal_domain: form.postal_domain,
                postal_key: form.postal_key,
                recipient: testRecipient.value,
            }),
        });

        const data = await response.json();

        mailTestResult.value = {
            ok: response.ok && data?.ok === true,
            // A 422 carries the field errors rather than a `message`, so surface those.
            message: data?.message ?? Object.values(data?.errors ?? {}).flat().join(' ') ?? 'Testmail fehlgeschlagen.',
        };
    } catch {
        mailTestResult.value = { ok: false, message: 'Testmail fehlgeschlagen.' };
    } finally {
        testingMail.value = false;
    }
}

function submit() {
    form.post(route('setup.store'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Einrichtung" />

    <div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
        <div class="w-full max-w-2xl">
            <div class="mb-8 flex flex-col items-center gap-3">
                <AppLogoIcon class="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                <h1 class="text-xl font-medium">{{ appName }} einrichten</h1>
                <p class="text-center text-sm text-muted-foreground">
                    Diese Instanz hat noch kein Benutzerkonto. Lege jetzt den Administrator an.
                </p>
            </div>

            <!-- Locked: a setup token is configured and hasn't been presented yet. -->
            <div v-if="props.locked" class="space-y-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <div>
                    <h2 class="text-sm font-medium">Setup-Token erforderlich</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Aus Sicherheitsgründen ist die Einrichtung durch ein Token geschützt. Es steht in den
                        Startup-Logs des Containers (Zeile „FIRST-RUN SETUP TOKEN"). Bei jedem App-Start wird ein
                        neues Token erzeugt.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="setup_token">Token</Label>
                    <Input id="setup_token" v-model="tokenInput" autocomplete="off" placeholder="aus den Startup-Logs" @keyup.enter="unlock" />
                </div>
                <Button type="button" :disabled="!tokenInput.trim()" @click="unlock">Entsperren</Button>
            </div>

            <!-- Step indicator -->
            <ol v-if="!props.locked" class="mb-6 flex items-center justify-between gap-2">
                <li v-for="(s, i) in steps" :key="s.title" class="flex flex-1 items-center gap-2">
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium"
                        :class="
                            i < step
                                ? 'border-verdigris/40 bg-verdigris/15 text-verdigris'
                                : i === step
                                  ? 'border-copper/40 bg-copper/15 text-copper-hi'
                                  : 'border-input text-muted-foreground'
                        "
                    >
                        <Check v-if="i < step" class="size-3.5" />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="hidden text-sm sm:inline" :class="i === step ? 'font-medium' : 'text-muted-foreground'">{{ s.title }}</span>
                </li>
            </ol>

            <form v-if="!props.locked" class="space-y-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border" @submit.prevent="submit">
                <!-- Submission errors are otherwise invisible when they land on a field
                     of a hidden conditional block, so surface every message here. -->
                <div
                    v-if="form.hasErrors"
                    class="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                >
                    <p class="font-medium">Die Einrichtung konnte nicht abgeschlossen werden.</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5">
                        <li v-for="(message, key) in form.errors" :key="key">{{ message }}</li>
                    </ul>
                </div>

                <!-- Step 1: administrator -->
                <template v-if="step === 0">
                    <div class="grid gap-2">
                        <Label for="admin_name">Name</Label>
                        <Input id="admin_name" v-model="form.admin_name" autocomplete="name" autofocus />
                        <InputError :message="form.errors.admin_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="admin_email">E-Mail</Label>
                        <Input id="admin_email" v-model="form.admin_email" type="email" autocomplete="username" />
                        <InputError :message="form.errors.admin_email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="admin_password">Passwort</Label>
                        <Input id="admin_password" v-model="form.admin_password" type="password" autocomplete="new-password" />
                        <InputError :message="form.errors.admin_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="admin_password_confirmation">Passwort bestätigen</Label>
                        <Input
                            id="admin_password_confirmation"
                            v-model="form.admin_password_confirmation"
                            type="password"
                            autocomplete="new-password"
                        />
                        <InputError :message="form.errors.admin_password_confirmation" />
                    </div>
                </template>

                <!-- Step 2: organization and first registry -->
                <template v-if="step === 1">
                    <div class="grid gap-2">
                        <Label for="organization_name">Organisation</Label>
                        <Input id="organization_name" v-model="form.organization_name" placeholder="Meine Firma" />
                        <p class="text-xs text-muted-foreground">Betreiber-Organisation dieser Instanz — sie erhält den Admin-Zugriff.</p>
                        <InputError :message="form.errors.organization_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="registry_name">Erste Registry</Label>
                        <Input id="registry_name" v-model="form.registry_name" placeholder="Interne Pakete" />
                        <InputError :message="form.errors.registry_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="registry_slug">Slug</Label>
                        <Input id="registry_slug" v-model="form.registry_slug" placeholder="interne-pakete" @input="slugTouched = true" />
                        <p class="text-xs text-muted-foreground">Erreichbar unter <code>/r/{{ form.registry_slug || 'slug' }}</code></p>
                        <InputError :message="form.errors.registry_slug" />
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="registry_public" v-model="form.registry_public" type="checkbox" class="size-4 rounded border-input" />
                        <Label for="registry_public">Öffentlich lesbar (ohne Token)</Label>
                    </div>
                </template>

                <!-- Step 3: mail -->
                <template v-if="step === 2">
                    <div class="grid gap-2">
                        <Label for="mailer">Treiber</Label>
                        <SearchableSelect
                            id="mailer"
                            v-model="form.mailer"
                            :options="[
                                { value: 'log', label: 'Log (kein Versand — später konfigurierbar)' },
                                { value: 'smtp', label: 'SMTP' },
                                { value: 'postal', label: 'Postal' },
                            ]"
                        />
                        <InputError :message="form.errors.mailer" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="from_address">Absender-Adresse</Label>
                        <Input id="from_address" v-model="form.from_address" type="email" placeholder="noreply@example.com" />
                        <InputError :message="form.errors.from_address" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="from_name">Absender-Name</Label>
                        <Input id="from_name" v-model="form.from_name" />
                        <InputError :message="form.errors.from_name" />
                    </div>

                    <template v-if="form.mailer === 'smtp'">
                        <div class="grid gap-2">
                            <Label for="smtp_host">Host</Label>
                            <Input id="smtp_host" v-model="form.smtp_host" placeholder="smtp.example.com" />
                            <InputError :message="form.errors.smtp_host" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="smtp_port">Port</Label>
                            <Input id="smtp_port" v-model="form.smtp_port" type="number" />
                            <InputError :message="form.errors.smtp_port" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="smtp_encryption">Verschlüsselung</Label>
                            <SearchableSelect
                                id="smtp_encryption"
                                v-model="form.smtp_encryption"
                                :options="[
                                    { value: 'tls', label: 'STARTTLS (Port 587)' },
                                    { value: 'ssl', label: 'Implizites TLS (Port 465)' },
                                    { value: '', label: 'Keine (unverschlüsselt)' },
                                ]"
                            />
                            <InputError :message="form.errors.smtp_encryption" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="smtp_username">Benutzername</Label>
                            <Input id="smtp_username" v-model="form.smtp_username" autocomplete="off" />
                            <InputError :message="form.errors.smtp_username" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="smtp_password">Passwort</Label>
                            <Input id="smtp_password" v-model="form.smtp_password" type="password" autocomplete="off" />
                            <InputError :message="form.errors.smtp_password" />
                        </div>
                    </template>

                    <template v-if="form.mailer === 'postal'">
                        <div class="grid gap-2">
                            <Label for="postal_domain">Postal-Server-URL</Label>
                            <Input id="postal_domain" v-model="form.postal_domain" placeholder="https://postal.example.com" />
                            <InputError :message="form.errors.postal_domain" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="postal_key">API-Credential</Label>
                            <Input id="postal_key" v-model="form.postal_key" type="password" autocomplete="off" />
                            <InputError :message="form.errors.postal_key" />
                        </div>
                    </template>

                    <!-- Verifying the backend here beats discovering it is broken when
                         the first password-reset mail silently fails to arrive. -->
                    <div class="grid gap-2 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                        <Label for="test_recipient">Testmail an (optional)</Label>
                        <Input id="test_recipient" v-model="testRecipient" type="email" placeholder="admin@example.com" />
                        <div>
                            <Button type="button" variant="outline" :disabled="testingMail || !testRecipient" @click="sendTestMail">
                                <LoaderCircle v-if="testingMail" class="size-4 animate-spin" />
                                <Send v-else class="size-4" />
                                Testmail senden
                            </Button>
                        </div>
                        <p v-if="mailTestResult" :class="['text-sm', mailTestResult.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive']">
                            {{ mailTestResult.message }}
                        </p>
                    </div>
                </template>

                <!-- Step 4: storage -->
                <template v-if="step === 3">
                    <div class="grid gap-2">
                        <Label for="storage_driver">Treiber</Label>
                        <SearchableSelect
                            id="storage_driver"
                            v-model="form.storage_driver"
                            :options="[
                                { value: 'local', label: 'Lokal (Server-Dateisystem)' },
                                { value: 's3', label: 'S3 / S3-kompatibel (z. B. MinIO)' },
                            ]"
                        />
                        <p class="text-xs text-muted-foreground">Ablage der Paket-Artefakte. Lokal benötigt ein persistentes Volume.</p>
                        <InputError :message="form.errors.storage_driver" />
                    </div>

                    <template v-if="form.storage_driver === 's3'">
                        <div class="grid gap-2">
                            <Label for="storage_key">Access-Key</Label>
                            <Input id="storage_key" v-model="form.storage_key" autocomplete="off" />
                            <InputError :message="form.errors.storage_key" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="storage_secret">Secret-Key</Label>
                            <Input id="storage_secret" v-model="form.storage_secret" type="password" autocomplete="off" />
                            <InputError :message="form.errors.storage_secret" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="storage_region">Region</Label>
                            <Input id="storage_region" v-model="form.storage_region" placeholder="eu-central-1" autocomplete="off" />
                            <InputError :message="form.errors.storage_region" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="storage_bucket">Bucket</Label>
                            <Input id="storage_bucket" v-model="form.storage_bucket" autocomplete="off" />
                            <InputError :message="form.errors.storage_bucket" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="storage_endpoint">Endpoint (optional)</Label>
                            <Input id="storage_endpoint" v-model="form.storage_endpoint" placeholder="https://minio.example.com" autocomplete="off" />
                            <InputError :message="form.errors.storage_endpoint" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="storage_url">Öffentliche URL (optional)</Label>
                            <Input id="storage_url" v-model="form.storage_url" placeholder="https://cdn.example.com" autocomplete="off" />
                            <InputError :message="form.errors.storage_url" />
                        </div>

                        <div class="flex items-center gap-2">
                            <input
                                id="storage_use_path_style"
                                v-model="form.storage_use_path_style"
                                type="checkbox"
                                class="size-4 rounded border-input"
                            />
                            <Label for="storage_use_path_style">Path-Style-Endpoint verwenden</Label>
                        </div>
                    </template>

                    <p class="text-sm text-muted-foreground">
                        E-Mail und Speicher lassen sich später jederzeit unter <strong>Verwaltung</strong> ändern.
                    </p>
                </template>

                <div class="flex items-center justify-between gap-2 pt-2">
                    <Button type="button" variant="outline" :disabled="step === 0" @click="step--">Zurück</Button>

                    <Button v-if="!isLastStep" type="button" @click="step++">Weiter</Button>
                    <Button v-else type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                        Einrichtung abschließen
                    </Button>
                </div>
            </form>
        </div>
    </div>
</template>
