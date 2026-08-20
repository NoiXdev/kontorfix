<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, inject } from 'vue';
import { recipientFormKey } from './recipientForm';

// Provided by Create.vue (see recipientForm.ts) rather than passed as a prop: this form
// object is meant to be written into (`v-model="form.xxx"`), and an injected value is not
// subject to Vue's no-mutating-props rule the way a prop would be. Mirrors webhooks/Form.vue.
const injectedForm = inject(recipientFormKey);
if (!injectedForm) {
    throw new Error('Form.vue requires a form to be provided via recipientFormKey — see Create.vue.');
}
const form = injectedForm;

// The checkbox list is driven by the shared NotificationEvent metadata (HandleInertiaRequests),
// not a hardcoded list — a case added to the backend enum shows up here without a frontend edit.
const page = usePage<SharedData>();
const eventOptions = computed(() => page.props.notificationEventMeta ?? []);

function toggleEvent(value: string, checked: boolean) {
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
        <Label for="email">E-Mail</Label>
        <Input id="email" v-model="form.email" type="email" placeholder="ops@example.com" autocomplete="off" />
        <InputError :message="form.errors.email" />
    </div>

    <div class="grid gap-2">
        <Label for="name">Name (optional)</Label>
        <Input id="name" v-model="form.name" autocomplete="off" />
        <InputError :message="form.errors.name" />
    </div>

    <div class="grid gap-2">
        <Label>Events</Label>
        <div class="space-y-2">
            <label v-for="option in eventOptions" :key="option.value" class="flex items-center gap-2 text-sm">
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

    <label class="flex items-center gap-2 text-sm">
        <Switch v-model="form.enabled" />
        Aktiv
    </label>
    <InputError :message="form.errors.enabled" />
</template>
