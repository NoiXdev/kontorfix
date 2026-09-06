<?php

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use App\Services\Registry\RegistryUrl;
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

function something(): void
{
    // ..
}

/**
 * A super admin: an operator-organization admin, privileged across every organization.
 *
 * Shared here (rather than local to one test file, like CreateFormRedirectTest.php's
 * redirectSuperAdmin()) because it needs to run whether or not the file that happens to
 * invoke it is the one loaded alongside it — PHPUnit only defines a top-level function once
 * its declaring file has been required, so a test file that used the version formerly
 * declared in PackageCreationOwnerTest.php would throw "Call to undefined function"
 * whenever run on its own.
 */
function superAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

/**
 * An admin of the given organization — a plain (non-operator) org admin.
 *
 * Moved here from PackageCreationOwnerTest.php for exactly the reason spelled out above:
 * a second file now uses it, and a top-level function only exists once its declaring file
 * has been required, so running that other file on its own would have failed.
 */
function adminOf(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Admin]);
}

/**
 * A maintainer of the given organization — a plain (non-operator) org maintainer.
 *
 * Added for the share-packages gate's regression coverage: a Maintainer of an ordinary
 * customer organization must never satisfy `roleIn($anyOperatorOrgId) === Maintainer`, since
 * the gate iterates every is_operator organization and asks that question of the caller.
 * Declared here rather than in the test file for the same reason as the other helpers above:
 * a top-level function only exists once its declaring file has been required, so a test file
 * using it would fail to run standalone if it lived in just one test file.
 */
function maintainerOf(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Maintainer]);
}

/**
 * A maintainer of the operator organization — the tier `share-packages` actually
 * delegates to when `shared_package_role` is widened, per SharedPackageRole's docblock.
 *
 * Given the straightforward shape (home organization IS the operator organization, role
 * Maintainer): unlike the admin tier, `UserRole::Maintainer` never trips
 * `User::isSuperAdmin()`'s grandfather clause (`role === Admin && organization?->is_operator`
 * — Maintainer never satisfies `role === Admin`), so this is a real, reachable population
 * distinct from superAdmin(), with no need for the pivot-membership workaround the
 * admin-tier version of this helper required. `User::roleIn()` returns `$user->role`
 * directly for the home organization once isSuperAdmin() is ruled out, so this resolves to
 * UserRole::Maintainer exactly as the gate expects.
 *
 * A pivot-membership variant (Maintainer role on the operator organization via an
 * additional, non-home membership) would also satisfy the gate — roleIn() covers that shape
 * too, see AppServiceProvider's share-packages gate — but no test here needs to exercise
 * that path, so it is dropped in favour of this simpler, more representative shape.
 *
 * Declared here rather than locally for the same reason as superAdmin()/adminOf() above:
 * a top-level function only exists once its declaring file has been required, so a test
 * file using it would fail to run standalone if it lived in just one test file.
 */
function operatorMaintainer(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Maintainer]);
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

/**
 * The registry's path prefix, taken from the application's own statement of the URL form
 * rather than spelled out again here. A test that addresses a registry through this keeps
 * asserting what it meant — that this registry answers — instead of pinning a URL shape
 * that lives in App\Services\Registry\RegistryUrl. The shape itself is pinned once, in
 * tests/Feature/Registry/OrgScopedSlugTest.php and tests/Unit/RegistryUrlTest.php.
 */
function registryPath(Group $group): string
{
    return app(RegistryUrl::class)->path($group);
}

/**
 * @return array<string, string>
 */
function tokenHeaderFor(Group $group): array
{
    [, $plain] = RegistryToken::issue($group->organization, 'test', $group);

    return ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];
}

/**
 * The raw token value, for callers that build their own Basic-auth header (OCI/Docker
 * uses the token as the password, not the username, unlike tokenHeaderFor()'s npm-style
 * "token:<plain>" convention) rather than consuming the ready-made header array.
 */
function tokenPlainTextFor(Group $group, TokenAbility $ability = TokenAbility::Read): string
{
    [, $plain] = RegistryToken::issue($group->organization, 'test', $group, $ability);

    return $plain;
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
