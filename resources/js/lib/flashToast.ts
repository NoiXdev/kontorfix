/**
 * Visibility rules for the shared flash toast.
 *
 * Sixteen admin pages used to render `<div v-if="flash.success">` inline, with no timer
 * and no close button, so a success message stayed on screen for the whole life of the
 * page — most visibly after creating a package, where the operator lands on the detail
 * page and the confirmation never leaves again.
 *
 * The rules are subtler than "show while the prop is set", which is why they live here as
 * a pure reducer instead of inside the component: Inertia keeps shared props around. A
 * partial reload (`router.reload({ only: [...] })`) does not carry `flash`, so the client
 * merges the response into the props it already has and `flash.success` still reads as the
 * message the operator dismissed a minute ago. Re-showing it there would make the toast
 * undismissable again through the back door.
 *
 * Hence the two-field state: `message` is what is on screen, `seen` is the last message
 * the server actually delivered. A partial reload that repeats `seen` is ignored; a full
 * visit always (re-)shows, even when it repeats the same text — resyncing twice flashes
 * "Synchronisierung wurde eingereiht." both times and both deserve a toast.
 */

export interface FlashToastState {
    /** The message currently on screen, or null when the toast is hidden. */
    message: string | null;
    /**
     * The last message the server delivered, dismissed or not. Only used to recognise a
     * partial reload that is echoing back an already-consumed flash.
     */
    seen: string | null;
}

export type FlashToastEvent =
    /**
     * The flash prop as it currently reads. `partial` is true when the visit that
     * produced it was a partial reload, i.e. Inertia may be echoing merged-in props
     * the server never re-sent.
     */
    | { type: 'flash'; message: string | null; partial: boolean }
    /** The operator clicked the close button, or the auto-dismiss timer fired. */
    | { type: 'dismiss' };

export const initialFlashToastState: FlashToastState = { message: null, seen: null };

/** How long a toast stays up before dismissing itself. */
export const FLASH_TOAST_DURATION_MS = 6_000;

export function reduceFlashToast(state: FlashToastState, event: FlashToastEvent): FlashToastState {
    if (event.type === 'dismiss') {
        // `seen` is deliberately kept: it is what stops a later partial reload from
        // resurrecting the message that was just dismissed.
        return { message: null, seen: state.seen };
    }

    // No flash at all — the operator navigated somewhere that sets none. Forget `seen`
    // too, so the identical text can be flashed again later and still be recognised as new.
    if (event.message === null || event.message === '') {
        return initialFlashToastState;
    }

    if (event.partial && event.message === state.seen) {
        return state;
    }

    return { message: event.message, seen: event.message };
}
