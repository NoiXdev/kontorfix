// Vue's built-in `HTMLAttributes` (from `@vue/runtime-dom`) has no index signature for
// `data-*` attributes, even though every native HTML element accepts arbitrary `data-*`
// attributes per spec, and Vue has always forwarded them at runtime regardless of this
// gap in the type. Without this augmentation, `vueCompilerOptions.strictTemplates`
// rejects every `data-*` attribute anywhere in the app (Tailwind/Radix state selectors
// like `data-sidebar`, `data-state`, `data-active`, ...). This only tells the type
// checker about behaviour that already exists; it changes no runtime behaviour.
//
// The key pattern is `data${string}`, not `data-${string}`: for a *native* element
// (`<div data-sidebar>`) the template checker keeps the literal kebab-case attribute
// name, but for a *component* (`<Button data-sidebar>`) it normalises to the camelCase
// prop name (`dataSidebar`) before checking it against the component's prop type. One
// broader pattern covers both forms.
declare module '@vue/runtime-dom' {
    interface HTMLAttributes {
        [key: `data${string}`]: unknown;
    }
}

// `@inertiajs/vue3`'s own `InertiaLinkProps` type doesn't declare `tabindex`, even though
// `Link` renders a native `<a>`/`<button>` and forwards extra attrs onto it at runtime the
// same way every other component here does. `TextLink.vue` already relies on this working
// (`resources/js/components/TextLink.vue`). This only tells the checker the truth about
// existing behaviour; it changes nothing at runtime.
declare module '@inertiajs/vue3' {
    interface InertiaLinkProps {
        tabindex?: number;
    }
}

export {};
