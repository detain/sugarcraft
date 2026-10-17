<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Edit;

/**
 * Places `new_string` where a fuzzy stage matched `old_string`, in the file's
 * own indentation and line endings.
 *
 * A model that misjudged the nesting of a block wrote BOTH strings at the wrong
 * depth, so replacing the file's lines with `new_string` as written would put
 * the edit at the model's depth, not the file's. The shift is read off the
 * first non-blank line of each side and applied to every non-blank line of
 * `new_string`; a line of `new_string` that does not carry the old prefix sits
 * shallower than the block and has no consistent place, so the stage refuses
 * rather than guess (null).
 */
final class IndentShift
{
    private function __construct()
    {
    }

    /**
     * $new adjusted from $old's spelling to $matched's, with a sentence saying
     * what moved, or null when no consistent adjustment exists.
     *
     * @return array{0: string, 1: ?string}|null
     */
    public static function adapt(string $old, string $matched, string $new): ?array
    {
        $from = self::leadingIndent($old);
        $to = self::leadingIndent($matched);
        $detail = null;

        if ($from !== $to) {
            $shifted = self::reindent($new, $from, $to);
            if ($shifted === null) {
                return null;
            }
            $new = $shifted;
            $detail = sprintf(
                'new_string was re-indented from %s to %s',
                self::describe($from),
                self::describe($to),
            );
        }

        // A CRLF file stays CRLF: the matched block's line endings are the
        // file's, and a model's string almost never carries `\r`.
        if (str_contains($matched, "\r\n") && !str_contains($new, "\r\n")) {
            $new = str_replace("\n", "\r\n", $new);
        }

        return [$new, $detail];
    }

    /**
     * $replacement with the FILE'S line endings (ISS-062).
     *
     * {@see adapt()} converts LF to CRLF when the MATCHED BLOCK carries
     * `\r\n`; that rule cannot see a single-line `old_string` with no line
     * break at all, a Write of whole new content, or a patch hunk that only
     * adds lines — yet all three would strand LF islands in a CRLF file.
     * The file-level twin of the same promise: when $fileBytes is PURELY
     * CRLF (at least one pair, no bare `\n` anywhere) and $replacement
     * introduces bare newlines, every bare `\n` becomes `\r\n`. Anything
     * less unambiguous — an LF file, a mixed file, a replacement that
     * already spells `\r\n` or has no newline to convert — is returned
     * untouched, because guessing at a mixed file is how an editor
     * rewrites lines nobody asked it to touch.
     */
    public static function inheritEndings(string $fileBytes, string $replacement): string
    {
        if ($fileBytes === '' || $replacement === '') {
            return $replacement;
        }

        if (!self::isPureCrlf($fileBytes)) {
            return $replacement; // not provably a CRLF file
        }

        if (str_contains($replacement, "\r\n") || !str_contains($replacement, "\n")) {
            return $replacement; // model spelled them, or there is nothing to convert
        }

        return self::toCrlf($replacement);
    }

    /**
     * The assembled-content sibling of {@see inheritEndings()} for callers
     * whose replacement IS the whole file — a patched result, where the
     * context lines necessarily repeat the file's own CRLF and the
     * "the model spelled it" guard could never fire meaningfully. A purely
     * CRLF file gets every bare `\n` of the assembly converted; every other
     * file shape is returned untouched.
     */
    public static function normalizeEndings(string $fileBytes, string $assembled): string
    {
        if ($fileBytes === '' || $assembled === '' || !self::isPureCrlf($fileBytes)) {
            return $assembled;
        }

        return preg_match('/(?<!\r)\n/', $assembled) === 1 ? self::toCrlf($assembled) : $assembled;
    }

    /** A file with at least one CRLF pair and no bare newline anywhere. */
    private static function isPureCrlf(string $bytes): bool
    {
        return str_contains($bytes, "\r\n") && preg_match('/(?<!\r)\n/', $bytes) !== 1;
    }

    private static function toCrlf(string $text): string
    {
        return preg_replace('/(?<!\r)\n/', "\r\n", $text) ?? $text;
    }

    /**
     * $new with $from swapped for $to at the head of every non-blank line, or
     * null when a line does not start with $from.
     */
    public static function reindent(string $new, string $from, string $to): ?string
    {
        $out = [];
        foreach (explode("\n", $new) as $line) {
            if (trim($line) === '') {
                $out[] = $line;
                continue;
            }
            if (!str_starts_with($line, $from)) {
                return null;
            }
            $out[] = $to . substr($line, strlen($from));
        }

        return implode("\n", $out);
    }

    /** "no indentation", "4 spaces", "1 tab", "2 spaces + 1 tab". */
    public static function describe(string $indent): string
    {
        if ($indent === '') {
            return 'no indentation';
        }
        $tabs = substr_count($indent, "\t");
        $spaces = strlen($indent) - $tabs;
        $parts = [];
        if ($spaces > 0) {
            $parts[] = $spaces . ' ' . ($spaces === 1 ? 'space' : 'spaces');
        }
        if ($tabs > 0) {
            $parts[] = $tabs . ' ' . ($tabs === 1 ? 'tab' : 'tabs');
        }

        return implode(' + ', $parts);
    }

    /** The leading spaces/tabs of the first non-blank line of $text. */
    private static function leadingIndent(string $text): string
    {
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                preg_match('/^[ \t]*/', $line, $m);

                return $m[0];
            }
        }

        return '';
    }
}
