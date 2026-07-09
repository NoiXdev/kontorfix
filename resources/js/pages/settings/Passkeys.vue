<script setup lang="ts">
// Platzhalter — vollständige UI folgt in Task Q4.
defineProps<{
    passkeys: Array<{
        id: string;
        name: string;
        authenticator: string | null;
        last_used_at: string | null;
        created_at: string | null;
    }>;
}>();
</script>

<template>
    <div />
</template>
