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

function something()
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
 * An admin of the operator organization who is NOT thereby a global super-admin.
 *
 * This cannot be built as "a UserRole::Admin whose home organization has is_operator:
 * true" — that is precisely User::isSuperAdmin()'s own grandfather clause
 * (`$user->role === UserRole::Admin && $user->organization?->is_operator`), so a user
 * built that way already passes Gate::before and every gate trivially, making it
 * indistinguishable from superAdmin() and unable to exercise the conditional
 * `shared_package_role` path at all. Instead this grants the admin role on the operator
 * organization through an additional membership (organization_user pivot), the same
 * per-org role mechanism PerOrgRoleScopeTest exercises — which User::roleIn() honours
 * without tripping isSuperAdmin().
 *
 * Declared here rather than locally for the same reason as superAdmin()/adminOf() above:
 * a top-level function only exists once its declaring file has been required, so a test
 * file using it would fail to run standalone if it lived in just one test file.
 */
function operatorOrgAdmin(): User
{
    $operator = Organization::factory()->create(['is_operator' => true]);
    $home = Organization::factory()->create(['is_operator' => false]);
    $user = User::factory()->for($home)->create(['role' => UserRole::Member]);
    $user->organizations()->attach($operator->id, ['role' => UserRole::Admin->value]);

    return $user;
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
