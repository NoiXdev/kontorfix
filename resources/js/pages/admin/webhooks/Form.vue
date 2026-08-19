<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { inject } from 'vue';
import { webhookEventOptions, webhookFormKey, type WebhookEventKey } from './webhookForm';

// Provided by Create.vue (see webhookForm.ts) rather than passed as a prop: this form object
// is meant to be written into (`v-model="form.xxx"`), and an injected value is not subject to
// Vue's no-mutating-props rule the way a prop would be.
const injectedForm = inject(webhookFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via webhookFormKey — see Create.vue.');
}
const form = injectedForm;

function toggleEvent(value: WebhookEventKey, checked: boolean) {
    if (checked) {
        if (!form.events.includes(value)) {
            form.events.push(value);
        }
    } else {
        form.events = form.events.filter((e) => e !== value);
    }
}
</script>

<template>
    <div class="grid gap-2">
        <Label for="url">URL</Label>
        <Input id="url" v-model="form.url" placeholder="https://hooks.example.com/kfx" autocomplete="off" />
        <InputError :message="form.errors.url" />
    </div>

    <div class="grid gap-2">
        <Label for="secret">Secret (optional)</Label>
        <Input id="secret" v-model="form.secret" type="password" autocomplete="off" />
        <InputError :message="form.errors.secret" />
    </div>

    <div class="grid gap-2">
        <Label>Events</Label>
        <div class="space-y-2">
            <label v-for="option in webhookEventOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    :checked="form.events.includes(option.value)"
                    class="size-4 rounded border-input"
                    @change="toggleEvent(option.value, ($event.target as HTMLInputElement).checked)"
                />
                {{ option.label }}
            </label>
        </div>
        <InputError :message="form.errors.events" />
    </div>
</template>
