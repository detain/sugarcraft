<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Stage 2, the roadmap's "indentation-flexible" stage (named `uniform-indent`
 * since audit 0.11 added it): the same lines shifted by ONE indentation delta.
 * `new_string` is re-indented by the same delta, so a model that misjudged the
 * nesting depth of a block gets its edit instead of a wasted turn. A block that
 * would need a different shift per line is left to the looser stages after it.
 *
 * Blank lines match any run of spaces and tabs, since editors disagree about
 * trailing whitespace on them.
 */
final class IndentFlexibleMatcher implements MatchStage
{
    public function name(): string
    {
        return EditMatcher::STAGE_UNIFORM_INDENT;
    }

    public function find(string $content, string $old, string $new): array
    {
        $lines = explode("\n", $old);
        $trailingNewline = \count($lines) > 1 && end($lines) === '';
        if ($trailingNewline) {
            array_pop($lines);
        }

        $common = self::commonIndent($lines);
        if ($common === null) {
            return [];
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
        if (preg_match_all($pattern, $content, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        $candidates = [];
        foreach ($found as $match) {
            [$matched, $offset] = $match[0];
            $indent = $match[1][0];
            $reindented = IndentShift::reindent($new, $common, $indent);
            if ($reindented === null) {
                continue;
            }
            $candidates[] = new MatchCandidate(
                $offset,
                strlen($matched),
                $reindented,
                sprintf(
                    'old_string matched only after re-indenting it from %s to %s; new_string was re-indented the same way',
                    IndentShift::describe($common),
                    IndentShift::describe($indent),
                ),
            );
        }

        return $candidates;
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
}
