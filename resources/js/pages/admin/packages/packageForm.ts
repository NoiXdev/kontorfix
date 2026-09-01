import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

export interface PackageFormData {
    type: 'composer' | 'npm' | 'python';
    source_mode: 'publish' | 'git';
    name: string;
    repository_url: string;
    is_private: boolean;
    repository_token: string;
    git_credential_id: string;
    group_ids: string[];
}

// Create.vue owns the Inertia form — it still needs `form.processing` and `form.post(...)`
// for its own submit button — and `provide`s it under this key. Form.vue `inject`s it
// instead of receiving it as a prop, so writing into its fields (`v-model="form.xxx"`) is
// never prop mutation: the repo-wide `vue/no-mutating-props` rule stays at its default
// everywhere, including here.
export const packageFormKey: InjectionKey<InertiaForm<PackageFormData>> = Symbol('package-form');

export interface GroupOption {
    id: string;
    name: string;
    slug: string;
}

export interface GitCredentialOption {
    id: string;
    name: string;
    provider: string;
}

export type SourceModeMap = Record<string, { value: string; label: string }[]>;

export interface ProbeResult {
    ok: boolean;
    error?: string;
    name?: string | null;
    description?: string | null;
    versions: string[];
}

/** The modes a given package type actually allows, per PackageSourceMode::allowedFor() on
 * the server — the single source of truth. */
export function modesFor(sourceModes: SourceModeMap, type: string): { value: string; label: string }[] {
    return sourceModes[type] ?? [];
}

/** Rendered/offered only for a type with more than one allowed mode (today: Python).
 * Composer is always git, npm always publish — offering either a choice would offer one the
 * server refuses. Shared between Form.vue (which renders the selector) and Create.vue (which
 * drops the field from the submitted payload when there is no real choice to make). */
export function canChooseSourceMode(sourceModes: SourceModeMap, type: string): boolean {
    return modesFor(sourceModes, type).length > 1;
}

/** A type whose only/default mode is git (Composer today) is always git-mode, regardless of
 * what `sourceModeValue` happens to hold (its selector is hidden for such a type, so nothing
 * sets it deliberately). Derived from modesFor rather than a hardcoded 'composer' check, so
 * this stays correct if allowedFor() ever changes which type that is. Shared between Form.vue
 * (which drives the git-only fields and the probe action) and Create.vue (which gates
 * submission on it). */
export function isGitMode(sourceModes: SourceModeMap, type: string, sourceModeValue: string): boolean {
    return modesFor(sourceModes, type)[0]?.value === 'git' || sourceModeValue === 'git';
}

/** The only message that invites another attempt. Reserved for failures another attempt can
 * actually fix — a dropped connection or a server-side error. Telling an operator to retry a
 * rejected URL or an expired session is an instruction that can never succeed. */
export const PROBE_RETRY_MESSAGE = 'Prüfung fehlgeschlagen — bitte erneut versuchen.';

/** The part of `fetch`'s Response that reading a failure needs. Declared structurally so the
 * helper is callable from a plain unit test without a DOM. */
export interface ProbeResponseLike {
    status: number;
    json(): Promise<unknown>;
}

export interface ProbeFailure {
    /** Per-field validation messages (422 only), keyed by field name, first message per
     * field — the shape PackagePicker.vue binds its per-field InputErrors to. */
    errors: Record<string, string>;
    /** One line for a general error banner. For a 422 this is the first field message, so a
     * caller with nowhere to put field errors still shows the real reason. */
    message: string;
}

function firstMessage(messages: unknown): string {
    return Array.isArray(messages) ? String(messages[0]) : String(messages);
}

/**
 * Turns a failed POST /admin/packages/probe into something an operator can act on.
 *
 * The endpoint fails in shapes that call for different answers, and collapsing them into one
 * "bitte erneut versuchen" is what made a rejected repository URL undiagnosable: the create
 * mask gates „Anlegen" on a successful probe, so a permanent rejection reported as a
 * transient one leaves the button disabled forever with no way to learn why. A git-level
 * failure is not handled here at all — that returns HTTP 200 with `{ok: false, error}` and
 * is already displayed verbatim.
 *
 * Pass `null` for a thrown fetch (no response at all — DNS, offline, aborted connection).
 */
export async function describeProbeFailure(response: ProbeResponseLike | null): Promise<ProbeFailure> {
    // No response: the request never completed. Genuinely transient.
    if (response === null) {
        return { errors: {}, message: PROBE_RETRY_MESSAGE };
    }

    if (response.status === 422) {
        const body = (await readJson(response)) as { errors?: Record<string, unknown>; message?: unknown } | null;
        const errors: Record<string, string> = {};
        for (const [field, messages] of Object.entries(body?.errors ?? {})) {
            errors[field] = firstMessage(messages);
        }
        const first = Object.values(errors)[0];
        return {
            errors,
            // A 422 without a usable `errors` bag is not something a retry fixes either, so
            // it does not fall through to the retry message.
            message: first ?? (typeof body?.message === 'string' ? body.message : 'Die Angaben wurden abgelehnt — bitte die Eingaben prüfen.'),
        };
    }

    if (response.status === 403) {
        return { errors: {}, message: 'Keine Berechtigung: Dieses Konto darf Repositories nicht prüfen.' };
    }

    // Laravel's expired-session status. The CSRF token in the page is stale, so every further
    // request from this page fails the same way until it is reloaded.
    if (response.status === 419) {
        return { errors: {}, message: 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden und erneut anmelden.' };
    }

    // The endpoint is rate-limited (throttle:10,1). Waiting, not retrying at once, is the fix.
    if (response.status === 429) {
        return { errors: {}, message: 'Zu viele Prüfungen in kurzer Zeit — bitte kurz warten.' };
    }

    // A server-side fault may well be gone on the next attempt.
    if (response.status >= 500) {
        return { errors: {}, message: PROBE_RETRY_MESSAGE };
    }

    return { errors: {}, message: `Prüfung nicht möglich (HTTP ${response.status}).` };
}

async function readJson(response: ProbeResponseLike): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        // A body that is not JSON (an HTML error page from a proxy, say) must not turn a
        // readable failure into an unhandled rejection.
        return null;
    }
}
