import { onBeforeUnmount, ref, type Ref } from 'vue';

/**
 * One tag's verdict under the rule set currently being tried out — the same shape both
 * preview endpoints answer with (`App\Support\Retention\RetentionDecision::toArray()`), so
 * this type is not restated per page.
 */
export interface RetentionPreviewTag {
    name: string;
    pushed_at: string | null;
    keep: boolean;
    reason: string | null;
}

/**
 * A preview endpoint's whole answer: the submitted rules' German summary
 * (`RetentionRule::describe()`, never re-implemented here) always, and the tag-by-tag
 * verdict only once a repository was named to try the rules against.
 */
export interface RetentionPreviewResult {
    summary: string[];
    tags: RetentionPreviewTag[] | null;
}

/** A request in flight, parameterised by the AbortSignal that cancels it. */
export type RetentionPreviewRequest = (signal: AbortSignal) => Promise<RetentionPreviewResult>;

export interface RetentionPreviewCore {
    summary: Ref<string[]>;
    tags: Ref<RetentionPreviewTag[] | null>;
    /** The 422 body's message, or a generic failure sentence — never both a result and an error at once. */
    error: Ref<string | null>;
    loading: Ref<boolean>;
    /**
     * Debounced: waits out `debounceMs` of silence before actually asking the server, and
     * aborts whichever request from an earlier call is still in flight — a keystroke never
     * has to wait for a stale response it no longer cares about, and a stale response can
     * never overwrite a newer one that raced ahead of it.
     */
    schedule: (request: RetentionPreviewRequest) => void;
    /** Same abort-the-previous guarantee as `schedule`, but skips the debounce wait. */
    runNow: (request: RetentionPreviewRequest) => Promise<void>;
    /** Drops any pending debounce timer and aborts an in-flight request without starting another. */
    cancel: () => void;
}

/**
 * The lifecycle-free core, so it can be unit-tested — the same split
 * `usePackageSyncStatus.ts` uses: this repo's vitest runs in a `node` environment with no
 * component-rendering harness, so anything behind `onMounted`/`onBeforeUnmount` is
 * untestable, and the debounce/abort behaviour is exactly the part worth a test.
 *
 * Shared by the retention policy form's Probelauf panel (a picked repository, or none yet —
 * see the endpoint's optional `package_id`) and the package page's inline-rules editor (the
 * package IS the repository, no picker) — one debounce implementation, not two.
 */
export function createRetentionPreview(options: { debounceMs?: number } = {}): RetentionPreviewCore {
    const debounceMs = options.debounceMs ?? 600;

    const summary = ref<string[]>([]);
    const tags = ref<RetentionPreviewTag[] | null>(null);
    const error = ref<string | null>(null);
    const loading = ref(false);

    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;

    async function run(request: RetentionPreviewRequest): Promise<void> {
        // Aborting the PREVIOUS controller, not this call's own: two rapid schedule() calls
        // must not race, and the second one's response is the only one allowed to land.
        controller?.abort();
        const own = new AbortController();
        controller = own;
        loading.value = true;

        try {
            const result = await request(own.signal);

            // A response can arrive after its own controller was aborted (the abort raced
            // the fetch's own resolution) — discarded either way, since a newer request has
            // already taken over `controller`.
            if (own.signal.aborted) {
                return;
            }

            summary.value = result.summary;
            tags.value = result.tags;
            error.value = null;
        } catch (err) {
            if (own.signal.aborted || (err instanceof DOMException && err.name === 'AbortError')) {
                return;
            }

            error.value = err instanceof Error ? err.message : 'Der Probelauf ist fehlgeschlagen.';
        } finally {
            if (!own.signal.aborted) {
                loading.value = false;
            }
        }
    }

    function schedule(request: RetentionPreviewRequest): void {
        if (timer !== undefined) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            timer = undefined;
            void run(request);
        }, debounceMs);
    }

    async function runNow(request: RetentionPreviewRequest): Promise<void> {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }

        await run(request);
    }

    function cancel(): void {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }

        controller?.abort();

        // Safe even when nothing was in flight: every run() sets loading back to true, so
        // this can never mask a request that is genuinely still pending.
        loading.value = false;
    }

    return { summary, tags, error, loading, schedule, runNow, cancel };
}

/** Reads the CSRF cookie Sanctum/Laravel sets, the same way `Form.vue`'s prior hand-rolled fetch did. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The one non-Inertia POST both preview endpoints are reached through: same headers, same
 * CSRF cookie, same "the 422 body's `message` is the error text" contract. Exported so a
 * page only has to name its URL and body, not repeat the fetch plumbing.
 */
export async function postRetentionPreview(url: string, body: Record<string, unknown>, signal: AbortSignal): Promise<RetentionPreviewResult> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
        signal,
    });

    const data: unknown = await response.json().catch(() => null);
    const record = data !== null && typeof data === 'object' ? (data as Record<string, unknown>) : {};

    if (!response.ok) {
        throw new Error(typeof record.message === 'string' ? record.message : 'Der Probelauf ist fehlgeschlagen.');
    }

    return {
        summary: Array.isArray(record.summary) ? (record.summary as string[]) : [],
        tags: Array.isArray(record.tags) ? (record.tags as RetentionPreviewTag[]) : null,
    };
}

/** The Vue-lifecycle wrapper: the core above, plus tearing it down when the page unmounts. */
export function useRetentionPreview(options: { debounceMs?: number } = {}): RetentionPreviewCore {
    const core = createRetentionPreview(options);

    onBeforeUnmount(core.cancel);

    return core;
}
