<?php

use App\Enums\ApiKeyPermission;
use App\Enums\PackageType;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Jobs\SyncPackage;
use App\Models\ApiKey;
use App\Models\GitCredential;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use App\Services\Vcs\GitRepository;
use App\Services\Vcs\RepositoryProbe;
use Illuminate\Support\Facades\Process;

/**
 * A10 — the git probe/clone/fetch path is the one outbound fetcher in the codebase that
 * never consulted an address allowlist, so an operator-supplied clone URL reached any host
 * the container could route to. These tests pin the guard at BOTH sinks (RepositoryProbe
 * and GitRepository::sync) and at every entry point that reaches them.
 *
 * The suite runs with `file` added to the scheme allowlist (phpunit.xml) so the git fixture
 * tests keep working; tests that assert the scheme gate reset it to the shipped default.
 */
function productionSchemeDefaults(): void
{
    config(['kontorfix.vcs.allowed_schemes' => ['https', 'ssh']]);
}

it('refuses to probe a link-local address and never shells out to git', function () {
    Process::fake();

    $result = (new RepositoryProbe)->probe(PackageType::Composer, 'https://169.254.169.254/repo.git');

    expect($result['ok'])->toBeFalse()
        ->and($result['versions'])->toBe([]);
    Process::assertNothingRan();
});

it('refuses to probe private and loopback targets', function () {
    Process::fake();

    foreach (['https://10.0.0.5/r.git', 'https://127.0.0.1/r.git', 'https://192.168.1.1/r.git', 'https://localhost/r.git', 'ssh://git@[::1]/r.git'] as $url) {
        expect((new RepositoryProbe)->probe(PackageType::Composer, $url)['ok'])->toBeFalse();
    }

    Process::assertNothingRan();
});

it('refuses transports outside the scheme allowlist', function () {
    productionSchemeDefaults();
    Process::fake();

    // file:// reads the container filesystem; ext:: hands git an arbitrary command;
    // git:// and http:// are unauthenticated cleartext transports.
    foreach (['file:///etc/passwd', 'ext::sh -c whoami', 'git://git.example.com/r.git', 'http://git.example.com/r.git', '/srv/git/r.git', 'git@github.com:acme/demo.git'] as $url) {
        expect((new RepositoryProbe)->probe(PackageType::Composer, $url)['ok'])->toBeFalse();
    }

    Process::assertNothingRan();
});

it('still probes a public https remote', function () {
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);

    expect((new RepositoryProbe)->probe(PackageType::Composer, 'https://github.com/acme/demo.git')['ok'])->toBeTrue();
});

it('refuses an internal git host by name, not only by IP literal', function () {
    // The case the suite could not see before: `gitlab.corp.internal` is a *hostname*, so
    // it reaches the resolver instead of short-circuiting on an IP literal. Under the old
    // global stub every alphabetic TLD came back public and this was allowed.
    Process::fake();

    expect((new RepositoryProbe)->probe(PackageType::Composer, 'https://gitlab.corp.internal/acme/demo.git')['ok'])->toBeFalse();

    Process::assertNothingRan();
});

it('allows an internal git host that the operator put on the allowlist', function () {
    config(['kontorfix.vcs.allowed_hosts' => ['*.corp.internal']]);
    Process::fake(['*ls-remote*' => Process::result(output: "ref: refs/heads/main\tHEAD\n")]);

    // Load-bearing only because the fixture resolver answers *.internal with a private
    // address (see Tests\Support\FixtureHostResolver): delete the config line above and
    // this must go red. It passed identically without it while every hostname was public.
    expect((new RepositoryProbe)->probe(PackageType::Composer, 'https://gitlab.corp.internal/acme/demo.git')['ok'])->toBeTrue();
});

it('blocks the clone/fetch sink in GitRepository::sync', function () {
    Process::fake();

    expect(fn () => (new GitRepository('https://169.254.169.254/r.git', 'ssrf-'.uniqid()))->sync())
        ->toThrow(RuntimeException::class);

    Process::assertNothingRan();
});

it('blocks the queued sync job, not just the synchronous probe', function () {
    Process::fake();
    $pkg = Package::factory()->create(['repository_url' => 'https://169.254.169.254/r.git']);

    expect(fn () => (new SyncPackage($pkg))->handle())->toThrow(RuntimeException::class);

    expect($pkg->fresh()->sync_status)->toBe(SyncStatus::Failed);
    Process::assertNothingRan();
});

it('blocks the admin probe endpoint', function () {
    Process::fake();
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->postJson('/admin/packages/probe', ['type' => 'composer', 'repository_url' => 'https://169.254.169.254/r.git'])
        ->assertOk()
        ->assertJson(['ok' => false]);

    Process::assertNothingRan();
});

it('blocks the git-credential connection test endpoint', function () {
    Process::fake();
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Admin]);
    $cred = GitCredential::factory()->create(['organization_id' => $org->id, 'host' => '169.254.169.254']);

    $this->actingAs($admin)
        ->postJson("/admin/git-credentials/{$cred->id}/test", ['repository_url' => 'https://169.254.169.254/r.git'])
        ->assertOk()
        ->assertJson(['ok' => false]);

    Process::assertNothingRan();
});

it('blocks the api create/resync path that reaches the same job', function () {
    Process::fake();
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $pkg = Package::factory()->create(['repository_url' => 'https://169.254.169.254/r.git']);

    // QUEUE_CONNECTION=sync, so the dispatched job runs inline and its failure surfaces
    // as the package's sync status — the point is that the job path is guarded too.
    try {
        $this->withToken($plain)->postJson("/api/v1/packages/{$pkg->id}/resync");
    } catch (Throwable) {
        // The job rethrows so the queue can retry; the status below is what matters.
    }

    expect($pkg->fresh()->sync_status)->toBe(SyncStatus::Failed);
    Process::assertNothingRan();
});
