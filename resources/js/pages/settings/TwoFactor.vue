<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';

defineProps<{
    enabled: boolean;
    confirmed: boolean;
    setup: {
        qr: string;
        secret: string;
        recoveryCodes: string[];
    } | null;
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Zwei-Faktor',
        href: '/settings/two-factor',
    },
];

const enable = () => {
    router.post(route('two-factor.enable'), {}, { preserveScroll: true });
};

const confirmForm = useForm({
    code: '',
});

const confirm = () => {
    confirmForm.post(route('two-factor.confirm'), {
        preserveScroll: true,
    });
};

const disableForm = useForm({
    password: '',
});

const disable = () => {
    disableForm.delete(route('two-factor.disable'), {
        preserveScroll: true,
        onSuccess: () => disableForm.reset(),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Zwei-Faktor" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Zwei-Faktor-Authentifizierung" description="Sichere dein Konto mit einem zusätzlichen Bestätigungscode." />

                <!-- State: not enabled -->
                <div v-if="!enabled" class="space-y-6">
                    <p class="text-sm text-muted-foreground">
                        Die Zwei-Faktor-Authentifizierung ist derzeit deaktiviert. Aktiviere sie, um bei jeder Anmeldung zusätzlich einen
                        zeitbasierten Code aus deiner Authenticator-App eingeben zu müssen.
                    </p>

                    <Button @click="enable">Zwei-Faktor aktivieren</Button>
                </div>

                <!-- State: enabled, but not yet confirmed (setup in progress) -->
                <div v-else-if="enabled && !confirmed && setup" class="space-y-6">
                    <p class="text-sm text-muted-foreground">
                        Scanne den folgenden QR-Code mit deiner Authenticator-App und gib anschließend den generierten Code ein, um die Einrichtung
                        abzuschließen.
                    </p>

                    <div class="grid gap-2">
                        <img :src="setup.qr" alt="QR-Code" class="h-48 w-48 rounded border bg-white p-2" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Einrichtungsschlüssel (manuelle Eingabe)</Label>
                        <code class="inline-block w-fit rounded bg-muted px-2 py-1 font-mono text-sm tracking-wider">{{ setup.secret }}</code>
                    </div>

                    <div class="grid gap-2">
                        <Label>Wiederherstellungscodes</Label>
                        <p class="text-sm text-muted-foreground">
                            Bewahre diese Wiederherstellungscodes sicher auf — sie werden nur jetzt angezeigt.
                        </p>
                        <ul class="grid gap-1 rounded border bg-muted p-3 font-mono text-sm">
                            <li v-for="recoveryCode in setup.recoveryCodes" :key="recoveryCode">{{ recoveryCode }}</li>
                        </ul>
                    </div>

                    <form @submit.prevent="confirm" class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="code">Bestätigungscode</Label>
                            <Input
                                id="code"
                                v-model="confirmForm.code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                class="mt-1 block w-full"
                                placeholder="123456"
                            />
                            <InputError :message="confirmForm.errors.code" />
                        </div>

                        <Button :disabled="confirmForm.processing">Bestätigen</Button>
                    </form>
                </div>

                <!-- State: confirmed (active) -->
                <div v-else-if="confirmed" class="space-y-6">
                    <p class="text-sm font-medium text-green-600">Die Zwei-Faktor-Authentifizierung ist aktiv.</p>

                    <p class="text-sm text-muted-foreground">Gib dein Passwort ein, um die Zwei-Faktor-Authentifizierung zu deaktivieren.</p>

                    <form @submit.prevent="disable" class="space-y-6">
                        <div class="grid gap-2">
                            <Label for="password">Passwort</Label>
                            <Input
                                id="password"
                                v-model="disableForm.password"
                                type="password"
                                autocomplete="current-password"
                                class="mt-1 block w-full"
                                placeholder="Passwort"
                            />
                            <InputError :message="disableForm.errors.password" />
                            <p class="text-xs text-muted-foreground">
                                Kein Passwort für dieses Konto? Feld leer lassen und abschicken — die Bestätigung läuft dann über einen Passkey oder
                                einen zugeschickten Link zum Setzen eines Passworts.
                            </p>
                        </div>

                        <Button variant="destructive" :disabled="disableForm.processing">Zwei-Faktor deaktivieren</Button>
                    </form>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
