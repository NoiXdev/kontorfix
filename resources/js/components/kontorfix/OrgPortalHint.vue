<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * The "this registry's owner has no customer portal (yet)" hint, shown under the "Im
 * Kundenportal anzeigen" switch on both the group-create sheet (`GroupSheet.vue`) and the
 * group-edit page (`pages/admin/groups/Show.vue`). One component rather than two copies:
 * the switch above only controls whether a registry appears INSIDE the customer portal —
 * it says nothing about whether the owning organization's portal exists at all — and both
 * call sites need to say so in the exact same words, with the same conditional link to open
 * that organization, or the copy drifts the next time only one of them gets edited.
 *
 * The link to `admin.organizations.show` is itself gated: customer/organization management
 * is super-admin only (see `EnsureSuperAdmin`), so a caller who may edit registries but not
 * organizations sees the hint text with no link at all rather than a link that 403s.
 */
const props = defineProps<{
    /** Whether the OWNING organization's customer portal exists at all — not whether this
     * particular registry is shown inside it. See `Organization::portal_enabled`'s docblock. */
    portalEnabled: boolean;
    canManageOrganization: boolean;
    organizationId: string | null;
}>();
</script>

<template>
    <p
        v-if="!props.portalEnabled"
        class="inline-flex w-fit items-start gap-1 rounded-md border border-border bg-muted px-2 py-1 text-xs text-muted-foreground"
    >
        <span>
            Das Kundenportal dieser Organisation ist deaktiviert — diese Registry erscheint dort erst, wenn es aktiviert wird.
            <Link
                v-if="props.canManageOrganization && props.organizationId"
                :href="route('admin.organizations.show', props.organizationId)"
                class="underline underline-offset-2 hover:text-foreground"
            >
                Organisation öffnen
            </Link>
        </span>
    </p>
</template>
