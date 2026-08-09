<?php

use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Support\Facades\Process;

it('stores the rendered readme when the repository has one', function () {
    $origin = makeGitRepoWith([
        'composer.json' => json_encode(['name' => 'acme/demo']),
        'README.md' => '# Hallo',
    ]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => null,
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->readme_html)->toContain('<h1>Hallo</h1>');
});

it('leaves the column null when the repository has no readme', function () {
    $origin = makeGitRepoWith(['composer.json' => json_encode(['name' => 'acme/demo'])]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => null,
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->readme_html)->toBeNull();
});

it('clears a stored readme once the repository no longer has one', function () {
    // A README deleted upstream — including one deleted *because* it leaked something —
    // must stop being served. Only a successful render used to write this column and
    // nothing ever cleared it: no admin edit path, not request-fillable, no reset in
    // packages:resync. So the stale copy was served indefinitely, and re-syncing, the one
    // action an operator would reach for, did nothing.
    $origin = makeGitRepoWith(['composer.json' => json_encode(['name' => 'acme/demo'])]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => '<h1>Geheimnis</h1>',
        'sync_status' => 'pending',
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->sync_status->value)->toBe('synced')
        ->and($package->fresh()->readme_html)->toBeNull();
});

it('clears a stored readme once the repository readme is emptied', function () {
    // Emptying README.md is the other way upstream retracts it, and it reaches a different
    // branch: the file is found and renders to '', which used to be treated as "nothing to
    // store" and left the old HTML in place.
    $origin = makeGitRepoWith([
        'composer.json' => json_encode(['name' => 'acme/demo']),
        'README.md' => "   \n",
    ]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => '<h1>Geheimnis</h1>',
        'sync_status' => 'pending',
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->sync_status->value)->toBe('synced')
        ->and($package->fresh()->readme_html)->toBeNull();
});

it('keeps the previous readme and still completes when the repository cannot be listed', function () {
    // The counterpart to the two tests above, and the reason clearing has to be narrow: a
    // repository we could not read is not a repository whose README is gone. A clone with
    // no commits has no HEAD to list, so `git ls-tree HEAD` fails while the sync itself
    // succeeds — if that were treated as "no README", one unreadable sync would wipe a
    // working README page.
    $dir = sys_get_temp_dir().'/readme-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    Process::path($dir)->run(['git', 'init', '-q', '-b', 'main'])->throw();

    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => 'file://'.$dir,
        'readme_html' => '<h1>Alt</h1>',
        'sync_status' => 'pending',
    ]);

    (new SyncPackage($package))->handle();

    expect($package->fresh()->sync_status->value)->toBe('synced')
        ->and($package->fresh()->readme_html)->toBe('<h1>Alt</h1>');
});

it('keeps the previous readme and still completes when the new readme fails to render', function () {
    // ReadmeLocator::find() never throws — a missing/directory/symlinked README all come
    // back as null from inside it, so none of those exercise SyncPackage's own try/catch.
    // The only realistic way to reach that catch is ReadmeRenderer::render() throwing on a
    // markdown parse failure, which it does deliberately on invalid encoding (see its
    // docblock). \xFF is not a valid UTF-8 byte in any position, so this is a byte value,
    // not a locale- or filesystem-encoding-dependent construct: file_put_contents() writes
    // it verbatim, and git stores blob content exactly as given (no line endings appear
    // here for core.autocrlf to touch) — reproducible on any machine that can run this
    // suite at all.
    $origin = makeGitRepoWith([
        'composer.json' => json_encode(['name' => 'acme/demo']),
        'README.md' => "# Hi\n\xFF invalid utf-8 byte\n",
    ]);
    $package = Package::factory()->create([
        'source_mode' => 'git',
        'repository_url' => $origin,
        'readme_html' => '<h1>Alt</h1>',
        'sync_status' => 'pending',
    ]);

    (new SyncPackage($package))->handle();

    // The sync itself must succeed, and an unparsable README must not blank what was
    // stored — this is the one case that actually reaches syncReadme()'s try/catch.
    expect($package->fresh()->sync_status->value)->toBe('synced')
        ->and($package->fresh()->readme_html)->toBe('<h1>Alt</h1>');
});
