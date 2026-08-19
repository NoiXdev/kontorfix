<?php

use Tests\Support\TestDatabase;
use Tests\Support\TestTempDir;

/*
|--------------------------------------------------------------------------
| Test Bootstrap
|--------------------------------------------------------------------------
|
| Runs before PHPUnit builds anything. Beyond the autoloader its job is to give this
| checkout a namespace of its own — one database and one temp directory — so that a
| suite running at the same time from another checkout (a git worktree, a second
| terminal) cannot corrupt this one's state. See the two support classes for what
| going without cost us.
|
| The temp directory has to be claimed first: PHP resolves sys_get_temp_dir() once
| and caches it, and TestDatabase reads .env, which may already touch a temp path.
|
*/

require __DIR__.'/../vendor/autoload.php';

TestTempDir::isolate(dirname(__DIR__));
TestDatabase::isolate(dirname(__DIR__));
