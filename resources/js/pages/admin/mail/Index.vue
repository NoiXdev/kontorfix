<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Send } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Mailer = 'log' | 'smtp' | 'postal';

interface MailSettings {
    mailer: Mailer;
    from_address: string | null;
    from_name: string | null;
    smtp_host: string | null;
    smtp_port: number | null;
    smtp_username: string | null;
    smtp_encryption: string | null;
    postal_domain: string | null;
    has_smtp_password: boolean;
    has_postal_key: boolean;
}

const props = defineProps<{
    settings: MailSettings;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'E-Mail', href: '/admin/mail' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const form = useForm({
    mailer: props.settings.mailer,
    from_address: props.settings.from_address ?? '',
    from_name: props.settings.from_name ?? '',
    smtp_host: props.settings.smtp_host ?? '',
    smtp_port: props.settings.smtp_port ?? 587,
    smtp_username: props.settings.smtp_username ?? '',
    smtp_password: '',
    smtp_encryption: props.settings.smtp_encryption ?? 'tls',
    postal_domain: props.settings.postal_domain ?? '',
    postal_key: '',
});

const testRecipient = ref(props.settings.from_address ?? '');
const testing = ref(false);
const testResult = ref<{ ok: boolean; message: string } | null>(null);

function submit() {
    form.put(route('admin.mail.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.smtp_password = '';
            form.postal_key = '';
        },
    });
}

/** Reads the XSRF-TOKEN cookie set by Laravel for the CSRF header. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function sendTest() {
    testResult.value = null;
    testing.value = true;

    try {
        const response = await fetch(route('admin.mail.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            // Probes the currently entered values — an empty secret means
            // "keep the stored one" on the server side.
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

        testResult.value = {
            ok: response.ok && data?.ok === true,
            message: data?.message ?? 'Testmail fehlgeschlagen.',
        };
    } catch {
        testResult.value = { ok: false, message: 'Testmail fehlgeschlagen.' };
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <Head title="E-Mail" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div
                v-if="flashSuccess"
                class="fixed top-4 right-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">E-Mail-Versand</h1>
            </div>

            <form class="max-w-2xl space-y-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="mailer">Treiber</Label>
                    <SearchableSelect
                        id="mailer"
                        v-model="form.mailer"
                        :options="[
                            { value: 'log', label: 'Log (kein Versand, nur Logfile)' },
                            { value: 'smtp', label: 'SMTP' },
                            { value: 'postal', label: 'Postal' },
                        ]"
                    />
                    <InputError :message="form.errors.mailer" />
                </div>

                <div class="grid gap-2">
                    <Label for="from_address">Absender-Adresse</Label>
                    <Input id="from_address" v-model="form.from_address" type="email" placeholder="noreply@example.com" autocomplete="off" />
                    <InputError :message="form.errors.from_address" />
                </div>

                <div class="grid gap-2">
                    <Label for="from_name">Absender-Name</Label>
                    <Input id="from_name" v-model="form.from_name" placeholder="Kontorfix" autocomplete="off" />
                    <InputError :message="form.errors.from_name" />
                </div>

                <template v-if="form.mailer === 'smtp'">
                    <div class="grid gap-2">
                        <Label for="smtp_host">Host</Label>
                        <Input id="smtp_host" v-model="form.smtp_host" placeholder="smtp.example.com" autocomplete="off" />
                        <InputError :message="form.errors.smtp_host" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="smtp_port">Port</Label>
                        <Input id="smtp_port" v-model="form.smtp_port" type="number" autocomplete="off" />
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
                        <div class="flex items-center justify-between">
                            <Label for="smtp_password">Passwort</Label>
                            <span
                                v-if="props.settings.has_smtp_password"
                                class="inline-flex items-center rounded-md border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                            >
                                Gesetzt
                            </span>
                        </div>
                        <Input id="smtp_password" v-model="form.smtp_password" type="password" autocomplete="off" placeholder="unverändert lassen" />
                        <InputError :message="form.errors.smtp_password" />
                    </div>
                </template>

                <template v-if="form.mailer === 'postal'">
                    <div class="grid gap-2">
                        <Label for="postal_domain">Postal-Server-URL</Label>
                        <Input id="postal_domain" v-model="form.postal_domain" placeholder="https://postal.example.com" autocomplete="off" />
                        <InputError :message="form.errors.postal_domain" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label for="postal_key">API-Credential</Label>
                            <span
                                v-if="props.settings.has_postal_key"
                                class="inline-flex items-center rounded-md border border-copper/30 bg-copper/15 px-2 py-0.5 text-xs font-medium text-copper-hi"
                            >
                                Gesetzt
                            </span>
                        </div>
                        <Input id="postal_key" v-model="form.postal_key" type="password" autocomplete="off" placeholder="unverändert lassen" />
                        <InputError :message="form.errors.postal_key" />
                    </div>

                    <p class="text-sm text-muted-foreground">Die Absender-Domain muss im Postal-Server für dieses API-Credential freigegeben sein.</p>
                </template>

                <div class="grid gap-2 border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                    <Label for="test_recipient">Testmail an</Label>
                    <Input id="test_recipient" v-model="testRecipient" type="email" placeholder="admin@example.com" autocomplete="off" />
                </div>

                <div class="flex items-center gap-2 pt-2">
                    <Button type="submit" :disabled="form.processing">Speichern</Button>
                    <Button type="button" variant="outline" :disabled="testing || !testRecipient" @click="sendTest">
                        <Send class="size-4" />
                        Testmail senden
                    </Button>
                </div>

                <p v-if="testResult" :class="['text-sm', testResult.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive']">
                    {{ testResult.message }}
                </p>
            </form>
        </div>
    </AppLayout>
</template>
