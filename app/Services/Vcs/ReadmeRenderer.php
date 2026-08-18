<?php

namespace App\Services\Vcs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Util\RegexHelper;

/**
 * Turns a repository's README into HTML that is safe to store and serve.
 *
 * The source is written by whoever controls the upstream repository, so it is treated
 * as hostile input. Raw HTML is stripped rather than escaped — a README is prose, and
 * passing author HTML through onto the operator's origin buys nothing — and unsafe link
 * schemes are refused by the parser.
 *
 * Images never survive rendering. This instance ships with no *enforced* img-src CSP
 * (SECURITY_CSP is "off" or "report" in the shipped defaults; report mode observes but does
 * not block), so unlike a link — which needs a click — a live <img> fires an automatic,
 * no-interaction GET to whatever host a hostile README names, leaking the viewer's IP, user
 * agent, and a Referer that discloses the private package name being viewed, on every page
 * view. There is no origin an image source could point to that this renderer can vouch for,
 * so every image is unwrapped to its alt text instead of dropped outright: the reader loses
 * the picture, not the sentence describing it. Alt text is author-controlled too — it is
 * inline markdown content, rendered and escaped through the same pipeline as the rest of the
 * document, not substituted in raw.
 *
 * Rendering happens once per sync, in a queue worker, and that placement is the load-bearing
 * mitigation — not a reaction to whichever advisory happens to be open. league/commonmark has
 * no unfixed advisory as of 2.10.0, but the six that were open at 2.8.2 were closed across
 * 2.9.0, and 2.9.1 and 2.10.0 each closed *more* quadratic-time parsing bugs found afterwards
 * (unbounded delimiter-cache keys, reference-label normalization, repeated attribute merges).
 * The pattern, not any one entry, is the reason: a markdown parser fed attacker-authored input
 * is a place where super-linear parsing keeps being found. Off the request path, a hostile
 * repository costs one queue job; on it, it would cost every page view.
 *
 * `max_nesting_level` below still only bounds *block* nesting (blockquotes, lists) — it is read
 * by MarkdownParser alone, and neither the inline parser nor the GFM table extension gained a
 * comparable cap in 2.9/2.10. So it remains a partial mitigation, not a general one.
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

    /**
     * @throws \Throwable if the markdown source cannot be parsed. The caller must catch
     *                    this, log which package/file failed to render, and treat the
     *                    readme as unavailable — see the class docblock.
     */
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

    /**
     * Which of the two render paths $filename will take. Public because a caller that
     * appends its own text to the source — ReadmeLocator's truncation notice — has to
     * write it in the syntax that will actually be rendered, and duplicating the extension
     * list there would let the two drift apart.
     */
    public static function isMarkdown(string $filename): bool
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
        $environment->addEventListener(DocumentParsedEvent::class, self::stripImages(...));

        return (string) (new MarkdownConverter($environment))->convert($source);
    }

    /**
     * A relative href ("docs/CONTRIBUTING.md", "/admin/tokens") means "another file in the
     * upstream repository" to whoever wrote the README — a path that has no meaning on this
     * instance and, left alone, would silently resolve against the operator's own origin
     * instead. There is no repository URL available here to resolve it correctly against, and
     * an unsafe scheme (javascript:, data:, …) is refused by the parser and renders with no
     * href at all. Either way the link is a dead end, so it is unwrapped to its plain text
     * instead of kept as a dead, link-styled anchor: the reader loses the ability to follow
     * it, not the words that described it.
     *
     * Anything with a scheme or a host (https://, mailto:, protocol-relative //host/path, …)
     * that isn't refused as unsafe is a real outbound link and gets hardened instead:
     * rel="nofollow ugc noopener noreferrer" plus target="_blank", so following one can't be
     * mistaken for first-party content, can't reach back via window.opener, and doesn't
     * navigate the reader off the package page.
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
            if (self::isDeadEnd($link->getUrl())) {
                self::unwrap($link);

                continue;
            }

            $link->data->set('attributes/rel', self::OUTBOUND_REL);
            $link->data->set('attributes/target', '_blank');
        }
    }

    /**
     * No image survives rendering — see the class docblock for why. Unwrapped to its own
     * children (the alt text and any inline markup inside it) rather than dropped, so the
     * surrounding prose still reads. Those children go through the normal renderer, the same
     * one that already HTML-escapes plain text and strips raw HTML tags, so author-controlled
     * alt text is not a new place to smuggle markup — it is covered by the same rules as the
     * rest of the document, not exempted from them.
     */
    private static function stripImages(DocumentParsedEvent $event): void
    {
        $images = [];

        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Image) {
                $images[] = $node;
            }
        }

        foreach ($images as $image) {
            self::unwrap($image);
        }
    }

    /**
     * A link this renderer will not emit a working href for, so it must not look clickable.
     *
     * Known, deliberate over-blocking, not an oversight: RegexHelper::REGEX_UNSAFE_PROTOCOL is
     * `/^javascript:|vbscript:|file:|data:/i` — the leading `^` binds to the first alternative
     * only, so "javascript:" must lead the string, but "vbscript:", "file:", and "data:" match
     * anywhere in it. A legitimate link that merely *contains* one of those substrings — e.g.
     * `https://good.example/?redirect=data:text/plain` — is caught by isLinkPotentiallyUnsafe()
     * and, here, unwrapped entirely rather than just losing its href. That is a behaviour change
     * from before this class's round-2 fix, which only stripped the href and left a dead but
     * still-styled anchor; fully unwrapping such a link now reads slightly worse (plain text
     * where a real link belonged) but is still fail-safe, so it is left as is rather than
     * special-cased to duplicate CommonMark's own unsafe-protocol matching in this class.
     */
    private static function isDeadEnd(string $url): bool
    {
        return self::isRelative($url) || RegexHelper::isLinkPotentiallyUnsafe($url);
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
