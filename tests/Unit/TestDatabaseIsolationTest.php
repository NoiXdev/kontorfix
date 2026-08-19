<?php

use Tests\Support\TestDatabase;

// Every checkout — the main clone and each git worktree — used to share the one
// `testing` database hard-coded in phpunit.xml. Two suites running at once therefore
// ran two `migrate:fresh` against the same schema, which deadlocks (SQLSTATE 40P01)
// and leaves the schema half-built, so every later test that touches a dropped table
// fails with 42P01/42P07. The suite is only deterministic if a run cannot collide
// with another run, so the database name is derived from the working directory.

it('derives a distinct database name per checkout', function () {
    expect(TestDatabase::nameFor('/home/dev/app'))
        ->not->toBe(TestDatabase::nameFor('/home/dev/app/.claude/worktrees/feature'));
});

it('derives a stable name for the same checkout', function () {
    expect(TestDatabase::nameFor('/home/dev/app'))->toBe(TestDatabase::nameFor('/home/dev/app'));
});

it('produces a name Postgres accepts as an unquoted identifier', function () {
    $name = TestDatabase::nameFor('/home/dev/Weird Name (2)/.claude/worktrees/äöü-123');

    expect($name)->toMatch('/^[a-z][a-z0-9_]*$/')
        ->and(strlen($name))->toBeLessThanOrEqual(63);
});

it('keeps the checkout recognisable in the name', function () {
    expect(TestDatabase::nameFor('/home/dev/app/.claude/worktrees/unruffled-matsumoto'))
        ->toContain('unruffled_matsumoto');
});

// CI runs one suite per container against a database the service already created, so
// an explicitly configured name must win — deriving one there would point the suite at
// a database nobody provisioned.
it('yields to an explicitly configured database name', function () {
    expect(TestDatabase::resolve('/home/dev/app', 'testing'))->toBe('testing');
});

it('derives a name only when none was configured', function () {
    expect(TestDatabase::resolve('/home/dev/app', null))->toBe(TestDatabase::nameFor('/home/dev/app'))
        ->and(TestDatabase::resolve('/home/dev/app', ''))->toBe(TestDatabase::nameFor('/home/dev/app'));
});
