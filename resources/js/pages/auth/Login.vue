<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { loginWithPasskey, passkeysSupported } from '@/lib/passkeys';
import { type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Fingerprint, KeyRound, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

withDefaults(
    defineProps<{
        status?: string;
        canResetPassword: boolean;
        oidcProviders?: Array<{ slug: string; name: string }>;
    }>(),
    {
        oidcProviders: () => [],
    },
);

const registrationEnabled = computed(() => usePage<SharedData>().props.registrationEnabled === true);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const passkeysAvailable = passkeysSupported();
const passkeyPending = ref(false);
const passkeyError = ref<string | null>(null);

const signInWithPasskey = async () => {
    passkeyPending.value = true;
    passkeyError.value = null;

    try {
        const redirect = await loginWithPasskey(form.remember);
        window.location.href = redirect;
    } catch (e) {
        passkeyError.value =
            e instanceof Error && e.name === 'NotAllowedError' ? 'Anmeldung abgebrochen.' : 'Anmeldung per Passkey fehlgeschlagen.';
        passkeyPending.value = false;
    }
};
</script>

<template>
    <AuthBase title="Log in to your account" description="Enter your email and password below to log in">
        <Head title="Log in" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div v-if="oidcProviders.length" class="mb-6 flex flex-col gap-2">
            <Button
                v-for="provider in oidcProviders"
                :key="provider.slug"
                as="a"
                :href="route('oidc.redirect', provider.slug)"
                variant="outline"
                class="w-full"
            >
                <KeyRound class="h-4 w-4" />
                Mit {{ provider.name }} anmelden
            </Button>

            <div class="my-2 flex items-center gap-3 text-xs text-muted-foreground">
                <span class="h-px flex-1 bg-border" />
                <span>oder</span>
                <span class="h-px flex-1 bg-border" />
            </div>
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="email"
                        v-model="form.email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" tabindex="5"> Forgot password? </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        required
                        tabindex="2"
                        autocomplete="current-password"
                        v-model="form.password"
                        placeholder="Password"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between" tabindex="3">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model:checked="form.remember" tabindex="4" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" tabindex="4" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Log in
                </Button>

                <div v-if="passkeysAvailable" class="grid gap-2">
                    <Button type="button" variant="outline" class="w-full" :disabled="passkeyPending" @click="signInWithPasskey">
                        <LoaderCircle v-if="passkeyPending" class="h-4 w-4 animate-spin" />
                        <Fingerprint v-else class="h-4 w-4" />
                        Mit Passkey anmelden
                    </Button>
                    <InputError :message="passkeyError ?? undefined" class="text-center" />
                </div>
            </div>

            <div v-if="registrationEnabled" class="text-center text-sm text-muted-foreground">
                Don't have an account?
                <TextLink :href="route('register')" :tabindex="5">Sign up</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
