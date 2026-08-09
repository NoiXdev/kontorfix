<?php

use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A registry in the user's own organization, to be passed as the mandatory `group_ids` of a
 * package create. Creating a package into no registry is refused (StorePackageRequest): the
 * row would burn its instance-global name while being invisible to its own creator.
 */
function homeRegistryId(User $user): string
{
    return (string) Group::factory()->create(['organization_id' => $user->organization_id])->id;
}

function tokenHeaderFor(Group $group): array
{
    [, $plain] = RegistryToken::issue($group->organization, 'test', $group);

    return ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];
}

/**
 * @return array<string, string>
 */
function publishHeaderFor(Group $group): array
{
    [, $plain] = RegistryToken::issue($group->organization, 'ci', $group, TokenAbility::Publish);

    return ['Authorization' => 'Bearer '.$plain];
}

/**
 * @return array<string, mixed>
 */
function publishBody(string $name, string $version, string $file, string $bytes): array
{
    return [
        'name' => $name,
        'versions' => [$version => ['name' => $name, 'version' => $version, 'dependencies' => []]],
        'dist-tags' => ['latest' => $version],
        '_attachments' => [$file => ['content_type' => 'application/octet-stream', 'data' => base64_encode($bytes), 'length' => strlen($bytes)]],
    ];
}

/**
 * Builds a throwaway git repository on disk (one commit, branch "main") and returns its
 * `file://` origin URL — a real repository a `GitRepository` can clone/mirror, not a
 * fake. A faked Process would prove nothing about how `git ls-tree` / `git show` (or a
 * full `SyncPackage` run) actually behave against a real mirror.
 *
 * `KONTORFIX_VCS_ALLOWED_SCHEMES` in phpunit.xml includes `file`, which is what lets this
 * origin past `GitUrlSafety` — the same mechanism every other git-sourced sync test
 * relies on (see e.g. `tests/Feature/SyncPackageTest.php` via `Tests\Support\FixtureRepo`).
 *
 * @param  array<string, string>  $files  path (relative to repo root) => contents
 */
function makeGitRepoWith(array $files): string
{
    $dir = sys_get_temp_dir().'/readme-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    Process::path($dir)->run(['git', 'init', '-q', '-b', 'main'])->throw();

    foreach ($files as $name => $contents) {
        $path = $dir.'/'.$name;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $contents);
    }

    Process::path($dir)->run(['git', 'add', '-A'])->throw();
    Process::path($dir)
        ->env(['GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@t.test', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@t.test'])
        ->run(['git', 'commit', '-q', '-m', 'init'])->throw();

    return 'file://'.$dir;
}
