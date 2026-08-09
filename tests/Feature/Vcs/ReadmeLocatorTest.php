<?php

use App\Services\Vcs\GitRepository;
use App\Services\Vcs\ReadmeLocator;
use App\Services\Vcs\ReadmeRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

afterEach(function () {
    File::deleteDirectory(storage_path('app/vcs'));

    foreach (glob(sys_get_temp_dir().'/readme-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

/**
 * Builds a throwaway source repo on disk (via the shared `makeGitRepoWith()` fixture
 * builder in tests/Pest.php) and returns a synced GitRepository mirror pointed at it,
 * the same way production reaches a bare `--mirror` clone. A faked Process would prove
 * nothing about how `git ls-tree` / `git show` actually behave.
 *
 * @param  array<string, string>  $files  path (relative to repo root) => contents
 */
function readmeRepoWith(array $files): GitRepository
{
    $origin = makeGitRepoWith($files);

    $repo = new GitRepository($origin, 'readme-test-'.bin2hex(random_bytes(6)));
    $repo->sync();

    return $repo;
}

it('finds a README.md at the repository root', function () {
    $repo = readmeRepoWith(['README.md' => '# Hallo']);

    expect(ReadmeLocator::find($repo, 'HEAD'))
        ->toMatchArray(['filename' => 'README.md', 'source' => '# Hallo']);
});

it('matches the filename case-insensitively', function () {
    $repo = readmeRepoWith(['readme.MD' => 'x']);

    expect(ReadmeLocator::find($repo, 'HEAD')['filename'])->toBe('readme.MD');
});

it('prefers README.md over README.txt when both exist', function () {
    $repo = readmeRepoWith(['README.md' => 'md', 'README.txt' => 'txt']);

    expect(ReadmeLocator::find($repo, 'HEAD')['source'])->toBe('md');
});

it('returns null when the repository has no readme', function () {
    $repo = readmeRepoWith(['composer.json' => '{}']);

    expect(ReadmeLocator::find($repo, 'HEAD'))->toBeNull();
});

it('throws instead of reporting no readme when the root listing fails', function () {
    // "The repository lists fine and holds no README" and "we could not read the
    // repository" have opposite consequences for a stored readme: the first means upstream
    // deleted it and the column must be cleared, the second means we know nothing and the
    // column must be left alone. Collapsing both into null — which this locator used to do
    // — makes that decision impossible for the caller, so a failed listing has to be
    // distinguishable here, at the source.
    //
    // A repository with no commits at all clones fine but has no HEAD to list, so
    // `git ls-tree HEAD` fails. That is the "could not read" shape without any mocking.
    $dir = sys_get_temp_dir().'/readme-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    Process::path($dir)->run(['git', 'init', '-q', '-b', 'main'])->throw();

    $repo = new GitRepository('file://'.$dir, 'readme-test-'.bin2hex(random_bytes(6)));
    $repo->sync();

    expect(fn () => ReadmeLocator::find($repo, 'HEAD'))->toThrow(RuntimeException::class);
});

it('throws instead of reporting no readme when the readme blob cannot be read', function () {
    // The other half of the same distinction: the listing named a README, so the project
    // has one — failing to read it is not evidence that it is gone.
    $origin = makeGitRepoWith(['README.md' => '# Hallo']);

    $repo = new class($origin, 'readme-test-'.bin2hex(random_bytes(6))) extends GitRepository
    {
        public function fileAtRef(string $ref, string $path): string
        {
            throw new RuntimeException('git show failed');
        }
    };
    $repo->sync();

    expect(fn () => ReadmeLocator::find($repo, 'HEAD'))->toThrow(RuntimeException::class);
});

it('ignores a readme in a subdirectory', function () {
    $repo = readmeRepoWith(['composer.json' => '{}', 'docs/README.md' => 'nested']);

    expect(ReadmeLocator::find($repo, 'HEAD'))->toBeNull();
});

it('truncates a readme above the cap and says so', function () {
    $repo = readmeRepoWith(['README.md' => str_repeat('a', 300 * 1024)]);
    $found = ReadmeLocator::find($repo, 'HEAD');

    expect(strlen($found['source']))->toBeLessThanOrEqual(ReadmeLocator::MAX_BYTES + 200)
        ->and($found['source'])->toContain('gekürzt');
});

it('never hands an oversize blob to the uncapped reader', function () {
    // The cap has to be decided from the blob size git already reports in the root
    // listing, *before* any read. GitRepository::fileAtRef() buffers all of `git show`'s
    // stdout in this process, so a multi-hundred-megabyte README would exhaust
    // memory_limit — a PHP fatal, not a Throwable, so SyncPackage's catch never runs and
    // the worker dies and gets replayed. A post-read strlen() check is therefore too late
    // by construction: this test fails the moment the check moves back after the read.
    $origin = makeGitRepoWith(['README.md' => str_repeat('a', 2 * ReadmeLocator::MAX_BYTES)]);

    $repo = new class($origin, 'readme-test-'.bin2hex(random_bytes(6))) extends GitRepository
    {
        public bool $readUncapped = false;

        public function fileAtRef(string $ref, string $path): string
        {
            $this->readUncapped = true;

            return parent::fileAtRef($ref, $path);
        }
    };
    $repo->sync();

    $found = ReadmeLocator::find($repo, 'HEAD');

    expect($repo->readUncapped)->toBeFalse()
        ->and(strlen($found['source']))->toBeLessThanOrEqual(ReadmeLocator::MAX_BYTES + 200)
        ->and($found['source'])->toContain('gekürzt');
});

it('reads an under-cap readme through the ordinary uncapped reader', function () {
    // The counterpart to the test above: the capped read is reserved for blobs the
    // listing reports as oversize, so a normal README is not silently routed through a
    // path that abandons the git process mid-stream.
    $origin = makeGitRepoWith(['README.md' => '# Klein']);

    $repo = new class($origin, 'readme-test-'.bin2hex(random_bytes(6))) extends GitRepository
    {
        public bool $readUncapped = false;

        public function fileAtRef(string $ref, string $path): string
        {
            $this->readUncapped = true;

            return parent::fileAtRef($ref, $path);
        }
    };
    $repo->sync();

    expect(ReadmeLocator::find($repo, 'HEAD')['source'])->toBe('# Klein')
        ->and($repo->readUncapped)->toBeTrue();
});

it('writes the truncation notice as markdown when markdown is what will render it', function () {
    $repo = readmeRepoWith(['README.md' => str_repeat("a\n", ReadmeLocator::MAX_BYTES)]);
    $found = ReadmeLocator::find($repo, 'HEAD');

    expect(ReadmeRenderer::render($found['source'], $found['filename']))
        ->toContain('<em>Diese README wurde gekürzt.</em>');
});

it('writes the truncation notice as plain text when the plain-text path will render it', function () {
    // .rst / .txt / extensionless are escaped into a <pre> block rather than parsed, so
    // markdown emphasis in the notice reaches the reader as literal underscores around the
    // sentence. The notice has to match whichever path will render it.
    $repo = readmeRepoWith(['README.txt' => str_repeat("a\n", ReadmeLocator::MAX_BYTES)]);
    $found = ReadmeLocator::find($repo, 'HEAD');
    $html = ReadmeRenderer::render($found['source'], $found['filename']);

    expect($html)->toContain('<pre')
        ->toContain('Diese README wurde gekürzt.')
        ->not->toContain('_Diese README wurde gekürzt._');
});

it('does not split a multi-byte character at the truncation boundary', function () {
    // "é" is 2 bytes in UTF-8. Placed so the byte cap lands on its first byte only, a
    // naive substr() would slice it in half and hand ReadmeRenderer invalid UTF-8 —
    // which it throws on rather than degrading gracefully (see ReadmeRenderer's docblock
    // and ReadmeLocator::truncate()'s).
    $source = str_repeat('a', ReadmeLocator::MAX_BYTES - 1).'é'.str_repeat('b', 1000);
    $repo = readmeRepoWith(['README.md' => $source]);
    $found = ReadmeLocator::find($repo, 'HEAD');

    expect(mb_check_encoding($found['source'], 'UTF-8'))->toBeTrue();

    // A raw byte split would hand invalid UTF-8 to CommonMark, which throws rather than
    // degrading — an uncaught exception here fails the test.
    expect(ReadmeRenderer::render($found['source'], $found['filename']))->toBeString();
});

it('closes an unterminated code fence left open by truncation', function () {
    // Opens a fence early and never closes it before the byte cap, so the raw cut would
    // swallow the truncation notice into the code block. closeUnterminatedFence() must
    // append a closing fence first so the fence markers stay balanced.
    $source = "```php\n".str_repeat("x\n", (int) (ReadmeLocator::MAX_BYTES / 2));
    $repo = readmeRepoWith(['README.md' => $source]);
    $found = ReadmeLocator::find($repo, 'HEAD');

    expect(substr_count($found['source'], '```') % 2)->toBe(0);
});

it('does not treat a root-level directory named README.md as the readme', function () {
    // `git show HEAD:README.md` exits 0 and prints a tree listing when README.md is a
    // directory rather than a blob — it does not throw, so a type-blind picker would
    // silently return that listing as the "readme" of the project. There is no other
    // readme candidate here, so the correct outcome is null, not a tree dump.
    $dir = sys_get_temp_dir().'/readme-'.bin2hex(random_bytes(6));
    mkdir($dir.'/README.md', 0775, true);
    file_put_contents($dir.'/README.md/notes.txt', 'not a readme');

    Process::path($dir)->run(['git', 'init', '-q', '-b', 'main'])->throw();
    Process::path($dir)->run(['git', 'add', '-A'])->throw();
    Process::path($dir)
        ->env(['GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@t.test', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@t.test'])
        ->run(['git', 'commit', '-q', '-m', 'init'])->throw();

    $repo = new GitRepository('file://'.$dir, 'readme-test-'.bin2hex(random_bytes(6)));
    $repo->sync();

    expect(ReadmeLocator::find($repo, 'HEAD'))->toBeNull();
});

it('does not treat a symlinked README as the readme', function () {
    // Unlike a directory, a symlink IS type "blob" (git stores the link target string as
    // the blob's content), so it survives a type-only filter. `git show ref:README.md` on
    // a symlink returns the literal target path, not the target's content and not an
    // error — e.g. "TARGET.md", one line of nonsense where the README belongs. Skipping
    // it (rather than resolving it) is the deliberate choice: resolving means following a
    // path the repository author controls, inside a bare mirror, and a symlinked README is
    // rare enough that "no README" is an honest outcome. There's no other candidate here,
    // so the correct result is null.
    $dir = sys_get_temp_dir().'/readme-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/TARGET.md', "# Real content\n");
    symlink('TARGET.md', $dir.'/README.md');

    Process::path($dir)->run(['git', 'init', '-q', '-b', 'main'])->throw();
    Process::path($dir)->run(['git', 'add', '-A'])->throw();
    Process::path($dir)
        ->env(['GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@t.test', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@t.test'])
        ->run(['git', 'commit', '-q', '-m', 'init'])->throw();

    // Sanity check the fixture actually committed a symlink blob, not a regular file —
    // otherwise this test would pass for the wrong reason on a platform/config that
    // dereferences symlinks on `git add`.
    $mode = trim(Process::path($dir)->run(['git', 'ls-tree', 'HEAD', 'README.md'])->output());
    expect($mode)->toStartWith('120000');

    $repo = new GitRepository('file://'.$dir, 'readme-test-'.bin2hex(random_bytes(6)));
    $repo->sync();

    expect(ReadmeLocator::find($repo, 'HEAD'))->toBeNull();
});

it('does not let a shorter nested fence marker falsely close a longer outer fence', function () {
    // A 4-backtick fence is the correct way to show a 3-backtick example literally (per
    // CommonMark's length rule); the inner ``` lines never close the real fence. A
    // char-only heuristic would treat the first inner ``` as closing the outer block,
    // append its own (too-short) closer, and leave the truncation notice as literal text
    // inside the still-open 4-backtick fence when rendered — i.e. inside <pre><code>.
    $intro = "```` markdown\nExample:\n\n```bash\necho hi\n```\n\nMore text.\n";
    $padding = str_repeat("filler line to push this past the truncation cap\n", (int) ceil(ReadmeLocator::MAX_BYTES / 49));
    $source = $intro.$padding;

    expect(strlen($source))->toBeGreaterThan(ReadmeLocator::MAX_BYTES);

    $repo = readmeRepoWith(['README.md' => $source]);
    $found = ReadmeLocator::find($repo, 'HEAD');

    $html = ReadmeRenderer::render($found['source'], $found['filename']);
    $preClose = strpos($html, '</pre>');
    $notice = strpos($html, 'gekürzt');

    expect($preClose)->not->toBeFalse()
        ->and($notice)->not->toBeFalse()
        ->and($notice)->toBeGreaterThan($preClose);
});
