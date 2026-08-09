<?php

namespace App\Services\Vcs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Throwable;

/**
 * Turns a repository's README into HTML that is safe to store and serve.
 *
 * The source is written by whoever controls the upstream repository, so it is treated
 * as hostile input. Raw HTML is stripped rather than escaped — a README is prose, and
 * passing author HTML through onto the operator's origin buys nothing — and unsafe link
 * schemes are refused by the parser.
 *
 * Rendering happens once per sync, in a queue worker. league/commonmark currently carries
 * several open denial-of-service advisories (deeply nested structures, colliding heading
 * slugs); doing this off the request path means a hostile repository costs one job rather
 * than every page view.
 */
class ReadmeRenderer
{
    /** Extensions we parse as markdown. Everything else is shown as what it is. */
    private const MARKDOWN_EXTENSIONS = ['md', 'markdown'];

    public static function render(string $source, string $filename): string
    {
        $source = trim($source);

        if ($source === '') {
            return '';
        }

        return self::isMarkdown($filename)
            ? self::markdown($source)
            : '<pre class="readme-plain">'.e($source).'</pre>';
    }

    private static function isMarkdown(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, self::MARKDOWN_EXTENSIONS, true);
    }

    private static function markdown(string $source): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        try {
            return (string) (new MarkdownConverter($environment))->convert($source);
        } catch (Throwable) {
            // A README is never a reason to fail. The caller stores nothing and logs.
            return '';
        }
    }
}
