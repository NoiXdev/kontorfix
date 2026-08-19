<?php

use Tests\Support\TestTempDir;

// The fixture suites clean up with glob(sys_get_temp_dir().'/kfx-fixture-*'), which
// matches every checkout's fixtures. Pointing the process at a private temp directory
// is what keeps a concurrent run from deleting repositories this one is still using —
// and it covers the temp files the code under test creates too, because those go
// through sys_get_temp_dir() as well.

it('runs inside a temp directory of its own', function () {
    expect(sys_get_temp_dir())->toBe(TestTempDir::pathFor(base_path()))
        ->and(is_dir(sys_get_temp_dir()))->toBeTrue();
});

it('gives a different temp directory to a different checkout', function () {
    expect(TestTempDir::pathFor('/home/dev/app'))
        ->not->toBe(TestTempDir::pathFor('/home/dev/app/.claude/worktrees/feature'));
});

it('keeps that directory inside the real system temp directory rather than replacing it', function () {
    expect(dirname(TestTempDir::pathFor('/home/dev/app')))
        ->toBe(rtrim(TestTempDir::systemTemp(), '/'));
});

// pathFor() has to resolve against the temp directory the system gave us, not the one
// it redirected us to, or each call would nest another level deeper.
it('does not nest a further directory once the process is already isolated', function () {
    expect(TestTempDir::pathFor(base_path()))->toBe(sys_get_temp_dir());
});
