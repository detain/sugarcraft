<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * The short name the model reads and writes for one tool result — `r17` — and
 * the tag that carries it on the wire, `<ctx-ref r="17"/>` (roadmap 2.2-2 /
 * 3.B-2, DCP §4.3 and §13.2 C step 4).
 *
 * A ref is a number the {@see ContextLedger} hands out once per tool result
 * and never reuses ({@see ContextLedger::refsFor()}). The tag is appended to
 * the result's content on its own last line, so the model can name the result
 * to a command or tool without quoting a call id; everything here is a pure
 * function of the number, so the same result carries the same bytes on every
 * request (DCP #614: a ref rendered two ways halved the cache hit rate).
 *
 * A model sometimes copies a tag it read into its own reply. {@see stripFrom()}
 * removes such echoes from assistant text before a request is built (DCP's
 * `stripHallucinations`), so a tag only ever appears where the harness put it.
 */
final class RefTag
{
    /** A tag with its leading newline, anywhere in a text. */
    private const TAG_PATTERN = '/\n?<ctx-ref\s+r="\d+"\s*\/>/';

    /** The wire tag for $ref. */
    public static function render(int $ref): string
    {
        return '<ctx-ref r="' . $ref . '"/>';
    }

    /** How a person or the model writes $ref: `r17`. */
    public static function label(int $ref): string
    {
        return 'r' . $ref;
    }

    /**
     * $content with $ref's tag on its own last line. Idempotent: content that
     * already ends with the tag is returned as it is, so a projection applied
     * to rows that were projected before adds nothing.
     */
    public static function appendTo(string $content, int $ref): string
    {
        $tag = self::render($ref);
        if (str_ends_with($content, $tag)) {
            return $content;
        }

        return $content === '' ? $tag : $content . "\n" . $tag;
    }

    /** $text with every tag removed, each with the newline in front of it. */
    public static function stripFrom(string $text): string
    {
        if (!str_contains($text, '<ctx-ref')) {
            return $text;
        }

        return preg_replace(self::TAG_PATTERN, '', $text) ?? $text;
    }

    /**
     * The ref a person or the model wrote — `r17`, `R17`, `17` or a whole
     * `<ctx-ref r="17"/>` tag — or null for anything else (zero and negative
     * numbers included: refs start at 1).
     */
    public static function parse(string $written): ?int
    {
        $written = trim($written);
        if (preg_match('/^<ctx-ref\s+r="(\d+)"\s*\/>$/', $written, $m) === 1
            || preg_match('/^[rR]?(\d+)$/', $written, $m) === 1) {
            $ref = (int) $m[1];

            return $ref >= 1 && (string) $ref === ltrim($m[1], '0') ? $ref : null;
        }

        return null;
    }
}
