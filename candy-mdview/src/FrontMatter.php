<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

/**
 * Leading YAML front matter detector (glow parity, finding 007).
 *
 * Jekyll/Hugo/GitHub-style documents open with a metadata block fenced by
 * a `---` (or `...`) thematic-break line. Rendered as Markdown that fence
 * misparses: the opening `---` becomes a thematic break and the closing one
 * an underline for the LAST metadata line, so the first block of the
 * document turns into garbage like a `title: My Doc` setext heading. glow
 * strips the block before rendering; so do we — at the shared read gate,
 * which covers every door (file, stdin, fetched URL) and every pager
 * reload, so one rule holds for the whole lifecycle of a document.
 *
 * The detector is deliberately conservative (a false negative keeps the
 * bytes the user wrote; a false positive silently deletes prose):
 *
 *  - the delimiter must be the VERY FIRST thing in the document;
 *  - the closing line must repeat the opening delimiter (`---` closes
 *    `---`, `...` closes `...`);
 *  - at least one non-blank line must sit between the delimiters (a
 *    block of nothing is not metadata);
 *  - a body must FOLLOW the closing line — a document that merely ENDS
 *    with `---` is a setext heading underline (CommonMark), not a closed
 *    metadata block, and is left exactly as written.
 */
final class FrontMatter
{
    /** Supported metadata fences, in detection order. */
    private const DELIMITERS = ['---', '...'];

    /**
     * Split a document into its renderable body and (optional) raw
     * front-matter block.
     *
     * @return array{0: string, 1: ?string} [body, matter] where matter is
     *         the fenced block verbatim (delimiters included) when the
     *         document opens with one, or null when it does not. The body
     *         is the input unchanged whenever matter is null.
     */
    public static function split(string $raw): array
    {
        foreach (self::DELIMITERS as $delim) {
            [$open, $contentStart] = self::openDelimiter($raw, $delim);
            if ($open === false) {
                continue;
            }

            $closing = self::findClosing($raw, $delim, $contentStart);
            if ($closing === null) {
                continue;
            }

            [$blockEnd, $body, $contentEnd] = $closing;
            if (trim(substr($raw, $contentStart, $contentEnd - $contentStart)) === '') {
                continue; // empty block: not metadata, leave the bytes alone
            }

            return [$body, substr($raw, 0, $blockEnd)];
        }

        return [$raw, null];
    }

    /** Whether a document opens with a front-matter block. */
    public static function has(string $raw): bool
    {
        return self::split($raw)[1] !== null;
    }

    /** The document with any leading front-matter block removed. */
    public static function strip(string $raw): string
    {
        return self::split($raw)[0];
    }

    /**
     * Does the document start with exactly this delimiter as its first
     * line? Returns [matched, offset of the first content line].
     *
     * @return array{0: bool, 1: int}
     */
    private static function openDelimiter(string $raw, string $delim): array
    {
        $length = strlen($delim);
        if (str_starts_with($raw, $delim . "\n") === true) {
            return [true, $length + 1];
        }
        if (str_starts_with($raw, $delim . "\r\n") === true) {
            return [true, $length + 2];
        }

        return [false, 0];
    }

    /**
     * Locate the closing delimiter line and the body that follows it.
     * null when the document never closes the fence OR closes it at EOF —
     * a trailing `---` with nothing after it is a setext underline, and
     * silently deleting prose is the one failure mode we refuse.
     *
     * @return array{0: int, 1: string, 2: int}|null [end offset (after the
     *         closing line's newline), body, offset where the closing line
     *         starts — i.e. one past the metadata content]
     */
    private static function findClosing(string $raw, string $delim, int $offset): ?array
    {
        $lineStart = $offset;
        $length    = strlen($raw);
        while ($lineStart < $length) {
            $eol  = strpos($raw, "\n", $lineStart);
            $line = $eol === false ? substr($raw, $lineStart) : substr($raw, $lineStart, $eol - $lineStart);
            if (rtrim($line, "\r") === $delim) {
                if ($eol === false) {
                    return null; // closing at EOF: setext reading wins
                }
                $body = substr($raw, $eol + 1);
                if ($body === '') {
                    return null; // nothing follows: same guard, LF form
                }

                return [$eol + 1, $body, $lineStart];
            }
            if ($eol === false) {
                return null;
            }
            $lineStart = $eol + 1;
        }

        return null;
    }
}
