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

    private static function git(string $dir, string $command): void
    {
        Process::path($dir)->run($command)->throw();
    }
}
