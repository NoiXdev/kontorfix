import { describe, expect, it } from 'vitest';
import { FLASH_TOAST_DURATION_MS, initialFlashToastState, reduceFlashToast, type FlashToastState } from './flashToast';

// Convenience: replays a list of events onto the initial state, the way the component
// feeds them in over the life of a page.
function replay(...events: Parameters<typeof reduceFlashToast>[1][]): FlashToastState {
    return events.reduce(reduceFlashToast, initialFlashToastState);
}

describe('showing a flash', () => {
    it('shows a message delivered by a full visit', () => {
        expect(replay({ type: 'flash', message: 'Paket angelegt.', partial: false })).toEqual({
            message: 'Paket angelegt.',
            seen: 'Paket angelegt.',
        });
    });

    it('shows nothing when the page carries no flash', () => {
        expect(replay({ type: 'flash', message: null, partial: false })).toEqual(initialFlashToastState);
    });

    it('treats an empty string like no flash at all', () => {
        expect(replay({ type: 'flash', message: '', partial: false })).toEqual(initialFlashToastState);
    });
});

describe('dismissing', () => {
    it('hides the toast but remembers what was delivered', () => {
        const state = replay({ type: 'flash', message: 'Paket angelegt.', partial: false }, { type: 'dismiss' });

        expect(state.message).toBeNull();
        expect(state.seen, 'forgetting the message is what lets a partial reload resurrect it').toBe('Paket angelegt.');
    });

    // The whole reason this reducer exists. `router.reload({ only: ['package'] })` does not
    // re-send `flash`, so Inertia merges the response into the props already held and
    // `flash.success` still reads as the dismissed message.
    it('does not resurrect a dismissed message on a partial reload', () => {
        const state = replay(
            { type: 'flash', message: 'Paket angelegt.', partial: false },
            { type: 'dismiss' },
            { type: 'flash', message: 'Paket angelegt.', partial: true },
        );

        expect(state.message).toBeNull();
    });

    it('does not restart an on-screen toast on a partial reload either', () => {
        const shown = replay({ type: 'flash', message: 'Paket angelegt.', partial: false });
        const after = reduceFlashToast(shown, { type: 'flash', message: 'Paket angelegt.', partial: true });

        expect(after, 'an unchanged state means the component leaves the running timer alone').toBe(shown);
    });
});

describe('repeat deliveries', () => {
    // Clicking "Erneut synchronisieren" twice flashes the identical string twice. Both are
    // real confirmations of a real action, so both must be shown — a naive "has this text
    // been seen before?" guard would swallow the second one.
    it('shows the same message again when a full visit re-delivers it', () => {
        const state = replay(
            { type: 'flash', message: 'Synchronisierung wurde eingereiht.', partial: false },
            { type: 'dismiss' },
            { type: 'flash', message: 'Synchronisierung wurde eingereiht.', partial: false },
        );

        expect(state.message).toBe('Synchronisierung wurde eingereiht.');
    });

    it('shows a partial reload that genuinely carries a different message', () => {
        const state = replay(
            { type: 'flash', message: 'Paket angelegt.', partial: false },
            { type: 'flash', message: 'Synchronisierung wurde eingereiht.', partial: true },
        );

        expect(state.message).toBe('Synchronisierung wurde eingereiht.');
    });

    it('forgets the seen message once the server reports no flash, so it can be shown again', () => {
        const state = replay(
            { type: 'flash', message: 'Paket angelegt.', partial: false },
            { type: 'dismiss' },
            { type: 'flash', message: null, partial: false },
            { type: 'flash', message: 'Paket angelegt.', partial: true },
        );

        expect(state.message).toBe('Paket angelegt.');
    });
});

describe('duration', () => {
    it('dismisses itself within a few seconds rather than sitting there forever', () => {
        expect(FLASH_TOAST_DURATION_MS).toBeGreaterThan(2_000);
        expect(FLASH_TOAST_DURATION_MS).toBeLessThanOrEqual(10_000);
    });
});
