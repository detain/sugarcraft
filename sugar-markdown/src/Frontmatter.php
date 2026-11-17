<?php

declare(strict_types=1);

namespace SugarCraft\Markdown;

use SugarCraft\Markdown\Frontmatter\Split;

/**
 * Splits a leading YAML frontmatter block off a Markdown-shaped document.
 *
 * One fence grammar for the whole family of callers that read agent presets,
 * skills, rules, commands and memory notes: the document opens with a `---`
 * line, the YAML block follows, and the first later line that is `---` (with
 * optional trailing whitespace) closes it. The closing fence may end the
 * document, which a hand-written note with no body legitimately does.
 *
 * Parsing the YAML is the caller's business: {@see Split::$meta} is the raw
 * block between the fences, handed over unparsed.
 *
 * Reviewed against the seven candy-code copies and the MemoryStore variant
 * (candy-code/src/Memory/MemoryStore.php FRONTMATTER_PATTERN). Three
 * differences are deliberate: the closing fence may end the document (the
 * copies return no frontmatter there); the blank lines after the closing fence
 * are consumed (the copies consume them too; MemoryStore keeps them in its
 * body); and an unclosed block is still no frontmatter. Callers that depended
 * on MemoryStore's body bytes must trim the leading blank lines themselves.
 */
final class Frontmatter
{
    /**
     * Anchored on whole lines. `\s*` after an opening or closing fence eats
     * trailing spaces and any blank lines before the newline, so a body that
     * opens after blank lines starts at its first text line. The closing
     * `\s*` is followed by a newline or the end of the document, so a
     * `----` rule or a `---foo` line never closes the block.
     */
    private const PATTERN = '/^---\s*\n(.*?)\n---\s*(?:\n|$)/s';

    /**
     * The frontmatter and the body after it, or null when the document does
     * not open with a complete frontmatter block.
     */
    public static function split(string $document): ?Split
    {
        if (preg_match(self::PATTERN, $document, $match) !== 1) {
            return null;
        }
        $offset = strlen($match[0]);

        return new Split($match[1], substr($document, $offset), $offset);
    }
}
