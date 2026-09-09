import { describe, expect, it } from 'vitest';
import {
    describeManifestOutcome,
    describeProbeFailure,
    isGitMode,
    isMirrorMode,
    PROBE_RETRY_MESSAGE,
    type ProbeResponseLike,
    type ProbeResult,
    type SourceModeMap,
} from './packageForm';

/** Mirrors PackageSourceMode::allowedFor() for the three source-having types. */
const sourceModes: SourceModeMap = {
    composer: [
        { value: 'git', label: 'Git-Mirror' },
        { value: 'mirror', label: 'Mirror (fremde Registry)' },
    ],
    npm: [
        { value: 'publish', label: 'Publish (Push)' },
        { value: 'mirror', label: 'Mirror (fremde Registry)' },
    ],
    python: [
        { value: 'publish', label: 'Publish (Push)' },
        { value: 'git', label: 'Git-Mirror' },
        { value: 'mirror', label: 'Mirror (fremde Registry)' },
    ],
    docker: [{ value: 'publish', label: 'Publish (Push)' }],
};

/** A stand-in for the part of `fetch`'s Response the helper reads. */
function response(status: number, body?: unknown): ProbeResponseLike {
    return {
        status,
        json: () => (body === undefined ? Promise.reject(new SyntaxError('Unexpected end of JSON input')) : Promise.resolve(body)),
    };
}

describe('describeProbeFailure', () => {
    // The reported bug: POST /admin/packages/probe answers a rejected repository URL with a
    // 422 whose body names the reason, the create mask threw the body away, and „Anlegen"
    // stays disabled until a probe succeeds — so the operator was told to retry something
    // that could never work.
    it('surfaces the field message from a 422 body', async () => {
        const failure = await describeProbeFailure(
            response(422, {
                message: 'The given data was invalid.',
                errors: { repository_url: ['Die Repository-URL muss mit https:// oder ssh:// beginnen.', 'Zweite Meldung.'] },
            }),
        );

        expect(failure.errors).toEqual({ repository_url: 'Die Repository-URL muss mit https:// oder ssh:// beginnen.' });
        expect(failure.message).toBe('Die Repository-URL muss mit https:// oder ssh:// beginnen.');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
    });

    it('maps every field of a 422 body to its first message', async () => {
        const failure = await describeProbeFailure(response(422, { errors: { repository_url: ['URL ungültig.'], type: ['Typ ungültig.'] } }));

        expect(failure.errors).toEqual({ repository_url: 'URL ungültig.', type: 'Typ ungültig.' });
    });

    // A permanent failure reported as a transient one is the bug itself, so each of these
    // has to be its own message and none of them may invite another attempt.
    it('reports a 403 as a missing permission, without suggesting a retry', async () => {
        const failure = await describeProbeFailure(response(403, { message: 'This action is unauthorized.' }));

        expect(failure.message).toContain('Keine Berechtigung');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
        expect(failure.message).not.toContain('erneut versuchen');
        expect(failure.errors).toEqual({});
    });

    it('reports a 419 as an expired session that needs a reload, without suggesting a retry', async () => {
        const failure = await describeProbeFailure(response(419, { message: 'CSRF token mismatch.' }));

        expect(failure.message).toContain('Sitzung ist abgelaufen');
        expect(failure.message).toContain('neu laden');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
        expect(failure.message).not.toContain('erneut versuchen');
    });

    it('gives 403 and 419 distinct messages', async () => {
        const forbidden = await describeProbeFailure(response(403, {}));
        const expired = await describeProbeFailure(response(419, {}));

        expect(forbidden.message).not.toBe(expired.message);
    });

    // The two cases another attempt can actually fix.
    it('suggests a retry for a server error and for a thrown fetch', async () => {
        expect((await describeProbeFailure(response(500, {}))).message).toBe(PROBE_RETRY_MESSAGE);
        expect((await describeProbeFailure(null)).message).toBe(PROBE_RETRY_MESSAGE);
    });

    it('tells a rate-limited caller to wait rather than to retry at once', async () => {
        const failure = await describeProbeFailure(response(429, {}));

        expect(failure.message).toContain('warten');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
    });

    it('names an unexpected status instead of suggesting a retry', async () => {
        const failure = await describeProbeFailure(response(404, {}));

        expect(failure.message).toContain('404');
        expect(failure.message).not.toBe(PROBE_RETRY_MESSAGE);
    });

    // A proxy answering with an HTML error page must not turn a readable failure into an
    // unhandled rejection.
    it('survives a body that is not json', async () => {
        const failure = await describeProbeFailure(response(422));

        expect(failure.errors).toEqual({});
        expect(failure.message).toContain('abgelehnt');
    });
});

describe('isGitMode / isMirrorMode', () => {
    // Composer's only allowed mode is git — its selector is hidden entirely (canChooseSourceMode
    // is false), so nothing ever sets `source_mode` for it deliberately; isGitMode must still
    // answer true from the type's default alone.
    it('treats composer as git-mode even with no explicit source_mode', () => {
        expect(isGitMode(sourceModes, 'composer', '')).toBe(true);
        expect(isMirrorMode(sourceModes, 'composer', '')).toBe(false);
    });

    it('is mirror-mode only when explicitly selected — never a type default', () => {
        expect(isMirrorMode(sourceModes, 'composer', 'mirror')).toBe(true);
        expect(isMirrorMode(sourceModes, 'npm', 'mirror')).toBe(true);
        expect(isMirrorMode(sourceModes, 'python', 'mirror')).toBe(true);
    });

    it('is neither git nor mirror for a publish-mode npm/python package', () => {
        expect(isGitMode(sourceModes, 'npm', 'publish')).toBe(false);
        expect(isMirrorMode(sourceModes, 'npm', 'publish')).toBe(false);
        expect(isGitMode(sourceModes, 'python', 'publish')).toBe(false);
        expect(isMirrorMode(sourceModes, 'python', 'publish')).toBe(false);
    });

    it('is git-mode for a python package with source_mode explicitly set to git', () => {
        expect(isGitMode(sourceModes, 'python', 'git')).toBe(true);
        expect(isMirrorMode(sourceModes, 'python', 'git')).toBe(false);
    });
});

describe('describeManifestOutcome', () => {
    /** A successful probe, with the manifest fields the server now sends. */
    function probe(extra: Partial<ProbeResult> = {}): ProbeResult {
        return { ok: true, versions: [], manifest: 'ok', manifest_file: 'composer.json', ...extra };
    }

    // The reported bug: „Prüfen" answered a private Composer repository with „Repository
    // erreichbar" and left the name field empty, because the manifest read had failed
    // unauthenticated behind the scenes. A repository that simply has no manifest looked
    // exactly the same, and the two need opposite responses from the operator.
    it('says the manifest could not be read, and points at access', () => {
        const note = describeManifestOutcome(probe({ manifest: 'unreadable', name: null }));

        expect(note?.tone).toBe('warning');
        expect(note?.text).toContain('composer.json');
        expect(note?.text).toContain('Zugriff');
    });

    it('distinguishes a repository that simply has no manifest', () => {
        const note = describeManifestOutcome(probe({ manifest: 'missing', name: null }));

        expect(note?.tone).toBe('info');
        expect(note?.text).toContain('Keine composer.json');
        // Not the access advice: nothing is broken here, the operator just types the name.
        expect(note?.text).not.toContain('Zugriff');
    });

    it('stays silent when the name was discovered', () => {
        expect(describeManifestOutcome(probe({ name: 'acme/demo' }))).toBeNull();
    });

    it('reports a manifest that was read but carries no name', () => {
        const note = describeManifestOutcome(probe({ name: null }));

        expect(note?.tone).toBe('info');
        expect(note?.text).toContain('keinen Namen');
    });

    it('adds nothing to a failed probe — the error banner owns that', () => {
        expect(describeManifestOutcome({ ok: false, error: 'Repository nicht erreichbar.', versions: [] })).toBeNull();
    });

    it('names the manifest by whatever the server said it looked for', () => {
        const note = describeManifestOutcome(probe({ manifest: 'missing', manifest_file: 'pyproject.toml', name: null }));

        expect(note?.text).toContain('pyproject.toml');
    });
});
