<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { confirmWithPasskey, passkeysSupported } from '@/lib/passkeys';
import { Head, router, useForm } from '@inertiajs/vue3';
import { KeyRound, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

// A password is not the only proof of "still you", and for an SSO-provisioned or
// admin-invited account it is no proof at all — nobody ever knew that hash. The screen
// therefore also offers the passkey path and a mailed set-password link.
const props = defineProps<{
    canUsePasskey?: boolean;
    canRequestPasswordLink?: boolean;
    status?: string | null;
}>();

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};

const passkeyBusy = ref(false);
const passkeyError = ref<string | null>(null);
const supported = passkeysSupported();

const confirmViaPasskey = async () => {
    passkeyBusy.value = true;
    passkeyError.value = null;

    try {
        const redirect = await confirmWithPasskey();
        router.visit(redirect);
    } catch (e) {
        passkeyError.value =
            e instanceof Error && e.name === 'NotAllowedError' ? 'Bestätigung abgebrochen.' : 'Der Passkey konnte nicht bestätigt werden.';
    } finally {
        passkeyBusy.value = false;
    }
};

const linkForm = useForm({});
const requestPasswordLink = () => linkForm.post(route('password.confirm.link'), { preserveScroll: true });
</script>

<template>
    <AuthLayout title="Confirm your password" description="This is a secure area of the application. Please confirm your password before continuing.">
        <Head title="Confirm password" />

        <div v-if="props.status" class="mb-4 text-sm font-medium text-green-600">{{ props.status }}</div>

        <form @submit.prevent="submit">
            <div class="space-y-6">
                <div class="grid gap-2">
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        class="mt-1 block w-full"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        autofocus
                    />

                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center">
                    <Button class="w-full" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Confirm Password
                    </Button>
                </div>
            </div>
        </form>

        <div v-if="(props.canUsePasskey && supported) || props.canRequestPasswordLink" class="mt-6 space-y-3 border-t pt-6">
            <Button
                v-if="props.canUsePasskey && supported"
                type="button"
                variant="outline"
                class="w-full"
                :disabled="passkeyBusy"
                @click="confirmViaPasskey"
            >
                <LoaderCircle v-if="passkeyBusy" class="h-4 w-4 animate-spin" />
                <KeyRound v-else class="h-4 w-4" />
                Mit Passkey bestätigen
            </Button>
            <InputError :message="passkeyError ?? undefined" />

            <p v-if="props.canRequestPasswordLink" class="text-center text-sm text-muted-foreground">
                Kein Passwort bekannt (z. B. Anmeldung über SSO)?
                <button type="button" class="underline underline-offset-4" :disabled="linkForm.processing" @click="requestPasswordLink">
                    Link zum Setzen eines Passworts anfordern
                </button>
            </p>
        </div>
    </AuthLayout>
</template>
