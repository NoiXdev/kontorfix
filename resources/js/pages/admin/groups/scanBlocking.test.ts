import { describe, expect, it } from 'vitest';
import { parseScanPreview, scanBlockingPayload } from './scanBlocking';

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

        expect(parseScanPreview({ blocking_now: 1, blocking_later: 2, artifacts: [artifact] })).toEqual({
            blocking_now: 1,
            blocking_later: 2,
            artifacts: [artifact],
        });
    });

    it('defaults every field rather than throwing on a malformed body', () => {
        expect(parseScanPreview({})).toEqual({ blocking_now: 0, blocking_later: 0, artifacts: [] });
        expect(parseScanPreview({ blocking_now: 'not a number', artifacts: 'not an array' })).toEqual({
            blocking_now: 0,
            blocking_later: 0,
            artifacts: [],
        });
    });
});

describe('scanBlockingPayload', () => {
    it('carries the severity and the grace days as a number', () => {
        expect(scanBlockingPayload('high', 14)).toEqual({ scan_block_severity: 'high', scan_block_grace_days: 14 });
        expect(scanBlockingPayload('high', '14')).toEqual({ scan_block_severity: 'high', scan_block_grace_days: 14 });
    });

    it('never round-trips a cleared field as the literal string ""', () => {
        // Number('') is 0, a legal grace period — not the empty string the Input component's
        // v-model would otherwise send.
        expect(scanBlockingPayload('high', '')).toEqual({ scan_block_severity: 'high', scan_block_grace_days: 0 });
    });

    it('carries a null severity through unchanged — "do not block" is a real value', () => {
        expect(scanBlockingPayload(null, 7)).toEqual({ scan_block_severity: null, scan_block_grace_days: 7 });
    });
});
