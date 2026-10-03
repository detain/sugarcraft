<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Stage 4: runs of spaces and tabs INSIDE a line treated as one space — the
 * shape of an `old_string` retyped with `foo(a,  b)` for `foo(a, b)`, or with a
 * tab where the file has spaces.
 *
 * A multi-line `old_string` matches whole lines (and `new_string` is placed by
 * {@see IndentShift}); a single-line one may also match INSIDE a line, as a run
 * of the same words separated by any whitespace, and then replaces just that
 * run — the whitespace `old_string` carried around its ends is taken off
 * `new_string` too, so it is not pasted into the middle of the line.
 */
final class WhitespaceNormalisedMatcher implements MatchStage
{
    private const WHAT = 'old_string matched with runs of spaces and tabs inside each line treated as one space';

    public function name(): string
    {
        return EditMatcher::STAGE_WHITESPACE;
    }

    public function find(string $content, string $old, string $new): array
    {
        $normalise = static fn (string $line): string => (string) preg_replace('/[ \t]+/', ' ', trim($line));

        if (str_contains($old, "\n")) {
            return LineWindows::find($content, $old, $new, $normalise, self::WHAT);
        }

        $words = preg_split('/[ \t]+/', trim($old), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (\count($words) < 2) {
            return [];
        }

        $pattern = '/' . implode('[ \t]+', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)) . '/';
        if (preg_match_all($pattern, $content, $found, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        preg_match('/^[ \t]*/', $old, $lead);
        preg_match('/[ \t]*$/', rtrim($old, "\n"), $trail);
        $placed = $new;
        if ($lead[0] !== '' && str_starts_with($placed, $lead[0])) {
            $placed = substr($placed, strlen($lead[0]));
        }
        if ($trail[0] !== '' && str_ends_with($placed, $trail[0])) {
            $placed = substr($placed, 0, -strlen($trail[0]));
        }

        $candidates = [];
        foreach ($found[0] as [$matched, $offset]) {
            $candidates[] = new MatchCandidate($offset, strlen($matched), $placed, self::WHAT);
        }

        return $candidates;
    }
}
