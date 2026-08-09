<?php

namespace App\Services\Vcs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;

/**
 * Turns a repository's README into HTML that is safe to store and serve.
 *
 * The source is written by whoever controls the upstream repository, so it is treated
 * as hostile input. Raw HTML is stripped rather than escaped — a README is prose, and
 * passing author HTML through onto the operator's origin buys nothing — and unsafe link
 * schemes are refused by the parser.
 *
 * Rendering happens once per sync, in a queue worker. league/commonmark currently carries
 * several open denial-of-service advisories around deeply nested structures; doing this off
 * the request path means a hostile repository costs one job rather than every page view.
 * `max_nesting_level` below only bounds *block* nesting (blockquotes, lists) — the inline
 * parser and the GFM table extension have no such cap in this version, so it is a partial
 * mitigation, not a general one.
 *
 * A parse failure is allowed to propagate as a Throwable rather than being swallowed into
 * an empty string indistinguishable from a genuinely empty README. The caller is expected
 * to catch it, log which package/file failed to render, and treat the readme as unavailable.
 */
class ReadmeRenderer
{
    /** Extensions we parse as markdown. Everything else is shown as what it is. */
    private const MARKDOWN_EXTENSIONS = ['md', 'markdown'];

    /**
     * Attributes applied to every outbound (absolute) link so a hostile README cannot farm
     * SEO credit, imply endorsement, or reach back into the opening page via window.opener.
     * Links are also opened in a new tab so following one doesn't navigate the reader away
     * from the package page.
     */
    private const OUTBOUND_REL = 'nofollow ugc noopener noreferrer';

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
        $environment->addEventListener(DocumentParsedEvent::class, self::policeLinks(...));

        return (string) (new MarkdownConverter($environment))->convert($source);
    }

    /**
     * A relative href ("docs/CONTRIBUTING.md", "/admin/tokens") means "another file in the
     * upstream repository" to whoever wrote the README — a path that has no meaning on this
     * instance and, left alone, would silently resolve against the operator's own origin
     * instead. There is no repository URL available here to resolve it against correctly, so
     * relative links are unwrapped to their plain text instead of kept as dead or, worse,
     * same-origin links. The reader loses the ability to follow a link to another file in the
     * source repository; they keep the words that described it.
     *
     * Anything with a scheme or a host (https://, mailto:, protocol-relative //host/path, …)
     * is a real outbound link and gets hardened instead: rel="nofollow ugc noopener noreferrer"
     * plus target="_blank", so following one can't be mistaken for first-party content, can't
     * reach back via window.opener, and doesn't navigate the reader off the package page.
     */
    private static function policeLinks(DocumentParsedEvent $event): void
    {
        $links = [];

        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Link) {
                $links[] = $node;
            }
        }

        foreach ($links as $link) {
            if (self::isRelative($link->getUrl())) {
                self::unwrap($link);

                continue;
            }

            $link->data->set('attributes/rel', self::OUTBOUND_REL);
            $link->data->set('attributes/target', '_blank');
        }
    }

    private static function isRelative(string $url): bool
    {
        return ! is_string(parse_url($url, PHP_URL_SCHEME))
            && ! is_string(parse_url($url, PHP_URL_HOST));
    }

    /** Replaces $node with its own children, in order, then removes it. */
    private static function unwrap(Node $node): void
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }

        $node->detach();
    }
}
