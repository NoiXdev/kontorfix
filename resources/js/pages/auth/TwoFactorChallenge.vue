<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

const useRecoveryCode = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const toggleRecoveryCode = () => {
    useRecoveryCode.value = !useRecoveryCode.value;
};

const submit = () => {
    form.post(route('two-factor.login'), {
        onFinish: () => form.reset('code', 'recovery_code'),
    });
};
</script>

<template>
    <AuthBase title="Bestätigung" description="Bestätige deine Anmeldung mit einem Code aus deiner Authenticator-App.">
        <Head title="Bestätigung" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div v-if="!useRecoveryCode" class="grid gap-2">
                    <Label for="code">Bestätigungscode</Label>
                    <Input
                        id="code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        autofocus
                        v-model="form.code"
                        placeholder="123456"
                    />
                    <InputError :message="form.errors.code" />
                </div>

                <div v-else class="grid gap-2">
                    <Label for="recovery_code">Wiederherstellungscode</Label>
                    <Input
                        id="recovery_code"
                        type="text"
                        autocomplete="one-time-code"
                        autofocus
                        v-model="form.recovery_code"
                        placeholder="Wiederherstellungscode"
                    />
                    <InputError :message="form.errors.recovery_code" />
                </div>

                <Button type="submit" class="mt-4 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Bestätigen
                </Button>

                <div class="text-center text-sm text-muted-foreground">
                    <button type="button" class="underline underline-offset-4 hover:text-foreground" @click="toggleRecoveryCode">
                        {{ useRecoveryCode ? 'Stattdessen Bestätigungscode verwenden' : 'Stattdessen Wiederherstellungscode verwenden' }}
                    </button>
                </div>
            </div>
        </form>
    </AuthBase>
</template>
