<?php

namespace Tests\Support;

/**
 * A scratch directory that belongs to this checkout alone.
 *
 * The fixtures live in the system temp directory, and the suites that build them clean
 * up with `glob(sys_get_temp_dir().'/kfx-fixture-*')`. That pattern matches every
 * checkout's fixtures, so a suite running at the same time from another checkout
 * deletes repositories this one is still cloning — `git init` fails with "cannot copy …
 * No such file or directory", and a mirror walk dies on a directory that vanished
 * mid-iteration.
 *
 * Rather than route every call site through here, the bootstrap points TMPDIR at this
 * directory: sys_get_temp_dir() follows it, so every temp path in the process — the
 * fixtures *and* the stubs the code under test creates with tempnam() — lands inside a
 * namespace no other checkout can see, and the existing glob patterns keep working.
 *
 * Same reasoning as Tests\Support\TestDatabase: concurrent runs must not be able to
 * reach into each other's state.
 */
final class TestTempDir
{
    /**
     * The temp directory we started from, so pathFor() does not nest a second directory
     * inside the one it just created.
     */
    private static ?string $systemTemp = null;

    /**
     * Points the process's temp directory at this checkout's own, creating it if needed.
     *
     * Must run before anything asks for a temp path — PHP resolves sys_get_temp_dir()
     * once and caches it for the rest of the request.
     */
    public static function isolate(string $root): void
    {
        $dir = self::pathFor($root);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        putenv("TMPDIR={$dir}");
        $_ENV['TMPDIR'] = $dir;
        $_SERVER['TMPDIR'] = $dir;
    }

    /** Stable per checkout, and always a child of the real system temp directory. */
    public static function pathFor(string $root): string
    {
        return self::systemTemp().'/kontorfix-tests-'.substr(sha1($root), 0, 8);
    }

    /**
     * The temp directory in effect before this class redirected TMPDIR.
     *
     * Read from the environment rather than from sys_get_temp_dir(): PHP resolves that
     * function once and caches the answer for the rest of the process, so asking it here
     * would pin the *old* directory and make the redirect below a no-op.
     */
    public static function systemTemp(): string
    {
        if (self::$systemTemp !== null) {
            return self::$systemTemp;
        }

        foreach (['TMPDIR', 'TMP', 'TEMP'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && $value !== '' && is_dir($value)) {
                return self::$systemTemp = rtrim($value, DIRECTORY_SEPARATOR);
            }
        }

        // What sys_get_temp_dir() falls back to on every platform this suite runs on.
        return self::$systemTemp = '/tmp';
    }
}
