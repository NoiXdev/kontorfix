<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { passkeysSupported, registerPasskey } from '@/lib/passkeys';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    passkeys: Array<{
        id: string;
        name: string;
        authenticator: string | null;
        last_used_at: string | null;
        created_at: string | null;
    }>;
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Passkeys', href: '/settings/passkeys' }];

const name = ref('');
const registering = ref(false);
const error = ref<string | null>(null);

const supported = passkeysSupported();

const addPasskey = async () => {
    if (name.value.trim() === '') {
        error.value = 'Bitte gib dem Passkey einen Namen.';
        return;
    }

    registering.value = true;
    error.value = null;

    try {
        await registerPasskey(name.value.trim());
        name.value = '';
        router.reload({ only: ['passkeys'] });
    } catch (e) {
        // User cancels or the browser rejects it.
        error.value = e instanceof Error && e.name === 'NotAllowedError' ? 'Registrierung abgebrochen.' : 'Passkey konnte nicht registriert werden.';
    } finally {
        registering.value = false;
    }
};

const remove = (id: string) => {
    router.delete(route('passkey.destroy', id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Passkeys" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Passkeys"
                    description="Melde dich passwortlos und phishing-sicher mit einem Passkey an (Touch ID, Windows Hello, Sicherheitsschlüssel)."
                />

                <div v-if="!supported" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    Dieser Browser unterstützt keine Passkeys.
                </div>

                <div v-else class="flex items-end gap-3">
                    <div class="grid flex-1 gap-2">
                        <Label for="passkey-name">Name</Label>
                        <Input id="passkey-name" v-model="name" placeholder="z. B. MacBook Touch ID" autocomplete="off" @keyup.enter="addPasskey" />
                        <InputError :message="error ?? undefined" />
                    </div>
                    <Button :disabled="registering" @click="addPasskey">
                        <LoaderCircle v-if="registering" class="h-4 w-4 animate-spin" />
                        Passkey hinzufügen
                    </Button>
                </div>

                <div v-if="passkeys.length > 0" class="divide-y rounded-md border">
                    <div v-for="passkey in passkeys" :key="passkey.id" class="flex items-center justify-between gap-4 p-4">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ passkey.name }}</p>
                            <p class="text-sm text-muted-foreground">
                                <span v-if="passkey.authenticator">{{ passkey.authenticator }} · </span>
                                <span v-if="passkey.last_used_at">zuletzt {{ passkey.last_used_at }}</span>
                                <span v-else>hinzugefügt {{ passkey.created_at }}</span>
                            </p>
                        </div>
                        <Button variant="destructive" size="sm" @click="remove(passkey.id)">Entfernen</Button>
                    </div>
                </div>

                <p v-else class="text-sm text-muted-foreground">Noch keine Passkeys hinterlegt.</p>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
