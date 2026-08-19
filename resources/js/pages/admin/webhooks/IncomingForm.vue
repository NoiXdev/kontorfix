<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { inject } from 'vue';
import { incomingWebhookFormKey, incomingWebhookProviderOptions } from './webhookForm';

// Provided by IncomingCreate.vue (see webhookForm.ts) rather than passed as a prop: this
// form object is meant to be written into (`v-model="form.xxx"`), and an injected value is
// not subject to Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(incomingWebhookFormKey);
if (!injectedForm) {
    throw new Error('IncomingForm.vue requires a form to be provided via incomingWebhookFormKey — see IncomingCreate.vue.');
}
const form = injectedForm;
</script>

<template>
    <div class="grid gap-2">
        <Label for="incoming_name">Name</Label>
        <Input id="incoming_name" v-model="form.name" placeholder="GitHub – acme/tools" autocomplete="off" />
        <InputError :message="form.errors.name" />
    </div>

    <div class="grid gap-2">
        <Label for="incoming_provider">Provider</Label>
        <SearchableSelect id="incoming_provider" v-model="form.provider" :options="incomingWebhookProviderOptions" />
        <InputError :message="form.errors.provider" />
    </div>
</template>
