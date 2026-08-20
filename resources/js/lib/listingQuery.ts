/**
 * Query-string merging for listings whose state lives in the URL.
 *
 * Every control on a listing owns a couple of query keys and nothing else: a filter bar
 * owns its filters, a sort header owns `sort`/`direction`, a page-size selector owns
 * `per_page`. Rebuilding the whole query from the control that happened to change is how
 * state silently disappears — on `admin/activity` the log-name filter used to reconstruct
 * the query by hand and had to read `sort` back out of the URL to avoid resetting the
 * order, a workaround that would need repeating for every key added afterwards.
 *
 * So: start from what is in the URL, overwrite only the keys handed in, and drop the ones
 * whose new value is `undefined`.
 */
export function mergeQuery(updates: Record<string, string | undefined>, search?: string): Record<string, string> {
    const query: Record<string, string> = {};

    const current = search ?? (typeof window === 'undefined' ? '' : window.location.search);
    for (const [key, value] of new URLSearchParams(current)) {
        query[key] = value;
    }

    // Every caller changes what the rows are, how many there are, or what order they come
    // in, so the current page number no longer points at the same rows. Paging restarts
    // rather than landing on a page that may not even exist any more.
    delete query.page;

    // An empty string removes the key rather than writing `?log=`: for listing state the
    // two mean the same thing — no filter — and only one of them produces a shareable URL.
    for (const [key, value] of Object.entries(updates)) {
        if (value === undefined || value === '') {
            delete query[key];
            continue;
        }
        query[key] = value;
    }

    return query;
}
