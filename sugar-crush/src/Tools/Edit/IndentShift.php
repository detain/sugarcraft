<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

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
