<?php

use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\RegistryToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
