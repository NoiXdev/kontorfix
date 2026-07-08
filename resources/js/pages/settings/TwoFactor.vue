<script setup lang="ts">
// Placeholder page for the two-factor settings backend (Task L3).
// The full UI is implemented in Task L5. This stub only exists so that
// the Inertia page-existence check (config: inertia.testing.ensure_pages_exist)
// passes for the backend feature tests.
defineProps<{
    enabled: boolean;
    confirmed: boolean;
    setup: {
        qr: string;
        secret: string;
        recoveryCodes: string[];
    } | null;
}>();
</script>

<template>
    <div />
</template>
