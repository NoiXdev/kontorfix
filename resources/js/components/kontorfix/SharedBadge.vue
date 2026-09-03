<script setup lang="ts">
/**
 * The marker for a package released to other organizations.
 *
 * One component rather than a repeated span: the badge appears in the package listing, in
 * the package detail page's header, in the assignment picker's search results and selection
 * chips, and in a registry's package table. An operator has to be able to recognise "this
 * is not only yours" at a glance in all of them, which a per-site copy of the classes stops
 * guaranteeing the first time one of them is restyled.
 *
 * The detail page's header is the one that is easy to forget: the shared toggle there
 * renders only for a caller who may change the flag, so the header badge is the only place
 * everyone else learns the package is shared at all.
 *
 * Verdigris, matching the package listing's original marker — deliberately not the amber
 * used for `verwaist`: sharing is a normal state, not a warning.
 */
</script>

<template>
    <span
        class="inline-flex items-center rounded-md border border-verdigris/30 bg-verdigris/15 px-2 py-0.5 font-sans text-xs font-medium text-verdigris"
        title="Freigegeben: dieses Paket kann Registrys anderer Organisationen zugewiesen werden."
    >
        geteilt
    </span>
</template>
