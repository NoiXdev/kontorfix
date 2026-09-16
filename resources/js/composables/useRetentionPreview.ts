import { onBeforeUnmount, ref, type Ref } from 'vue';
import { createPreviewEngine } from './previewEngine';
import { postPreviewJson } from './previewTransport';

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
 *
 * The debounce/abort machinery itself lives in `./previewEngine` — generalised out of this
 * function when the scan-blocking preview became a second caller of the same state machine.
 * This function keeps its OWN return shape (`summary`/`tags`, not a generic `result`)
 * unchanged on purpose: this is the untouched regression gate for that extraction
 * (`useRetentionPreview.test.ts`), and a generic Ref this function merely re-exported would
 * have been a behaviour change the test suite exists to catch, not a refactor.
 */
export function createRetentionPreview(options: { debounceMs?: number } = {}): RetentionPreviewCore {
    const summary = ref<string[]>([]);
    const tags = ref<RetentionPreviewTag[] | null>(null);
    const error = ref<string | null>(null);
    const loading = ref(false);

    const engine = createPreviewEngine<RetentionPreviewResult>(
        {
            onResult: (result) => {
                summary.value = result.summary;
                tags.value = result.tags;
                error.value = null;
            },
            onError: (message) => {
                error.value = message;
            },
            onLoadingChange: (value) => {
                loading.value = value;
            },
        },
        { debounceMs: options.debounceMs, fallbackMessage: 'Der Probelauf ist fehlgeschlagen.' },
    );

    return { summary, tags, error, loading, schedule: engine.schedule, runNow: engine.runNow, cancel: engine.cancel };
}

/**
 * The one non-Inertia POST both preview endpoints are reached through: same headers, same
 * CSRF cookie, same "the 422 body's `message` is the error text" contract. Exported so a
 * page only has to name its URL and body, not repeat the fetch plumbing. The transport
 * itself lives in `./previewTransport` — shared with the scan-blocking preview, the second
 * caller that made this worth extracting.
 */
export async function postRetentionPreview(url: string, body: Record<string, unknown>, signal: AbortSignal): Promise<RetentionPreviewResult> {
    const record = await postPreviewJson(url, body, signal, 'Der Probelauf ist fehlgeschlagen.');

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
