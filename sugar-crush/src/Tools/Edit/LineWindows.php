<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * The window search the line-based stages share: every run of file lines that
 * equals `old_string`'s lines once both sides pass through the same per-line
 * normaliser, as whole-line spans with `new_string` placed by
 * {@see IndentShift}.
 */
final class LineWindows
{
    private function __construct()
    {
    }

    /**
     * @param \Closure(string): string $normalise
     * @return list<MatchCandidate>
     */
    public static function find(string $content, string $old, string $new, \Closure $normalise, string $what): array
    {
        $needle = LineIndex::needle($old);
        $want = array_map($normalise, $needle['lines']);
        if (implode('', $want) === '') {
            return [];
        }

        $index = LineIndex::of($content);
        $have = array_map($normalise, $index->lines);
        $n = \count($want);
        $candidates = [];

        for ($i = 0, $last = $index->count() - $n; $i <= $last; $i++) {
            if ($have[$i] !== $want[0]) {
                continue;
            }
            for ($k = 1; $k < $n; $k++) {
                if ($have[$i + $k] !== $want[$k]) {
                    continue 2;
                }
            }

            [$offset, $length] = $index->span($i, $i + $n - 1, $needle['trailingNewline']);
            $placed = IndentShift::adapt($old, substr($content, $offset, $length), $new);
            if ($placed === null) {
                continue;
            }

            $candidates[] = new MatchCandidate(
                $offset,
                $length,
                $placed[0],
                $placed[1] === null ? $what : $what . '; ' . $placed[1],
            );
        }

        return $candidates;
    }
}
