<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * C-PRV-3.c: the pure detector behind the `ultrathink` keyword — a word a
 * person TYPES in a prompt to ask that one dispatch for the top reasoning
 * tier, the shape Claude Code's product made familiar (the word itself is
 * a tier escalator, kept in the prompt text rather than rewritten out of
 * it, so the model still reads the request it actually made).
 *
 * Two rules keep the dial honest, and both live here rather than in the
 * dispatch seam so the whole predicate is testable without a backend:
 *
 * - WHOLE WORD, any case: `ULTRATHINK` asks, `ultrathinking` does not —
 *   the match sits on word boundaries, not substrings.
 * - OUTSIDE code fences and inline code: a fence-state walk skips every
 *   line between an opening ``` / ~~~ run and its closing one (an
 *   unclosed opener swallows the rest of the text, the conservative edge:
 *   half a pasted transcript must not silently pull money). Within a
 *   prose line, backtick-delimited spans are lifted out before the word
 *   is looked for, so quoting the word to TALK about it — the exact
 *   thing `/effort` help text and docs do — never trips the detector.
 *
 * This is a SugarCraft vocabulary helper, not a port: no
 * `Mirrors charmbracelet/...` citation attaches to it.
 */
final class EffortKeyword
{
    /** The escalator word, spelled exactly as a user types it. */
    public const KEYWORD = 'ultrathink';

    private function __construct()
    {
    }

    /**
     * Does this prompt text USE {@see KEYWORD} as a whole word, outside
     * code fences and inline code spans?
     */
    public static function present(string $text): bool
    {
        $fence = null;
        $pattern = '/\b' . self::KEYWORD . '\b/i';

        foreach (\preg_split('/\r\n|\r|\n/', $text) ?: [$text] as $line) {
            $stripped = \ltrim($line, " \t");
            $marker = self::fenceChar($stripped);

            if ($fence !== null) {
                // Inside a fence every word is quoted material. A closing
                // run is the marker char alone (three or more, no info
                // string) with nothing but whitespace after it.
                $body = \rtrim($stripped);
                if ($marker === $fence && $body !== '' && \strspn($body, $fence) === \strlen($body) && \strlen($body) >= 3) {
                    $fence = null;
                }

                continue;
            }

            if ($marker !== null) {
                $fence = $marker;

                continue;
            }

            if (\preg_match($pattern, self::outsideInlineCode($line)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The fence char a line opens (or closes) a block with; null when it
     * is prose. CommonMark admits three spaces of indent, which `ltrim`
     * already removed by the time this sees the line.
     */
    private static function fenceChar(string $strippedLine): ?string
    {
        if (\str_starts_with($strippedLine, '```')) {
            return '`';
        }

        if (\str_starts_with($strippedLine, '~~~')) {
            return '~';
        }

        return null;
    }

    /**
     * The line's text with every inline code span lifted out: a run of N
     * backticks opens a span, and the next run of N closes it — content
     * between them is dropped. An unpaired opener keeps its backticks as
     * literals (the text after them stays reachable), which is CommonMark's
     * own fallback and the reason a stray single backtick cannot blind the
     * rest of the line.
     */
    private static function outsideInlineCode(string $line): string
    {
        $outside = '';
        $length = \strlen($line);
        $i = 0;

        while ($i < $length) {
            if ($line[$i] !== '`') {
                $outside .= $line[$i];
                $i++;

                continue;
            }

            $open = $i;
            while ($i < $length && $line[$i] === '`') {
                $i++;
            }
            $run = \str_repeat('`', $i - $open);
            $close = \strpos($line, $run, $i);

            if ($close === false) {
                $outside .= $run;

                continue;
            }

            $i = $close + \strlen($run);
        }

        return $outside;
    }
}
