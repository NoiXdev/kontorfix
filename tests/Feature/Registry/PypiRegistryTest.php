<?php

// twine upload + PEP 503/691 simple API + downloads for the Python registry.
use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Models\Upstream;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** @return array{0: Group, 1: Package} */
function pythonRegistry(string $slug = 'kadenz', string $name = 'My.Package'): array
{
    $group = Group::factory()->for(Organization::factory())->create(['slug' => $slug, 'public' => true]);
    $pkg = Package::factory()->create(['type' => PackageType::Python, 'name' => $name, 'repository_url' => null]);
    $group->packages()->attach($pkg);

    return [$group, $pkg];
}

it('accepts a twine upload and stores the distribution file', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $bytes = 'fake-sdist-content';
    $file = UploadedFile::fake()->createWithContent('my_package-1.0.0.tar.gz', $bytes);

    $this->withHeaders(publishHeaderFor($group))->post('/r/kadenz/', [
        ':action' => 'file_upload',
        'name' => 'My.Package',
        'version' => '1.0.0',
        'filetype' => 'sdist',
        'requires_python' => '>=3.9',
        'sha256_digest' => hash('sha256', $bytes),
        'content' => $file,
    ])->assertOk();

    $dist = $pkg->fresh()->pythonDists()->first();
    expect($dist)->not->toBeNull()
        ->and($dist->filename)->toBe('my_package-1.0.0.tar.gz')
        ->and($dist->filetype)->toBe('sdist')
        ->and($dist->version)->toBe('1.0.0')
        ->and($dist->sha256)->toBe(hash('sha256', $bytes))
        ->and($dist->size)->toBe(strlen($bytes))
        ->and($dist->requires_python)->toBe('>=3.9');
    Storage::disk('artifacts')->assertExists($dist->path);
});

it('infers a wheel filetype from the filename', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $file = UploadedFile::fake()->createWithContent('my_package-1.0.0-py3-none-any.whl', 'wheelbytes');

    $this->withHeaders(publishHeaderFor($group))->post('/r/kadenz/', [
        'name' => 'My.Package', 'version' => '1.0.0', 'content' => $file,
    ])->assertOk();

    expect($pkg->fresh()->pythonDists()->first()->filetype)->toBe('bdist_wheel');
});

it('serves the PEP 503 simple index resolving the normalised name', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0.tar.gz", 'sha256' => str_repeat('a', 64), 'size' => 10,
        'requires_python' => '>=3.9', 'uploaded_at' => now(),
    ]);

    // Request under a differently-punctuated name — must resolve to the same project.
    $res = $this->withHeaders(tokenHeaderFor($group))->get('/r/kadenz/simple/My_Package/')->assertOk();
    $res->assertSee('my_package-1.0.0.tar.gz', false);
    $res->assertSee('#sha256='.str_repeat('a', 64), false);
    $res->assertSee('data-requires-python="&gt;=3.9"', false);
});

it('serves PEP 691 JSON when the client asks for it', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/x.tar.gz", 'sha256' => str_repeat('b', 64), 'size' => 10, 'uploaded_at' => now(),
    ]);

    $this->withHeaders(tokenHeaderFor($group) + ['Accept' => 'application/vnd.pypi.simple.v1+json'])
        ->get('/r/kadenz/simple/my-package/')
        ->assertOk()
        ->assertJsonPath('name', 'my-package')
        ->assertJsonPath('files.0.filename', 'my_package-1.0.0.tar.gz')
        ->assertJsonPath('files.0.hashes.sha256', str_repeat('b', 64));
});

it('declares Simple API version 1.1 in the JSON representation', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0.tar.gz", 'sha256' => str_repeat('a', 64), 'size' => 10, 'uploaded_at' => now(),
    ]);
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0-py3-none-any.whl', 'filetype' => 'bdist_wheel',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0-py3-none-any.whl", 'sha256' => str_repeat('b', 64), 'size' => 20, 'uploaded_at' => now(),
    ]);

    $json = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => 'application/vnd.pypi.simple.v1+json'])
        ->get('/r/kadenz/simple/my-package/')
        ->assertOk()
        ->json();

    expect($json['meta']['api-version'])->toBe('1.1');
});

it('lists every project version once, in the versions key', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0.tar.gz", 'sha256' => str_repeat('a', 64), 'size' => 10, 'uploaded_at' => now(),
    ]);
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0-py3-none-any.whl', 'filetype' => 'bdist_wheel',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0-py3-none-any.whl", 'sha256' => str_repeat('b', 64), 'size' => 20, 'uploaded_at' => now(),
    ]);
    $pkg->pythonDists()->create([
        'version' => '1.1.0', 'filename' => 'my_package-1.1.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/my_package-1.1.0.tar.gz", 'sha256' => str_repeat('c', 64), 'size' => 30, 'uploaded_at' => now(),
    ]);

    $json = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => 'application/vnd.pypi.simple.v1+json'])
        ->get('/r/kadenz/simple/my-package/')
        ->assertOk()
        ->json();

    expect($json['versions'])->toEqualCanonicalizing(['1.0.0', '1.1.0']);
});

it('reports the mandatory size on every file', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0.tar.gz", 'sha256' => str_repeat('a', 64), 'size' => 10, 'uploaded_at' => now(),
    ]);
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0-py3-none-any.whl', 'filetype' => 'bdist_wheel',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0-py3-none-any.whl", 'sha256' => str_repeat('b', 64), 'size' => 20, 'uploaded_at' => now(),
    ]);

    $json = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => 'application/vnd.pypi.simple.v1+json'])
        ->get('/r/kadenz/simple/my-package/')
        ->assertOk()
        ->json();

    foreach ($json['files'] as $file) {
        expect($file['size'])->toBeInt()->toBeGreaterThan(0);
    }
});

it('reports upload-time as an ISO 8601 instant', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0.tar.gz", 'sha256' => str_repeat('a', 64), 'size' => 10, 'uploaded_at' => now(),
    ]);

    $json = $this->withHeaders(tokenHeaderFor($group) + ['Accept' => 'application/vnd.pypi.simple.v1+json'])
        ->get('/r/kadenz/simple/my-package/')
        ->assertOk()
        ->json();

    expect($json['files'][0]['upload-time'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
});

it('declares the same version in the HTML representation', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0.tar.gz", 'sha256' => str_repeat('a', 64), 'size' => 10, 'uploaded_at' => now(),
    ]);

    $html = $this->withHeaders(tokenHeaderFor($group))
        ->get('/r/kadenz/simple/my-package/')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<meta name="pypi:repository-version" content="1.1">');
});

it('declares the same version on the root index route', function () {
    Storage::fake('artifacts');
    [$group] = pythonRegistry();

    $html = $this->withHeaders(tokenHeaderFor($group))
        ->get('/r/kadenz/simple')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<meta name="pypi:repository-version" content="1.1">');
});

it('downloads a stored distribution and counts the download', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    Storage::disk('artifacts')->put("pypi/{$pkg->id}/my_package-1.0.0.tar.gz", 'data');
    $dist = $pkg->pythonDists()->create([
        'version' => '1.0.0', 'filename' => 'my_package-1.0.0.tar.gz', 'filetype' => 'sdist',
        'path' => "pypi/{$pkg->id}/my_package-1.0.0.tar.gz", 'sha256' => str_repeat('c', 64), 'size' => 4, 'uploaded_at' => now(),
    ]);

    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/pypi/files/{$pkg->id}/my_package-1.0.0.tar.gz")
        ->assertOk();

    expect($dist->fresh()->download_count)->toBe(1);
});

it('never forwards a locally-known project to an upstream (dependency confusion)', function () {
    $groupA = Group::factory()->for(Organization::factory())->create(['slug' => 'a', 'public' => true]);
    Upstream::factory()->for($groupA)->create(['type' => PackageType::Python, 'url' => 'https://pypi.org', 'enabled' => true]);

    // The project exists in another org's registry — must not leak or be proxied.
    $groupB = Group::factory()->for(Organization::factory())->create(['slug' => 'b', 'public' => true]);
    $secret = Package::factory()->create(['type' => PackageType::Python, 'name' => 'internal-lib']);
    $groupB->packages()->attach($secret);

    $this->withHeaders(tokenHeaderFor($groupA))->get('/r/a/simple/internal-lib/')->assertNotFound();
});

it('redirects an unknown project to the configured python upstream', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'a', 'public' => true]);
    Upstream::factory()->for($group)->create(['type' => PackageType::Python, 'url' => 'https://pypi.org', 'enabled' => true]);

    $this->withHeaders(tokenHeaderFor($group))->get('/r/a/simple/requests/')
        ->assertRedirect('https://pypi.org/simple/requests/');
});

it('rejects an upload without publish ability', function () {
    Storage::fake('artifacts');
    [$group, $pkg] = pythonRegistry();
    [, $read] = RegistryToken::issue($group->organization, 'read', $group, TokenAbility::Read);
    $file = UploadedFile::fake()->createWithContent('my_package-1.0.0.tar.gz', 'x');

    $this->withHeaders(['Authorization' => 'Bearer '.$read])
        ->post('/r/kadenz/', ['name' => 'My.Package', 'version' => '1.0.0', 'content' => $file])
        ->assertForbidden();

    expect($pkg->fresh()->pythonDists()->count())->toBe(0);
});

it('rejects an upload for a project not in this registry', function () {
    Storage::fake('artifacts');
    [$group] = pythonRegistry();
    $file = UploadedFile::fake()->createWithContent('other-1.0.0.tar.gz', 'x');

    $this->withHeaders(publishHeaderFor($group))
        ->post('/r/kadenz/', ['name' => 'other', 'version' => '1.0.0', 'content' => $file])
        ->assertNotFound();
});

it('rejects a sha256 digest mismatch', function () {
    Storage::fake('artifacts');
    [$group] = pythonRegistry();
    $file = UploadedFile::fake()->createWithContent('my_package-1.0.0.tar.gz', 'real');

    $this->withHeaders(publishHeaderFor($group))->post('/r/kadenz/', [
        'name' => 'My.Package', 'version' => '1.0.0', 'sha256_digest' => str_repeat('0', 64), 'content' => $file,
    ])->assertStatus(400);
});

it('rejects a duplicate distribution filename with 409', function () {
    Storage::fake('artifacts');
    [$group] = pythonRegistry();

    $upload = fn () => $this->withHeaders(publishHeaderFor($group))->post('/r/kadenz/', [
        'name' => 'My.Package', 'version' => '1.0.0', 'content' => UploadedFile::fake()->createWithContent('my_package-1.0.0.tar.gz', 'x'),
    ]);

    $upload()->assertOk();
    $upload()->assertStatus(409);
});
