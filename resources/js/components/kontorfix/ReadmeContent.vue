<script setup lang="ts">
// Renders sanitized README HTML with v-html. This is the one place in the app
// where untrusted (upstream-repository-authored) content becomes markup, so the
// markup, the security note and the element styling live together here rather
// than being duplicated per page — a future hardening rule only has one place
// to land.
defineProps<{
    html: string | null;
}>();
</script>

<template>
    <section v-if="html" class="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
        <!-- Stored HTML is sanitized at write time by ReadmeRenderer: raw HTML is
             stripped, unsafe link schemes are refused, and every <img> is unwrapped
             to its alt text before it ever reaches this column. -->
        <div class="readme max-w-none" v-html="html"></div>
        <p class="mt-6 border-t border-sidebar-border/70 pt-4 text-xs text-muted-foreground dark:border-sidebar-border">
            Bilder aus fremden READMEs werden nicht geladen.
        </p>
    </section>
</template>

<style scoped>
/* No Tailwind typography plugin in this project (checked package.json and the
   @theme block in resources/css/app.css) — the stored README HTML gets minimal
   element styling here instead of `prose` classes.
   `:deep()` is required because v-html content isn't seen by Vue's scoped-CSS rewrite. */
.readme :deep(h1),
.readme :deep(h2),
.readme :deep(h3),
.readme :deep(h4),
.readme :deep(h5),
.readme :deep(h6) {
    margin-top: 1.5em;
    margin-bottom: 0.5em;
    font-weight: 600;
    line-height: 1.3;
}
.readme :deep(h1) {
    font-size: 1.5rem;
}
.readme :deep(h2) {
    font-size: 1.25rem;
}
.readme :deep(h3) {
    font-size: 1.1rem;
}
.readme :deep(p) {
    margin: 0.75em 0;
    line-height: 1.6;
}
.readme :deep(ul),
.readme :deep(ol) {
    margin: 0.75em 0;
    padding-left: 1.5em;
}
.readme :deep(li) {
    margin: 0.25em 0;
}
.readme :deep(a) {
    color: hsl(var(--primary));
    text-decoration: underline;
    text-underline-offset: 2px;
}
.readme :deep(code) {
    border-radius: 0.25rem;
    background-color: hsl(var(--muted));
    padding: 0.15em 0.4em;
    font-family: ui-monospace, 'JetBrains Mono', Menlo, Consolas, monospace;
    font-size: 0.85em;
}
.readme :deep(pre) {
    margin: 1em 0;
    overflow-x: auto;
    border-radius: 0.5rem;
    background-color: hsl(var(--muted));
    padding: 0.75em 1em;
}
.readme :deep(pre code) {
    background-color: transparent;
    padding: 0;
}
.readme :deep(blockquote) {
    margin: 0.75em 0;
    border-left: 3px solid hsl(var(--border));
    padding-left: 1em;
    color: hsl(var(--muted-foreground));
}
.readme :deep(hr) {
    margin: 1.5em 0;
    border-color: hsl(var(--border));
}
.readme :deep(table) {
    margin: 0.75em 0;
    border-collapse: collapse;
    width: 100%;
    font-size: 0.9em;
}
.readme :deep(th),
.readme :deep(td) {
    border: 1px solid hsl(var(--border));
    padding: 0.4em 0.6em;
    text-align: left;
}
.readme :deep(strong) {
    font-weight: 600;
}
</style>
