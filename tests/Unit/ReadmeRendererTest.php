<?php

use App\Services\Vcs\ReadmeRenderer;

it('renders markdown headings and paragraphs', function () {
    $html = ReadmeRenderer::render("# Titel\n\nEin Absatz.", 'README.md');

    expect($html)->toContain('<h1>Titel</h1>')->toContain('<p>Ein Absatz.</p>');
});

it('strips embedded html instead of escaping it through', function () {
    $html = ReadmeRenderer::render('<script>alert(1)</script>', 'README.md');

    expect($html)->not->toContain('<script>')->not->toContain('alert(1)');
});

it('strips an event handler smuggled in raw html', function () {
    $html = ReadmeRenderer::render('<img src=x onerror="alert(1)">', 'README.md');

    expect($html)->not->toContain('onerror');
});

it('does not emit a javascript url as a link target', function () {
    $html = ReadmeRenderer::render('[klick](javascript:alert(1))', 'README.md');

    expect($html)->not->toContain('href="javascript:');
});

it('does not emit a javascript url as an image source', function () {
    $html = ReadmeRenderer::render('![bild](javascript:alert(1))', 'README.md');

    expect($html)->not->toContain('src="javascript:');
});

it('renders an rst file as escaped preformatted text, not as markdown', function () {
    $html = ReadmeRenderer::render("Titel\n=====\n\n<b>roh</b>", 'README.rst');

    expect($html)->toContain('<pre')
        ->toContain('&lt;b&gt;roh&lt;/b&gt;')
        ->not->toContain('<h1>');
});

it('renders a plain text readme as preformatted text', function () {
    expect(ReadmeRenderer::render('nur text', 'README'))->toContain('<pre');
});

it('returns an empty string for empty source', function () {
    expect(ReadmeRenderer::render('   ', 'README.md'))->toBe('');
});

it('lets a parse failure propagate instead of returning a false-positive empty string', function () {
    // Invalid UTF-8 makes league/commonmark's Cursor throw UnexpectedEncodingException,
    // a \RuntimeException. This must not be indistinguishable from a genuinely empty readme.
    ReadmeRenderer::render("\xB1\x31", 'README.md');
})->throws(RuntimeException::class);

it('hardens an outbound link with rel and target instead of passing it through bare', function () {
    $html = ReadmeRenderer::render('[klick](https://evil.example/x)', 'README.md');

    expect($html)
        ->toContain('rel="nofollow ugc noopener noreferrer"')
        ->toContain('target="_blank"')
        ->toContain('href="https://evil.example/x"');
});

it('unwraps a relative link to plain text instead of resolving it against this origin', function () {
    $html = ReadmeRenderer::render('[Klick hier](/admin/tokens/destroy)', 'README.md');

    expect($html)
        ->not->toContain('<a')
        ->not->toContain('href=')
        ->toContain('Klick hier');
});

it('does not emit a mixed-case javascript url as a link target', function () {
    $html = ReadmeRenderer::render('[klick](JaVaScRiPt:alert(1))', 'README.md');

    expect($html)->not->toContain('href=');
});

it('does not emit a javascript url smuggled through a reference-style link', function () {
    $html = ReadmeRenderer::render("[klick][ref]\n\n[ref]: javascript:alert(1)", 'README.md');

    expect($html)->not->toContain('href=');
});

it('does not emit a javascript url smuggled through an entity-encoded control character', function () {
    $html = ReadmeRenderer::render('[klick](jav&#x09;ascript:alert(1))', 'README.md');

    expect($html)->not->toContain('href=');
});

it('unwraps an unsafe-scheme link instead of leaving a dead, styled anchor', function () {
    $html = ReadmeRenderer::render('[klick](javascript:alert(1))', 'README.md');

    expect($html)
        ->not->toContain('<a')
        ->toContain('klick');
});

it('does not render an image with a remote absolute source, keeping only its alt text', function () {
    $html = ReadmeRenderer::render('![Build Status](https://evil.example/track.png)', 'README.md');

    expect($html)
        ->not->toContain('<img')
        ->not->toContain('evil.example')
        ->toContain('Build Status');
});

it('does not render an image with a relative source, keeping only its alt text', function () {
    $html = ReadmeRenderer::render('![Screenshot](assets/screenshot.png)', 'README.md');

    expect($html)
        ->not->toContain('<img')
        ->not->toContain('screenshot.png')
        ->toContain('Screenshot');
});

it('escapes hostile alt text instead of smuggling it through when an image is stripped', function () {
    $html = ReadmeRenderer::render('![<script>alert(1)</script>](https://evil.example/x.png)', 'README.md');

    expect($html)->not->toContain('<script>')->not->toContain('<img');

    $escaped = ReadmeRenderer::render('![1 < 2 and 3 > 2](https://evil.example/x.png)', 'README.md');

    expect($escaped)->toContain('&lt;')->toContain('&gt;')->not->toContain('1 < 2');
});
