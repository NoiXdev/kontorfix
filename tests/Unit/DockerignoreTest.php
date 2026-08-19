<?php

/**
 * A05 — `docker/Dockerfile` ships the build context with `COPY . .` and then runs
 * `chmod -R a+rX .`, so anything `.dockerignore` lets through is world-readable inside
 * every container started from the image. The list was hand-maintained and had already
 * drifted: `.git`, `.github`, `.ddev` and `.claude` were named while `.idea` and
 * `.superpowers` — the latter holding internal vulnerability write-ups, including a
 * section on deliberately unfixed weaknesses — were not.
 *
 * These assertions pin the *outcome* rather than the file's wording: which concrete
 * paths end up in an image layer.
 *
 * The matcher below implements the subset of `.dockerignore` syntax this file uses —
 * segment-wise glob matching where a pattern that matches a path's leading segments
 * excludes everything beneath it, plus `**` for a run of zero or more segments.
 * Negations are not supported, so the test asserts the file contains none; add support
 * here before adding a `!` line there.
 */
function dockerignorePatterns(): array
{
    $lines = file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES);

    return array_values(array_filter(
        array_map('trim', $lines ?: []),
        static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));
}

/**
 * Match a pattern against the *leading* segments of a path. Consuming every pattern
 * segment is a match whatever remains of the path, because excluding a directory
 * excludes its whole subtree.
 *
 * `**` stands for a run of zero or more segments, so the two lists cannot be walked by
 * one shared index — this tries each split point instead. An earlier version compared
 * segment `$i` to segment `$i`, so the colocated-test rule matched only at the single
 * depth where its `**` happened to line up, and silently missed every other depth.
 *
 * @param  list<string>  $patternSegments
 * @param  list<string>  $pathSegments
 */
function matchesLeadingSegments(array $patternSegments, array $pathSegments): bool
{
    while ($patternSegments !== []) {
        $segment = array_shift($patternSegments);

        if ($segment === '**') {
            for ($consumed = 0; $consumed <= count($pathSegments); $consumed++) {
                if (matchesLeadingSegments($patternSegments, array_slice($pathSegments, $consumed))) {
                    return true;
                }
            }

            return false;
        }

        if ($pathSegments === []) {
            return false;
        }

        if (! fnmatch($segment, array_shift($pathSegments), FNM_PATHNAME)) {
            return false;
        }
    }

    return true;
}

function entersImage(string $path): bool
{
    foreach (dockerignorePatterns() as $pattern) {
        $matches = matchesLeadingSegments(
            explode('/', trim($pattern, '/')),
            explode('/', trim($path, '/')),
        );

        if ($matches) {
            return false;
        }
    }

    return true;
}

it('uses no negation, so the matcher in this test stays faithful', function () {
    foreach (dockerignorePatterns() as $pattern) {
        expect($pattern)->not->toStartWith('!');
    }
});

it('keeps developer tooling, internal notes and credentials out of the image', function () {
    $mustNotShip = [
        // The two the audit found missing.
        '.idea/dataSources.xml',
        '.superpowers/security-audit-report.md',
        // Composer registry credentials. Absent today; docs/private-github-repos.md
        // describes creating one, and it holds a GitHub PAT or a Packagist token.
        'auth.json',
        // `php artisan config:cache` resolves the whole .env into this file — APP_KEY,
        // database password, mail credentials — and COPY would bake it into a layer.
        'bootstrap/cache/config.php',
        'bootstrap/cache/packages.php',
        // Editors and agents nobody has used here yet. The point of the blanket rule is
        // that these need no maintenance to stay out.
        '.vscode/settings.json',
        '.zed/settings.json',
        '.nova/configuration.json',
        '.fleet/run.json',
        '.cursor/rules',
        '.aider.conf.yml',
        // Runtime state, dev uploads and stale compiled Blades (COPY preserves mtimes,
        // so a compiled view newer than its source silences recompilation).
        'storage/framework/views/07ed10a3c0b628a74c27154a036bfeb3.php',
        'storage/framework/sessions/abc',
        'storage/app/private/customer-upload.zip',
        'storage/app/artifacts/proxy/x.zip',
        'storage/logs/laravel.log',
        // Secrets and repository furniture.
        '.env',
        '.env.example',
        'docker/.env',
        '.phpunit.result.cache',
        '.git/config',
        '.github/workflows/release.yml',
        '.ddev/config.yaml',
        '.claude/settings.json',
        'docs/development.md',
        'tests/TestCase.php',
        'node_modules/vue/package.json',
        'vendor/autoload.php',
        'public/build/manifest.json',
        // The JS test runner's config and the test files it points at — they drive a test
        // run that never happens inside the image (the asset stage only runs `vite build`).
        // The three depths are the point: their pattern's `**` stands for a run of zero or
        // more directories, and a matcher that treated it as exactly one would still pass
        // on the real file while letting a test moved one level in or out slip through.
        'vitest.config.ts',
        'resources/js/shallow.test.ts',
        'resources/js/composables/useTableState.test.ts',
        'resources/js/deeply/nested/somewhere/else.test.ts',
    ];

    foreach ($mustNotShip as $path) {
        expect(entersImage($path))->toBeFalse("{$path} would be copied into the image");
    }
});

it('still ships everything the image and the asset build need', function () {
    $mustShip = [
        'artisan',
        'composer.json',
        'composer.lock',
        'app/Providers/AppServiceProvider.php',
        'bootstrap/app.php',
        'config/app.php',
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'public/index.php',
        'routes/web.php',
        'resources/js/app.ts',
        // The source file sitting next to a colocated test, pinning that the `**` rule
        // above stops at `*.test.ts` instead of swallowing the directory it lives in.
        'resources/js/composables/useTableState.ts',
        'resources/views/app.blade.php',
        'docker/entrypoint.sh',
        'package.json',
        'package-lock.json',
        'vite.config.ts',
        'tsconfig.json',
        'components.json',
    ];

    foreach ($mustShip as $path) {
        expect(entersImage($path))->toBeTrue("{$path} would be missing from the image");
    }
});

it('excludes every root entry that is not deliberately shipped', function () {
    // The tripwire. A future tooling directory, credential file or scratch folder at the
    // context root fails here instead of quietly ending up in an image layer; adding it
    // to this list has to be a deliberate, reviewed act.
    $shipped = [
        'app', 'artisan', 'bootstrap', 'components.json', 'composer.json', 'composer.lock',
        'config', 'database', 'docker', 'eslint.config.js', 'package-lock.json',
        'package.json', 'phpstan.neon', 'phpunit.xml', 'public', 'resources', 'routes',
        'tsconfig.json', 'vite.config.ts',
        'CHANGELOG.md', 'CONTRIBUTING.md', 'LICENSE', 'README.md', 'SECURITY.md',
    ];

    $entries = array_diff(scandir(base_path()) ?: [], ['.', '..']);

    foreach ($entries as $entry) {
        if (in_array($entry, $shipped, true)) {
            continue;
        }

        expect(entersImage($entry))->toBeFalse("{$entry} is neither ignored nor on the shipped list");
    }
});
