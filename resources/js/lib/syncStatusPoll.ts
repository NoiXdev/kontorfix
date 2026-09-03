/**
 * Reconciles a package's sync status with the server when the broadcast cannot.
 *
 * The detail page seeds its badge from the server-rendered prop and otherwise only ever
 * changes it when a `PackageSynced` / `PackageSyncFailed` event arrives on the `operator`
 * channel. Creating a package dispatches `SyncPackage` and redirects to that page
 * immediately, so the browser still has to load the page, open the WebSocket, authenticate
 * at `/broadcasting/auth` and subscribe. A small repository syncs faster than that, the
 * event is broadcast to a channel this browser has not joined, Reverb does not replay it,
 * and the badge reads "Wartet" until somebody reloads by hand.
 *
 * Realtime may also be off entirely: `@/echo` only constructs `window.Echo` when a Reverb
 * key was present at asset-build time, and `useOperatorChannel` subscribes only for
 * super-admins. In both cases this poll is the *only* thing that ever corrects the badge,
 * which is why it does not assume a broadcast will eventually arrive.
 *
 * The scheduling lives here, apart from Vue and from `fetch`, because it is the part with
 * rules worth testing: stop on a terminal status, never start on one, back off, give up —
 * and say so when it gives up.
 */

export type SyncStatus = 'pending' | 'syncing' | 'synced' | 'failed';

export const SYNC_STATUSES: readonly SyncStatus[] = ['pending', 'syncing', 'synced', 'failed'];

export interface SyncStatusSnapshot {
    status: SyncStatus;
    error: string | null;
}

/**
 * The dense part of the backoff: the first seven checks, spanning 53 seconds. This is
 * where the missed broadcast actually lives — creation redirects to the detail page the
 * instant `SyncPackage` is dispatched, and a small repository is usually done within a
 * second or two of that.
 */
const SYNC_POLL_RAMP_MS: readonly number[] = [1_000, 2_000, 3_000, 5_000, 8_000, 13_000, 21_000];

/** The interval the ramp settles into. One cheap request every half minute. */
const SYNC_POLL_STEADY_MS = 30_000;

/**
 * How long the page keeps looking, in total.
 *
 * Pinned to `SyncPackage::TIMEOUT` (900s), the alarm on a *single* run of the job: within
 * that window every first-attempt outcome — success, and just as importantly the failure
 * whose `sync_error` text the operator actually needs — is observed and displayed.
 *
 * It is deliberately not pinned to the job's outer envelope. `SyncPackage::retryUntil()`
 * is roughly 3960s (`maxExceptions × timeout + sum(backoff [60, 300, 900])`), and a tab
 * polling for over an hour is not a reasonable thing to leave running. Beyond this budget
 * the reconciler stops and reports that it stopped, via `onGiveUp` — see the note there:
 * a badge that has quietly stopped updating is worse than one that admits it.
 */
export const SYNC_POLL_BUDGET_MS = 900_000;

function buildPollSchedule(): number[] {
    const delays = [...SYNC_POLL_RAMP_MS];
    let total = delays.reduce((sum, delay) => sum + delay, 0);

    while (total < SYNC_POLL_BUDGET_MS) {
        delays.push(SYNC_POLL_STEADY_MS);
        total += SYNC_POLL_STEADY_MS;
    }

    return delays;
}

/** 36 attempts spanning 923 seconds: the ramp above, then 30s steps out to the budget. */
export const SYNC_POLL_DELAYS_MS: readonly number[] = buildPollSchedule();

/** A status the job will not move away from — nothing left to reconcile. */
export function isTerminalSyncStatus(status: string): boolean {
    return status === 'synced' || status === 'failed';
}

function isSyncStatus(value: unknown): value is SyncStatus {
    return typeof value === 'string' && (SYNC_STATUSES as readonly string[]).includes(value);
}

/**
 * Turns a decoded `admin.packages.sync-status` body into a snapshot, or throws.
 *
 * Blindly casting the JSON was a quiet way to poison the page: a 200 carrying an
 * unexpected body (a proxy's error envelope, a login page served with the wrong content
 * type, a future field rename) set `status` to `undefined`, which renders an empty status
 * pill and — since `undefined` is never terminal — polls out the entire budget. Throwing
 * instead routes it through the reconciler's normal "unknown, try again" path and, if it
 * keeps happening, ends in the honest give-up notice.
 */
export function parseSyncStatusResponse(body: unknown): SyncStatusSnapshot {
    if (typeof body !== 'object' || body === null) {
        throw new Error('sync-status: response was not an object');
    }

    const { status, error } = body as { status?: unknown; error?: unknown };

    if (!isSyncStatus(status)) {
        throw new Error(`sync-status: unexpected status ${JSON.stringify(status)}`);
    }

    if (error !== null && error !== undefined && typeof error !== 'string') {
        throw new Error('sync-status: error was neither a string nor null');
    }

    return { status, error: error ?? null };
}

export interface SyncStatusReconcilerOptions {
    /**
     * The status the page is displaying right now. Read afresh before every request and
     * again after each response, so a broadcast that lands mid-flight wins and the poll
     * stands down instead of overwriting it with an older read.
     *
     * **Invariant: whatever `apply()` writes must be readable back through `current()`.**
     * There is deliberately no "was the snapshot I just applied terminal?" branch — this
     * is the single place a terminal status is recognised, and `schedule()` consults it
     * right after `apply()`. Wiring `apply` to somewhere `current` does not read is
     * therefore not merely redundant, it disables the stop condition: the reconciler would
     * poll out its whole 900-second budget on a package that finished in a second.
     */
    current: () => SyncStatus;
    /** Asks the server. A rejection means "unknown" and costs one attempt, not the run. */
    read: () => Promise<SyncStatusSnapshot>;
    /** Applies a snapshot. Never called with an answer the display has already overtaken. */
    apply: (snapshot: SyncStatusSnapshot) => void;
    /**
     * Called once when the attempt budget runs out with the status still non-terminal.
     *
     * Giving up silently is the failure mode this exists to prevent: for anyone who cannot
     * join the `operator` channel, or on a build with no Reverb key, the badge would simply
     * freeze on "Wartet"/"Läuft…" with nothing to say the page had stopped looking. The
     * page turns this into a visible note telling the operator to reload.
     */
    onGiveUp?: () => void;
    /** Overridable for tests. */
    delays?: readonly number[];
}

export interface SyncStatusReconciler {
    /** No-op when the page opened on a terminal status, and when already started. */
    start(): void;
    /**
     * Re-arms from scratch: fresh attempt budget, running again even if it had stopped or
     * given up. Any request still in flight from the previous run is orphaned.
     *
     * The page needs this because Inertia sets `preserveState: true` for post/put/patch/
     * delete, so the component is *not* remounted after "Erneut synchronisieren" — the
     * server re-renders the page with `sync_status` back at `pending`, and without a
     * restart the badge would sit on the old terminal value for good.
     */
    restart(): void;
    /** Idempotent, and final for `start()` — only `restart()` revives a stopped run. */
    stop(): void;
    /** Whether another request is still scheduled. */
    readonly running: boolean;
    /** Whether the run ended by exhausting its budget rather than by reaching an answer. */
    readonly gaveUp: boolean;
}

export function createSyncStatusReconciler(options: SyncStatusReconcilerOptions): SyncStatusReconciler {
    const delays = options.delays ?? SYNC_POLL_DELAYS_MS;

    let attempt = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let started = false;
    let stopped = false;
    let gaveUp = false;
    // Bumped by stop() and restart(). A response that resolves after either of those
    // belongs to a run that is over, and is dropped instead of applied or rescheduled.
    let generation = 0;

    function clearTimer(): void {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }
    }

    function stop(): void {
        stopped = true;
        generation++;
        clearTimer();
    }

    function schedule(generationAtStart: number): void {
        if (generationAtStart !== generation || stopped) {
            return;
        }

        // A broadcast settled it, or the snapshot just applied did.
        if (isTerminalSyncStatus(options.current())) {
            stop();

            return;
        }

        if (attempt >= delays.length) {
            gaveUp = true;
            stop();
            options.onGiveUp?.();

            return;
        }

        const delay = delays[attempt++];
        timer = setTimeout(() => void tick(generationAtStart), delay);
    }

    async function tick(generationAtStart: number): Promise<void> {
        timer = undefined;

        if (generationAtStart !== generation || stopped) {
            return;
        }

        if (isTerminalSyncStatus(options.current())) {
            stop();

            return;
        }

        try {
            const snapshot = await options.read();

            // Unmounted, restarted, or a broadcast beat the response home. The other fact
            // is the newer one, so the answer is dropped rather than regressing the badge.
            if (generationAtStart !== generation || stopped) {
                return;
            }

            if (isTerminalSyncStatus(options.current())) {
                stop();

                return;
            }

            options.apply(snapshot);
        } catch {
            // A hiccup (offline, 500, an expired session, a body that did not parse) is not
            // a reason to stop looking; the attempt is spent, so a persistent failure still
            // runs out of budget and ends in the give-up notice.
        }

        // No explicit "was that terminal?" branch: `schedule()` re-reads the displayed
        // status, which by now includes the snapshot just applied, and stands down there.
        schedule(generationAtStart);
    }

    return {
        start(): void {
            if (started || stopped) {
                return;
            }
            started = true;

            // No terminal check here either: `schedule()` stands down without arming a
            // timer when the status is already terminal, so a page rendered with the final
            // answer never sends a request at all.
            schedule(generation);
        },
        restart(): void {
            clearTimer();
            generation++;
            attempt = 0;
            stopped = false;
            gaveUp = false;
            started = true;
            schedule(generation);
        },
        stop,
        get running(): boolean {
            return started && !stopped;
        },
        get gaveUp(): boolean {
            return gaveUp;
        },
    };
}
