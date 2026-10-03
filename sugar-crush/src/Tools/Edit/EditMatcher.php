<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Finds where an Edit's `old_string` lands in a file, one stage at a time,
 * stopping at the first stage that finds it (audit 0.11).
 *
 *  1. `exact` — the bytes as given. Any number of occurrences is returned;
 *     uniqueness is the caller's rule ({@see \SugarCraft\Crush\Tools\BuiltIn\Edit}
 *     honours `replace_all`).
 *  2. `uniform-indent` — only when exact found nothing: the same lines shifted
 *     by ONE indentation delta. It must match exactly once, and `new_string` is
 *     re-indented by the same delta, so a model that misjudged the nesting
 *     depth of a block gets its edit instead of a wasted turn. A match that
 *     would need a different shift per line is not this stage's to guess.
 *
 * The stage list is the seam later matching work extends (roadmap 3.I appends
 * more forgiving stages after these two). Every stage after `exact` must stay
 * unique-only: a fuzzy stage that picked one of several candidates would edit
 * a place the model never named.
 *
 * Null means no stage matched; {@see EditFailureHints::notFound()} explains why.
 */
final class EditMatcher
{
    public const STAGE_EXACT = 'exact';
    public const STAGE_UNIFORM_INDENT = 'uniform-indent';

    public static function new(): self
    {
        return new self();
    }

    public function match(string $content, string $old, string $new): ?EditMatch
    {
        if ($old === '') {
            return null;
        }

        $count = substr_count($content, $old);
        if ($count > 0) {
            return new EditMatch(self::STAGE_EXACT, $old, $new, $count);
        }

        return $this->uniformIndent($content, $old, $new);
    }

    /**
     * Stage 2: $old with its common leading indentation replaced by whatever
     * single indentation the file uses at the one place the dedented lines
     * occur. Blank lines match any run of spaces and tabs, since editors
     * disagree about trailing whitespace on them.
     */
    private function uniformIndent(string $content, string $old, string $new): ?EditMatch
    {
        $lines = explode("\n", $old);
        $trailingNewline = count($lines) > 1 && end($lines) === '';
        if ($trailingNewline) {
            array_pop($lines);
        }

        $common = self::commonIndent($lines);
        if ($common === null) {
            return null;
        }

        $parts = [];
        $grouped = false;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $parts[] = '[ \t]*';
                continue;
            }
            $body = preg_quote(substr($line, strlen($common)), '/');
            // \g{1}, not \1: a body starting with a digit would turn \1 into \12.
            $parts[] = ($grouped ? '\g{1}' : '([ \t]*)') . $body;
            $grouped = true;
        }

        $pattern = '/^' . implode('\n', $parts) . ($trailingNewline ? '\n' : '') . '/m';
        if (preg_match_all($pattern, $content, $found, PREG_SET_ORDER) !== 1) {
            return null;
        }

        $matched = $found[0][0];
        $indent = $found[0][1];
        // Edit replaces by value, so the matched bytes must be unique as a
        // substring too, not just as an anchored block.
        if (substr_count($content, $matched) !== 1) {
            return null;
        }

        $reindented = self::reindent($new, $common, $indent);
        if ($reindented === null) {
            return null;
        }

        return new EditMatch(
            self::STAGE_UNIFORM_INDENT,
            $matched,
            $reindented,
            1,
            sprintf(
                'old_string matched only after re-indenting it from %s to %s; new_string was re-indented the same way',
                self::describeIndent($common),
                self::describeIndent($indent),
            ),
        );
    }

    /**
     * The longest whitespace prefix every non-blank line shares, or null when
     * there is no non-blank line.
     *
     * @param list<string> $lines
     */
    private static function commonIndent(array $lines): ?string
    {
        $common = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^[ \t]*/', $line, $m);
            $indent = $m[0];
            if ($common === null) {
                $common = $indent;
                continue;
            }
            $length = 0;
            $max = min(strlen($common), strlen($indent));
            while ($length < $max && $common[$length] === $indent[$length]) {
                $length++;
            }
            $common = substr($common, 0, $length);
        }

        return $common;
    }

    /**
     * $new with $from swapped for $to at the head of every non-blank line, or
     * null when a line does not start with $from (it sits shallower than the
     * block it replaces, so no single shift applies to it).
     */
    private static function reindent(string $new, string $from, string $to): ?string
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

    private static function describeIndent(string $indent): string
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
}
