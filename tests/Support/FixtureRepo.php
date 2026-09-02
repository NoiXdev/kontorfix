<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Process;

class FixtureRepo
{
    /**
     * Creates a local git repo with composer.json and tags v1.0.0 + v1.1.0.
     * Returns the absolute path.
     */
    public static function make(string $packageName = 'acme/demo'): string
    {
        $dir = sys_get_temp_dir().'/kfx-fixture-'.uniqid();
        mkdir($dir, 0775, true);
        Process::path($dir)->run('git init -b main .')->throw();

        file_put_contents($dir.'/composer.json', json_encode([
            'name' => $packageName,
            'description' => 'Demo package',
            'require' => ['php' => '>=8.2'],
        ], JSON_PRETTY_PRINT));

        self::git($dir, 'git add .');
        self::git($dir, 'git -c user.email=test@kontorfix.test -c user.name=kontorfix commit -m "init"');
        self::git($dir, 'git tag v1.0.0');

        file_put_contents($dir.'/composer.json', json_encode([
            'name' => $packageName,
            'description' => 'Demo package v2',
            'require' => ['php' => '>=8.3'],
        ], JSON_PRETTY_PRINT));
        self::git($dir, 'git add .');
        self::git($dir, 'git -c user.email=test@kontorfix.test -c user.name=kontorfix commit -m "bump"');
        self::git($dir, 'git tag v1.1.0');

        return $dir;
    }

    /**
     * Creates a local git repo with a package.json and tags v1.0.0 + v1.1.0 (npm
     * git-mirror fixture). Returns the absolute path.
     */
    public static function makeNpm(string $packageName = '@acme/widget'): string
    {
        $dir = sys_get_temp_dir().'/kfx-fixture-npm-'.uniqid();
        mkdir($dir, 0775, true);
        self::git($dir, 'git init -b main .');

        foreach (['1.0.0', '1.1.0'] as $v) {
            file_put_contents($dir.'/package.json', json_encode([
                'name' => $packageName,
                'version' => $v,
                'description' => "Widget {$v}",
            ], JSON_PRETTY_PRINT));
            file_put_contents($dir.'/index.js', "module.exports = '{$v}';\n");
            self::git($dir, 'git add .');
            self::git($dir, 'git -c user.email=test@kontorfix.test -c user.name=kontorfix commit -m "'.$v.'"');
            self::git($dir, "git tag v{$v}");
        }

        return $dir;
    }

    /**
     * Creates a local git repo with a pyproject.toml and tags v1.0.0 + v1.1.0 (Python
     * git-mirror fixture). Returns the absolute path.
     */
    public static function makePython(string $projectName = 'acme-lib'): string
    {
        $dir = sys_get_temp_dir().'/kfx-fixture-py-'.uniqid();
        mkdir($dir, 0775, true);
        self::git($dir, 'git init -b main .');

        foreach (['1.0.0', '1.1.0'] as $v) {
            file_put_contents($dir.'/pyproject.toml', "[project]\nname = \"{$projectName}\"\nversion = \"{$v}\"\n");
            self::git($dir, 'git add .');
            self::git($dir, 'git -c user.email=test@kontorfix.test -c user.name=kontorfix commit -m "'.$v.'"');
            self::git($dir, "git tag v{$v}");
        }

        return $dir;
    }

    /**
     * A repo with no package manifest at all — the "nothing is wrong, just type the name
     * yourself" case the probe has to report differently from a failed read.
     */
    public static function makeWithoutManifest(): string
    {
        $dir = sys_get_temp_dir().'/kfx-fixture-nomanifest-'.uniqid();
        mkdir($dir, 0775, true);
        self::git($dir, 'git init -b main .');

        file_put_contents($dir.'/README.md', "# no manifest here\n");
        self::git($dir, 'git add .');
        self::git($dir, 'git -c user.email=test@kontorfix.test -c user.name=kontorfix commit -m "init"');

        return $dir;
    }

    /**
     * A repo whose composer.json is present but unreadable as a manifest. Stands in for
     * every way the manifest read can fail after the repository itself was reachable —
     * including the one that motivated this: the blob's lazy fetch coming back 401.
     */
    public static function makeWithBrokenManifest(): string
    {
        $dir = sys_get_temp_dir().'/kfx-fixture-broken-'.uniqid();
        mkdir($dir, 0775, true);
        self::git($dir, 'git init -b main .');

        file_put_contents($dir.'/composer.json', "<!doctype html>\nnot json at all\n");
        self::git($dir, 'git add .');
        self::git($dir, 'git -c user.email=test@kontorfix.test -c user.name=kontorfix commit -m "init"');

        return $dir;
    }

    private static function git(string $dir, string $command): void
    {
        Process::path($dir)->run($command)->throw();
    }
}
