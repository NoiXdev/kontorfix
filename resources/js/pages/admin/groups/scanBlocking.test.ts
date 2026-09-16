import { describe, expect, it } from 'vitest';
import { hiddenArtifacts, parseScanPreview, scanBlockingPayload } from './scanBlocking';

describe('parseScanPreview', () => {
    it('parses a well-formed preview response', () => {
        const artifact = {
            package: 'meinapp',
            digest: 'sha256:abc',
            tags: ['latest'],
            vulnerability_id: 'CVE-2026-1',
            severity: 'critical',
            severity_label: 'Kritisch',
            blocked: true,
            blocks_at: '2026-01-01',
        };

        expect(parseScanPreview({ blocking_now: 1, blocking_later: 2, artifacts_total: 3, artifacts: [artifact] })).toEqual({
            blocking_now: 1,
            blocking_later: 2,
            artifacts_total: 3,
            artifacts: [artifact],
        });
    });

    it('falls back to the list length when the body names no total', () => {
        // Never 0: `hiddenArtifacts()` would then report a negative remainder, which reads
        // as "we are showing more artifacts than exist".
        const artifact = { digest: 'sha256:abc' } as never;

        expect(parseScanPreview({ artifacts: [artifact, artifact] }).artifacts_total).toBe(2);
    });

    it('defaults every field rather than throwing on a malformed body', () => {
        expect(parseScanPreview({})).toEqual({ blocking_now: 0, blocking_later: 0, artifacts_total: 0, artifacts: [] });
        expect(parseScanPreview({ blocking_now: 'not a number', artifacts: 'not an array' })).toEqual({
            blocking_now: 0,
            blocking_later: 0,
            artifacts_total: 0,
            artifacts: [],
        });
    });
});

describe('hiddenArtifacts', () => {
    it('reports how many qualifying artifacts the capped list leaves out', () => {
        // `ScanBlockGuard::preview()` names at most fifty artifacts however many qualify, so
        // the screen has to say so — a list of fifty must not read as the whole answer.
        expect(hiddenArtifacts({ blocking_now: 60, blocking_later: 0, artifacts_total: 60, artifacts: new Array(50).fill({}) as never })).toBe(10);
    });

    it('never claims a negative remainder', () => {
        expect(hiddenArtifacts({ blocking_now: 1, blocking_later: 0, artifacts_total: 0, artifacts: [{}] as never })).toBe(0);
    });
});

describe('scanBlockingPayload', () => {
    it('carries the severity and the grace days as a number', () => {
        expect(scanBlockingPayload('high', 14)).toEqual({ scan_block_severity: 'high', scan_block_grace_days: 14 });
        expect(scanBlockingPayload('high', '14')).toEqual({ scan_block_severity: 'high', scan_block_grace_days: 14 });
    });

    it('sends a cleared field as a value `required` rejects, never as 0', () => {
        // A blank field is absence, not zero. Number('') is 0 — the single input that would
        // otherwise silently become the STRICTEST legal setting ("block as soon as a finding
        // is recorded") instead of falling into the required/integer validation net.
        expect(scanBlockingPayload('high', '')).toEqual({ scan_block_severity: 'high', scan_block_grace_days: null });
        // Whitespace-only counts as blank too — an operator who selected-all and typed a
        // space has not entered a value either.
        expect(scanBlockingPayload('high', '   ')).toEqual({ scan_block_severity: 'high', scan_block_grace_days: null });
    });

    it('still converts a genuinely typed zero to the number 0', () => {
        // 0 stays reachable — typing it is not blank, and it is a legal, meaningful value
        // (see UpdateScanBlockingRequest's own comment on `min:0`).
        expect(scanBlockingPayload('high', '0')).toEqual({ scan_block_severity: 'high', scan_block_grace_days: 0 });
        expect(scanBlockingPayload('high', 0)).toEqual({ scan_block_severity: 'high', scan_block_grace_days: 0 });
    });

    it('carries a null severity through unchanged — "do not block" is a real value', () => {
        expect(scanBlockingPayload(null, 7)).toEqual({ scan_block_severity: null, scan_block_grace_days: 7 });
    });
});
