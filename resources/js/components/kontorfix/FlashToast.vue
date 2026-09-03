<script setup lang="ts">
/**
 * The single success toast for `flash.success`.
 *
 * Sixteen admin pages had each pasted the same `<div v-if="flashSuccess" class="fixed
 * top-4 right-4 …">` — sixteen copies of one defect: no timer, no close button, so a
 * confirmation stayed on screen until the operator navigated away. Most visible after
 * creating a package, which redirects to the detail page and leaves the message pinned
 * over it for good.
 *
 * The non-obvious part is not the timer but *when to show again*, which lives in
 * `@/lib/flashToast` as a pure reducer with its own tests. Everything here is glue:
 * reading the prop, telling the reducer whether the visit was partial, and running the
 * timer.
 */
import { FLASH_TOAST_DURATION_MS, initialFlashToastState, reduceFlashToast, type FlashToastEvent } from '@/lib/flashToast';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ durationMs?: number }>(), { durationMs: FLASH_TOAST_DURATION_MS });

const page = usePage<SharedData>();
const message = computed(() => page.props.flash?.success ?? null);

const state = ref(initialFlashToastState);
let timer: ReturnType<typeof setTimeout> | undefined;

/**
 * Whether the visit that most recently completed asked for a subset of props. Inertia
 * merges a partial response into the props already held, so `flash.success` survives such
 * a reload untouched and would otherwise look like a fresh message every time. `only` is
 * on the visit object the `start` event carries; it is empty for a full visit.
 */
let lastVisitWasPartial = false;

function dispatch(event: FlashToastEvent): void {
    const next = reduceFlashToast(state.value, event);
    if (next === state.value) {
        // The reducer refused the event (a partial reload echoing a consumed message) —
        // leave any running timer alone rather than restarting it.
        return;
    }

    state.value = next;
    clearTimer();

    if (next.message !== null && props.durationMs > 0) {
        timer = setTimeout(() => dispatch({ type: 'dismiss' }), props.durationMs);
    }
}

function clearTimer(): void {
    if (timer !== undefined) {
        clearTimeout(timer);
        timer = undefined;
    }
}

function deliver(partial: boolean): void {
    dispatch({ type: 'flash', message: message.value, partial });
}

// A prop that *changes* value. Covers the ordinary case; it cannot see a full visit
// re-delivering the identical string, which is what the router hooks below are for.
watch(message, () => deliver(lastVisitWasPartial));

let stopStart: (() => void) | undefined;
let stopSuccess: (() => void) | undefined;

onMounted(() => {
    // The first paint is server-rendered, so no visit event fires for it.
    deliver(false);

    stopStart = router.on('start', (event) => {
        lastVisitWasPartial = (event.detail.visit.only?.length ?? 0) > 0;
    });

    // Fires after the new page props are in place. Unlike the watcher above this also
    // fires when the server flashes the same text twice in a row (resync clicked twice),
    // which is a genuine second confirmation and has to be shown again.
    stopSuccess = router.on('success', () => deliver(lastVisitWasPartial));
});

onBeforeUnmount(() => {
    clearTimer();
    stopStart?.();
    stopSuccess?.();
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out motion-reduce:transition-none"
        enter-from-class="translate-x-4 opacity-0 motion-reduce:translate-x-0"
        leave-active-class="transition duration-150 ease-in motion-reduce:transition-none"
        leave-to-class="translate-x-4 opacity-0 motion-reduce:translate-x-0"
    >
        <div
            v-if="state.message"
            role="status"
            aria-live="polite"
            class="fixed top-4 right-4 z-50 flex items-start gap-3 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
        >
            <span>{{ state.message }}</span>
            <button
                type="button"
                aria-label="Meldung schließen"
                class="-mr-1 rounded-sm p-0.5 opacity-70 transition-opacity hover:opacity-100 focus-visible:ring-2 focus-visible:ring-verdigris/50 focus-visible:outline-none motion-reduce:transition-none"
                @click="dispatch({ type: 'dismiss' })"
            >
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
