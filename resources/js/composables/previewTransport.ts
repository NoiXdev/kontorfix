/**
 * The one non-Inertia POST every preview endpoint is reached through.
 *
 * Extracted from `useRetentionPreview.ts` when the scan-blocking preview became the second
 * caller. Only the TRANSPORT is shared — the CSRF cookie, the headers, the "a 422 body's
 * `message` is the error text" contract. Each preview keeps its own result type, because
 * those genuinely differ; what must not be restated is the plumbing.
 */

/** Reads the CSRF cookie Sanctum/Laravel sets. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function postPreviewJson(
    url: string,
    body: Record<string, unknown>,
    signal: AbortSignal,
    fallbackMessage = 'Die Vorschau ist fehlgeschlagen.',
): Promise<Record<string, unknown>> {
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
        throw new Error(typeof record.message === 'string' ? record.message : fallbackMessage);
    }

    return record;
}
