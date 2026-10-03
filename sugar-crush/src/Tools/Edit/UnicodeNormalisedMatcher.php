<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Stage 6, the last: both sides compared after mapping typographic look-alikes
 * to one spelling — curly quotes to straight ones, en/em dashes and the minus
 * sign to `-`, non-breaking and other fixed-width spaces to a space, `…` to
 * `...` — and then Unicode NFKC compatibility normalisation (full-width forms,
 * ligatures) where the intl extension is present.
 *
 * It exists because a model routinely reads `“quoted”` and writes `"quoted"`
 * (or the reverse) and then misses on bytes it cannot see. The map is applied
 * one code point at a time so every normalised byte still knows which original
 * bytes it came from: the replaced span is always the FILE's own text, and a
 * match that would start or end inside one expanded character is not a match.
 * `new_string` is applied as written.
 *
 * Skipped outright when both sides are ASCII: normalisation cannot change
 * either, so there is nothing new to find.
 */
final class UnicodeNormalisedMatcher implements MatchStage
{
    /** @var array<string, string> */
    private const LOOKALIKES = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{2032}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"', "\u{2033}" => '"',
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
        "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{200A}" => ' ',
        "\u{2026}" => '...',
    ];

    public function name(): string
    {
        return EditMatcher::STAGE_UNICODE;
    }

    public function find(string $content, string $old, string $new): array
    {
        if (
            (!preg_match('/[\x80-\xFF]/', $content) && !preg_match('/[\x80-\xFF]/', $old))
            || !mb_check_encoding($content, 'UTF-8')
            || !mb_check_encoding($old, 'UTF-8')
        ) {
            return [];
        }

        [$needle] = self::normalise($old);
        if ($needle === '') {
            return [];
        }
        [$haystack, $segments] = self::normalise($content);

        $candidates = [];
        $offset = 0;
        while (($at = strpos($haystack, $needle, $offset)) !== false) {
            $from = self::toOriginal($segments, $at, true);
            $to = self::toOriginal($segments, $at + strlen($needle), false);
            // Aligned to whole source characters at both ends, or not a match.
            if ($from === null || $to === null) {
                $offset = $at + 1;
                continue;
            }
            $candidates[] = new MatchCandidate(
                $from,
                $to - $from,
                $new,
                'old_string matched after normalising quotes, dashes, spaces and Unicode compatibility forms; '
                . 'new_string was applied as written',
            );
            $offset = $at + strlen($needle);
        }

        return $candidates;
    }

    /**
     * $text normalised, plus the segments that map it back: ASCII runs pass
     * through as one identity segment each, and every non-ASCII code point is
     * its own segment covering the bytes it expanded to. Memory is per
     * non-ASCII code point, not per byte, so a 1 MiB mostly-ASCII file costs a
     * handful of segments.
     *
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int, 4: bool}>}
     *         segments as [normStart, normEnd, origStart, origEnd, identity]
     */
    private static function normalise(string $text): array
    {
        $nfkc = class_exists(\Normalizer::class);
        $out = '';
        $segments = [];
        $cursor = 0;

        preg_match_all('/[\x80-\xFF]+/', $text, $runs, PREG_OFFSET_CAPTURE);
        foreach ($runs[0] as [$run, $runStart]) {
            if ($runStart > $cursor) {
                $segments[] = [strlen($out), strlen($out) + ($runStart - $cursor), $cursor, $runStart, true];
                $out .= substr($text, $cursor, $runStart - $cursor);
            }

            $origin = $runStart;
            foreach (mb_str_split($run, 1, 'UTF-8') as $point) {
                $piece = self::LOOKALIKES[$point] ?? null;
                if ($piece === null) {
                    $piece = $nfkc ? (\Normalizer::normalize($point, \Normalizer::FORM_KC) ?: $point) : $point;
                    $piece = self::LOOKALIKES[$piece] ?? $piece;
                }
                $segments[] = [strlen($out), strlen($out) + strlen($piece), $origin, $origin + strlen($point), false];
                $out .= $piece;
                $origin += strlen($point);
            }
            $cursor = $runStart + strlen($run);
        }

        if ($cursor < strlen($text)) {
            $segments[] = [strlen($out), strlen($out) + (strlen($text) - $cursor), $cursor, strlen($text), true];
            $out .= substr($text, $cursor);
        }

        return [$out, $segments];
    }

    /**
     * The original byte offset for normalised offset $at, as the START
     * ($isStart) or the END of a match; null when $at falls inside one
     * expanded code point (the match would split a character the file holds).
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int, 4: bool}> $segments
     */
    private static function toOriginal(array $segments, int $at, bool $isStart): ?int
    {
        $low = 0;
        $high = \count($segments) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            [$normStart, $normEnd, $origStart, $origEnd, $identity] = $segments[$mid];

            if ($at < $normStart || ($at === $normStart && !$isStart && $mid > 0)) {
                $high = $mid - 1;
                continue;
            }
            if ($at > $normEnd || ($at === $normEnd && $isStart && $mid < \count($segments) - 1)) {
                $low = $mid + 1;
                continue;
            }

            if ($identity) {
                return $origStart + ($at - $normStart);
            }
            if ($at === $normStart) {
                return $origStart;
            }

            return $at === $normEnd ? $origEnd : null;
        }

        return null;
    }
}
