<?php

declare(strict_types=1);

namespace SugarCraft\Markdown;

use SugarCraft\Markdown\Frontmatter\Split;

/**
 * The `---` fenced metadata block that can open a Markdown document (the
 * convention Jekyll, Hugo and agent skill or rule files share), split off
 * from the body.
 *
 * This is the fence grammar only. It never reads the YAML inside: `meta`
 * is the raw block text for the caller's own reader, so every consumer
 * shares one definition of where the block starts and ends.
 *
 * The grammar, one line at a time:
 *  - the document opens with a line that is `---` followed by optional
 *    spaces or tabs; a document that does not open that way has no block;
 *  - the block closes at the next line that is `---` followed by optional
 *    spaces or tabs, so `----` is not a fence and an empty block (two fences
 *    back to back) is a valid, empty `meta`;
 *  - a closing fence on the last line needs no line break after it;
 *  - whitespace-only lines are dropped from the start of the body and from
 *    the start of `meta`, so the first real line keeps its own indentation;
 *    the end of the body is never trimmed, so a whitespace-only tail stays
 *    exactly as written, including one with no line break after it;
 *  - CRLF and LF endings are both accepted, and the line break in front of
 *    the closing fence is not part of `meta`;
 *  - a block with no closing fence is not a block: the whole input is body.
 *
 * Deliberately not handled: a leading byte-order mark (the document is then
 * plain body) and any YAML-level meaning of the block's contents.
 */
final class Frontmatter
{
    /** A fence line: `---`, optional spaces or tabs, an optional CR, nothing else. */
    private const FENCE = '/\A---[ \t]*\r?\z/';

    private function __construct()
    {
    }

    /**
     * Split $document at its frontmatter fence, or return null when it has
     * no complete block.
     */
    public static function split(string $document): ?Split
    {
        $length = strlen($document);
        $openEnd = strpos($document, "\n");
        if ($openEnd === false || preg_match(self::FENCE, substr($document, 0, $openEnd)) !== 1) {
            return null;
        }

        $metaStart = null;
        $cursor = $openEnd + 1;
        while (true) {
            $newline = strpos($document, "\n", $cursor);
            $lineEnd = $newline === false ? $length : $newline;
            $line = substr($document, $cursor, $lineEnd - $cursor);
            if (preg_match(self::FENCE, $line) === 1) {
                break;
            }
            if ($newline === false) {
                return null;
            }
            if ($metaStart === null && trim($line) !== '') {
                $metaStart = $cursor;
            }
            $cursor = $newline + 1;
        }

        $fence = $cursor;
        $meta = $metaStart === null ? '' : self::trimLineBreak(substr($document, $metaStart, $fence - $metaStart));

        $bodyOffset = self::skipBlankLines($document, $fence);

        return new Split($meta, substr($document, $bodyOffset), $bodyOffset);
    }

    /**
     * Drop the one line break that ends the last meta line, and a CR that
     * a CRLF document left in front of it.
     */
    private static function trimLineBreak(string $raw): string
    {
        $raw = substr($raw, 0, -1);

        return str_ends_with($raw, "\r") ? substr($raw, 0, -1) : $raw;
    }

    /**
     * The offset of the first non-blank line at or after the closing fence
     * on $fence: the fence line itself is passed, then every whitespace-only
     * line that a line break ends.
     */
    private static function skipBlankLines(string $document, int $fence): int
    {
        $length = strlen($document);
        $cursor = $fence;
        $newline = strpos($document, "\n", $cursor);
        if ($newline === false) {
            return $length;
        }
        $cursor = $newline + 1;
        while ($cursor < $length) {
            $newline = strpos($document, "\n", $cursor);
            if ($newline === false) {
                break;
            }
            if (trim(substr($document, $cursor, $newline - $cursor)) !== '') {
                break;
            }
            $cursor = $newline + 1;
        }

        return $cursor;
    }
}
