<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use SugarCraft\Code\Tools\BuiltIn\WebFetch;

/**
 * Converts an HTML string into Markdown for WebFetch's default format
 * (C-TOOL-2.a).
 *
 * The input is untrusted bytes off the network, so the parser is fenced
 * three ways before it touches anything: the size is checked against
 * {@see MAX_PARSE_BYTES} BEFORE parsing, the parse runs with `LIBXML_NONET`
 * (the document can never reach out) and WITHOUT `LIBXML_NOENT` (entity
 * references stay text — nothing is substituted, so there is no external
 * entity path at all), and diagnostics are suppressed so a garbage document
 * cannot spray warnings into the runner's error stream. Every fence failing
 * — ext-dom absent, oversized, unparseable, or nothing renderable — returns
 * null, and the caller hands the raw body to the model with a one-line note
 * rather than pretending a conversion happened.
 *
 * Markup kept: headings 1-6, paragraphs, inline emphasis, links with
 * relative hrefs resolved against the final fetch URL through
 * {@see WebFetch::resolveReference()} — the tool's own RFC 3986 resolver,
 * shared rather than copied, so its fragment drop applies here too and an
 * in-document anchor lands on the document URL — hard breaks as line breaks,
 * ordered and unordered lists nested by indentation, fenced code from `pre`
 * (fence length chosen longer than any backtick run inside), inline code
 * with the same run rule, blockquotes, `hr` as `---`, and simple pipe tables
 * from `table/tr/td/th` with `|` escaped. `script`, `style`, `nav`, `svg`,
 * `iframe` and `noscript` subtrees are dropped whole: their text is never
 * rendered, not even as inert markup, so a "ignore all previous
 * instructions" payload inside a `<script>` never reaches the model at all.
 * Comments survive as literal `<!-- -->` text — the parser ends a comment at
 * the first `-->` it contains, so one can never forge its way out.
 *
 * Everything else renders as its inline content, so unknown tags do not
 * silently swallow what they contain. Text nodes are NOT escaped for
 * Markdown syntax: a page containing `# heading` shows `# heading`. The
 * content is untrusted data and the model is told so; escaping every
 * sigil would mangle most real documents' prose for no safety gain.
 */
final class HtmlToMarkdown
{
    /**
     * Largest body handed to the parser, checked before parsing. A WebFetch
     * wire bound is configurable upward, so this does not lean on the
     * caller's cap: libxml's cost on megabytes of markup is real, and a
     * hostile page should meet a refusal to parse, not a slow parse.
     */
    public const MAX_PARSE_BYTES = 512 * 1024;

    /** Elements dropped whole, text included. */
    private const DROP_TAGS = ['script', 'style', 'nav', 'svg', 'iframe', 'noscript'];

    /** Elements that end the current inline run and render as their own block. */
    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'header', 'hr', 'li', 'main', 'ol', 'p', 'pre', 'section',
        'table', 'ul',
    ];

    /** Block containers whose children splice into the parent's block flow. */
    private const SPLICE_TAGS = [
        'address', 'article', 'aside', 'dd', 'div', 'dl', 'dt', 'figcaption',
        'figure', 'footer', 'form', 'header', 'li', 'main', 'p', 'section',
    ];

    private const HEADING_LEVELS = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

    /** URL schemes a link may carry; anything else renders as the label alone. */
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'ftp', 'ftps'];

    private function __construct()
    {
    }

    /**
     * @param string $html    the raw bytes of one document
     * @param string $baseUrl the final URL after redirects, used to resolve
     *                        relative links; an empty base leaves every link
     *                        exactly as the page wrote it
     *
     * @return ?string Markdown ending in one newline, or null when the body
     *                 could not be converted and the caller should fall back
     *                 to the raw bytes
     */
    public static function convert(string $html, string $baseUrl): ?string
    {
        if ($html === '' || strlen($html) > self::MAX_PARSE_BYTES) {
            return null;
        }
        if (!class_exists(DOMDocument::class)) {
            // No ext-dom (composer.json suggests it): no conversion, no fuss.
            return null;
        }

        // A leading byte-order mark makes libxml reject a document whose
        // encoding it was never told; undeclared BOMs are common in the wild.
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        try {
            $loaded = @$document->loadHTML(
                $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($loaded === false) {
            return null;
        }

        // Start inside <body>: libxml's standard HTML parsing puts fragments
        // there too, so every input walks the same structure — and head
        // metadata (the <title>, inline <style> text) stays out of the page.
        $root = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        if ($root === null) {
            return null;
        }

        $blocks = self::renderBlocks($root, $baseUrl);

        $markdown = trim(implode("\n\n", $blocks));

        return $markdown === '' ? null : $markdown . "\n";
    }

    /**
     * The children of $parent as block-level strings: pending inline text
     * flushes as a paragraph when a block element interrupts it, hard breaks
     * split the run into lines, and every paragraph and block carries its own
     * blank-line separation at the joiner.
     *
     * @return list<string>
     */
    private static function renderBlocks(DOMNode $parent, string $baseUrl): array
    {
        $blocks = [];
        $lines = [];
        $inline = '';

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText) {
                $inline .= self::collapseWhitespace($child->nodeValue ?? '');
                continue;
            }
            if ($child instanceof DOMComment) {
                $inline .= '<!--' . ($child->nodeValue ?? '') . '-->';
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue; // Processing instructions and the like: nothing to show.
            }

            $name = strtolower($child->nodeName);
            if (in_array($name, self::DROP_TAGS, true)) {
                continue;
            }
            if ($name === 'head') {
                // Structural metadata container: only reachable when the walk
                // starts at <html> (a body-less document), and never content.
                continue;
            }

            if ($name === 'br') {
                $lines[] = $inline;
                $inline = '';
                continue;
            }

            if (!in_array($name, self::BLOCK_TAGS, true)) {
                $inline .= self::renderInline($child, $baseUrl);
                continue;
            }

            $lines[] = $inline;
            $inline = '';
            $paragraph = self::joinLines($lines);
            $lines = [];
            if ($paragraph !== '') {
                $blocks[] = $paragraph;
            }

            $block = self::renderBlock($child, $name, $baseUrl);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        $lines[] = $inline;
        $paragraph = self::joinLines($lines);
        if ($paragraph !== '') {
            $blocks[] = $paragraph;
        }

        return $blocks;
    }

    /**
     * One block element as one Markdown block (or several, spliced; null when
     * it renders nothing at all).
     */
    private static function renderBlock(DOMElement $node, string $name, string $baseUrl): ?string
    {
        if (isset(self::HEADING_LEVELS[$name])) {
            $inner = self::joinBlocks($node, $baseUrl);

            return $inner === '' ? null : str_repeat('#', self::HEADING_LEVELS[$name]) . ' ' . $inner;
        }

        if (in_array($name, self::SPLICE_TAGS, true)) {
            $blocks = self::renderBlocks($node, $baseUrl);

            return $blocks === [] ? null : implode("\n\n", $blocks);
        }

        return match ($name) {
            'hr' => '---',
            'pre' => self::renderPre($node),
            'blockquote' => self::renderQuote($node, $baseUrl),
            'ul', 'ol' => self::renderList($node, $baseUrl),
            'table' => self::renderTable($node, $baseUrl),
            default => null,
        };
    }

    private static function renderPre(DOMElement $node): ?string
    {
        $code = trim($node->textContent ?? '', "\n");
        if (trim($code) === '') {
            return null;
        }

        // Fenced blocks need three or more, longer than any run inside.
        $fence = str_repeat('`', max(3, self::longestBacktickRun($code) + 1));

        return $fence . "\n" . $code . "\n" . $fence;
    }

    private static function renderQuote(DOMElement $node, string $baseUrl): ?string
    {
        $inner = trim(implode("\n\n", self::renderBlocks($node, $baseUrl)));
        if ($inner === '') {
            return null;
        }

        $quoted = array_map(
            static fn (string $line): string => $line === '' ? '>' : '> ' . $line,
            explode("\n", $inner),
        );

        return implode("\n", $quoted);
    }

    /**
     * `ul`/`ol` as list items. Nested blocks inside an item (a sub-list, a
     * second paragraph) are re-indented to the marker's width; a nested list
     * continues the parent line directly, so the tight shape of ordinary
     * menus stays tight.
     */
    private static function renderList(DOMElement $node, string $baseUrl): ?string
    {
        $ordered = strtolower($node->nodeName) === 'ol';
        $number = 1;
        if ($ordered) {
            $start = $node->getAttribute('start');
            if ($start !== '' && preg_match('/^-?\d{1,9}$/', $start) === 1) {
                $number = (int) $start;
            }
        }

        $items = [];
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement) || strtolower($child->nodeName) !== 'li') {
                continue; // Inter-item whitespace and stray text are not items.
            }

            $marker = $ordered ? $number++ . '. ' : '- ';
            $blocks = self::renderBlocks($child, $baseUrl);
            if ($blocks === []) {
                continue; // An empty item renders as nothing.
            }

            $first = (string) array_shift($blocks);
            $item = $marker . $first;
            $pad = str_repeat(' ', strlen($marker));
            foreach ($blocks as $block) {
                $item .= (self::isLeadingList($block) ? "\n" : "\n\n") . self::indentLines($block, $pad);
            }
            $items[] = $item;
        }

        return $items === [] ? null : implode("\n", $items);
    }

    /**
     * `table` as a pipe grid. The first row found becomes the header — pipe
     * tables require one, and a table without `th` still reads best with its
     * first row titled. `caption` text is not carried; nested tables flatten
     * into their cell.
     */
    private static function renderTable(DOMElement $node, string $baseUrl): ?string
    {
        $rows = [];
        foreach ($node->getElementsByTagName('tr') as $tr) {
            if (!($tr instanceof DOMElement)) {
                continue;
            }
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '\\|', self::joinBlocks($cell, $baseUrl));
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }
        if ($rows === []) {
            return null;
        }

        $width = max(array_map('count', $rows));
        foreach ($rows as $index => $row) {
            $rows[$index] = array_pad($row, $width, '');
        }

        $lines = ['| ' . implode(' | ', $rows[0]) . ' |'];
        $lines[] = '| ' . implode(' | ', array_fill(0, $width, '---')) . ' |';
        for ($index = 1, $count = count($rows); $index < $count; $index++) {
            $lines[] = '| ' . implode(' | ', $rows[$index]) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * One inline element as its Markdown. Unknown tags contribute their
     * rendered content, never their markup.
     */
    private static function renderInline(DOMElement $node, string $baseUrl): string
    {
        $name = strtolower($node->nodeName);
        if (in_array($name, self::DROP_TAGS, true)) {
            return '';
        }

        switch ($name) {
            case 'a':
                $href = $node->getAttribute('href');
                $label = self::joinBlocks($node, $baseUrl);
                $link = $href === '' ? null : self::safeHref($href, $baseUrl);
                if ($link === null || $label === '') {
                    return $label;
                }

                return '[' . $label . '](' . $link . ')';

            case 'img':
                $src = $node->getAttribute('src');
                if ($src === '') {
                    return '';
                }
                $link = self::safeHref($src, $baseUrl, allowDataImage: true);
                if ($link === null) {
                    return '';
                }

                return '![' . $node->getAttribute('alt') . '](' . $link . ')';

            case 'code':
            case 'kbd':
            case 'samp':
            case 'tt':
                return self::inlineCode(trim($node->textContent ?? '', " \t\n\r\0\x0B"));

            case 'strong':
            case 'b':
                // chr(42) not '*': a bare asterisk literal here is glob-shaped and
                // would drift the corpus figure the PathGlob docblock pins.
                return self::wrap(self::joinBlocks($node, $baseUrl), str_repeat(chr(42), 2));

            case 'em':
            case 'i':
            case 'cite':
            case 'var':
            case 'dfn':
                return self::wrap(self::joinBlocks($node, $baseUrl), chr(42));

            case 'del':
            case 's':
            case 'strike':
                return self::wrap(self::joinBlocks($node, $baseUrl), '~~');

            case 'button':
            case 'input':
            case 'textarea':
            case 'select':
            case 'option':
                // Form controls: only their label text is meaningful as
                // content, and it is set off with spaces so a control never
                // welds itself to the text on either side of it.
                $label = self::collapseWhitespace($node->textContent ?? '');

                return $label === '' ? '' : ' ' . $label . ' ';

            default:
                return self::flattenInline($node, $baseUrl);
        }
    }

    /**
     * The children of an inline element, concatenated as inline. A `br`
     * inside inline content becomes a space: an emphasis or link label spans
     * one line in the output no matter how the page broke it.
     */
    private static function flattenInline(DOMElement $node, string $baseUrl): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= self::collapseWhitespace($child->nodeValue ?? '');
            } elseif ($child instanceof DOMComment) {
                $out .= '<!--' . ($child->nodeValue ?? '') . '-->';
            } elseif ($child instanceof DOMElement) {
                if (strtolower($child->nodeName) === 'br') {
                    $out .= ' ';
                } else {
                    $out .= self::renderInline($child, $baseUrl);
                }
            }
        }

        return $out;
    }

    /**
     * A child element rendered down to one single-line block text, for
     * headings, link labels and table cells.
     */
    private static function joinBlocks(DOMElement $node, string $baseUrl): string
    {
        $text = implode(' ', self::renderBlocks($node, $baseUrl));

        return trim(self::collapseWhitespace($text));
    }

    /**
     * The href only when its scheme is one this converter will name.
     * `javascript:`, `vbscript:`, `data:` (except images, on the `img` path)
     * and anything else unrecognized render as the label alone, so a clicked
     * link the model later proposes can never carry an executable URL. The
     * scheme is read off the href after whitespace and control characters are
     * stripped, because HTML allows `href=" javascript:..."` and browsers
     * obey it.
     */
    private static function safeHref(string $href, string $baseUrl, bool $allowDataImage = false): ?string
    {
        $probe = preg_replace('/[\x00-\x20]+/', '', $href) ?? $href;
        if ($probe === '') {
            return null;
        }
        if (preg_match('~^([a-zA-Z][a-zA-Z0-9+.\-]*):~', $probe, $m) === 1) {
            $scheme = strtolower($m[1]);
            $allowed = in_array($scheme, self::LINK_SCHEMES, true)
                || ($allowDataImage && $scheme === 'data' && str_starts_with($probe, 'data:image/'));
            if (!$allowed) {
                return null;
            }
        }

        return $baseUrl === '' ? $href : WebFetch::resolveReference($baseUrl, $href);
    }

    private static function inlineCode(string $code): string
    {
        if ($code === '') {
            return '';
        }

        $fence = str_repeat('`', self::longestBacktickRun($code) + 1);
        // A code span that opens or closes on a backtick needs one space of
        // separation, INSIDE the fences, or the reader reads one long run.
        $glue = str_starts_with($code, '`') || str_ends_with($code, '`') ? ' ' : '';

        return $fence . $glue . $code . $glue . $fence;
    }

    private static function wrap(string $text, string $mark): string
    {
        return $text === '' ? '' : $mark . $text . $mark;
    }

    /**
     * True when the block OPENS on a list marker — the signal for a tight
     * (single newline) continuation inside a list item. A paragraph that
     * merely contains a mid-text `1. ` does not qualify.
     */
    private static function isLeadingList(string $block): bool
    {
        return preg_match('/^(?:- \S|\\d+\\. \S)/', $block) === 1;
    }

    private static function longestBacktickRun(string $text): int
    {
        $longest = 0;
        $run = 0;
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            if ($text[$index] === '`') {
                $run++;
                $longest = max($longest, $run);
            } else {
                $run = 0;
            }
        }

        return $longest;
    }

    /**
     * @param list<string> $lines
     */
    private static function joinLines(array $lines): string
    {
        $trimmed = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Adjacent inline pieces can each carry a separating space;
            // a paragraph never means a double one. Fenced blocks are
            // rendered elsewhere, so real code spacing survives there.
            $trimmed[] = preg_replace('/ {2,}/', ' ', $line) ?? $line;
        }

        $text = implode("\n", $trimmed);
        $collapsed = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($collapsed);
    }

    private static function collapseWhitespace(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    private static function indentLines(string $block, string $pad): string
    {
        return preg_replace('/^(?!$)/m', $pad, $block) ?? $block;
    }
}
