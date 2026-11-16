<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Support;

/**
 * Model output → platform messages. Composition is shared (docs/SPEC.md §9.4); the
 * limits are per-platform (docs/SPEC.md §7.7: Discord 2000 UTF-16 units / 17 lines;
 * Teams takes its own cap from config). Chunking happens here, never in
 * the model loop.
 *
 * Fences, by construction: pass one records the fence state before and
 * after every source line; pass two fills chunks and, wherever a chunk
 * starts with a fence open or ends with one still open, closes it and
 * reopens it with the same info string as a synthetic line — counted
 * against the character limit, never against the line budget.
 *
 * Pure: same text + same limits, same chunks.
 */
final class OutboundChunker
{
    /** The characters PHP's ltrim/rtrim strip by default, as codepoints. */
    private const TRIM = [' ', "\t", "\n", "\r", "\0", "\x0B"];

    /**
     * @return list<string>
     */
    public static function split(string $text, int $limit = 2000, int $maxLines = 17): array
    {
        $text = \trim($text);
        if ($text === '') {
            return [];
        }
        if (self::len($text) <= $limit && \substr_count($text, "\n") + 1 <= $maxLines) {
            return self::dropNoise([$text]);
        }

        $lines = \explode("\n", $text);

        // Pass 1: fence state before/after every line. A ``` line toggles:
        // the first opens (info string, possibly ""), the next closes.
        $fence = null;
        $before = $after = [];
        foreach ($lines as $i => $line) {
            $before[$i] = $fence;
            if (\preg_match('/^```(\S*)/', $line, $m) === 1) {
                $fence = $fence === null ? $m[1] : null;
            }
            $after[$i] = $fence;
        }

        $synthetic = static fn (?string $info, string $edge): int => $info === null ? 0 : ($edge === 'open' ? self::len('```' . $info) + 1 : 4);
        $decorate = static function (string $chunk, ?string $open, ?string $close): string {
            if ($open !== null) {
                $chunk = '```' . $open . "\n" . $chunk;
            }
            if ($close !== null) {
                $chunk .= "\n```";
            }

            return $chunk;
        };

        // Pass 2: greedy fill.
        $out = [];
        $chunk = [];          // real source lines of the chunk being filled
        $chunkStart = 0;      // line index of $chunk's first line (fence bookkeeping)
        $chunkChars = 0;
        $i = 0;
        $n = \count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            $lineLen = self::len($line);

            if ($lineLen > $limit) {
                // A line that cannot fit alone: word-split it around the
                // fence, each piece balanced and its own chunk.
                $openHere = $before[$i];
                $closeHere = $after[$i];
                foreach (self::wordSplit($line, $limit) as $piece) {
                    $out[] = $decorate($piece, $openHere, $closeHere ?? $openHere);
                }
                $i++;
                continue;
            }

            $tryChars = $chunk === [] ? $lineLen : $chunkChars + 1 + $lineLen;
            $overhead = $synthetic($before[$i] ?? null, 'open') + $synthetic($after[$i] ?? null, 'close');
            $fits = $tryChars + $overhead <= $limit && \count($chunk) + 1 <= $maxLines;

            if (!$fits && $chunk !== []) {
                $out[] = $decorate(\implode("\n", $chunk), $before[$chunkStart], $after[$i - 1]);
                $chunk = [];
                $chunkChars = 0;
                continue; // re-try this line into the fresh chunk
            }

            if ($chunk === []) {
                $chunkStart = $i;
            }
            $chunk[] = $line;
            $chunkChars = $chunkChars === 0 ? $lineLen : $chunkChars + 1 + $lineLen;
            $i++;
        }

        if ($chunk !== []) {
            $out[] = $decorate(\implode("\n", $chunk), $before[$chunkStart], $after[$n - 1]);
        }

        return self::dropNoise($out);
    }

    /**
     * Chunks that are empty, or nothing but fence decoration, carry no text
     * and are never sent — the law holds for the fast path too.
     *
     * @param list<string> $chunks
     *
     * @return list<string>
     */
    private static function dropNoise(array $chunks): array
    {
        $chunks = \array_values(\array_filter($chunks, static fn (string $c): bool => \trim($c) !== ''));

        return \array_values(\array_filter(
            $chunks,
            static fn (string $c): bool => \trim((string) \preg_replace('/^```\S*$/m', '', $c)) !== '',
        ));
    }

    /**
     * Cuts a line that cannot fit alone into pieces of at most $room UTF-16
     * units: each piece ends after the last space inside its window, or at
     * the window's edge when there is no space. Leading whitespace of the
     * remainder is dropped, the first piece keeps the line's own left edge.
     *
     * One decode and one prefix-sum pass. Each window end and its last space
     * are then read off in amortized O(1), so a long line costs O(n). The
     * earlier form re-measured and re-split the whole remaining body for every
     * piece, which is O(n^2 / room): 6.8 s for a 1M-character line, measured.
     *
     * Invalid UTF-8 is the one input whose cut moved. The old mb_substr /
     * mb_strlen / mb_str_split mix disagreed on stray bytes, so on those lines
     * the piece boundaries (and sometimes the piece count) differ from the
     * pre-single-pass cut, and stray bytes pass through instead of being
     * replaced with '?'. Valid UTF-8 is cut identically to the old code.
     *
     * @return list<string>
     */
    private static function wordSplit(string $line, int $limit): array
    {
        $room = \max(16, $limit - 16); // headroom for the fence decoration
        $cps = \mb_str_split($line, 1, 'UTF-8');
        $n = \count($cps);
        // $units[$i] is the UTF-16 width of the first $i codepoints.
        $units = [0];
        foreach ($cps as $i => $ch) {
            $units[$i + 1] = $units[$i] + self::len($ch);
        }

        $pieces = [];
        $start = 0;   // first codepoint of the piece being cut
        $end = 0;     // window end, exclusive: first codepoint that no longer fits
        $space = -1;  // last ' ' below $end; $end only grows, so this never rescans
        while ($units[$n] - $units[$start] > $room) {
            while ($end < $n && $units[$end + 1] - $units[$start] <= $room) {
                if ($cps[$end] === ' ') {
                    $space = $end;
                }
                ++$end;
            }
            $cut = $space >= $start ? $space + 1 : $end;
            $pieces[] = \rtrim(\implode('', \array_slice($cps, $start, $cut - $start)));

            $start = $cut;
            while ($start < $n && \in_array($cps[$start], self::TRIM, true)) {
                ++$start;
            }
            $end = \max($end, $start);
        }
        if ($start < $n) {
            $pieces[] = \implode('', \array_slice($cps, $start));
        }

        return $pieces;
    }

    /**
     * Discord measures content in UTF-16 code units — its own client
     * validates a JS content.length — so one astral emoji costs 2 against
     * the 2000-unit cap. Counting codepoints instead under-budgets
     * astral-heavy replies by up to half and invites the API's 400-reject,
     * so this counts UTF-16LE units (pure bytes: no BOM, fixed
     * little-endian, never a guess). Same text in, same count out.
     */
    public static function len(string $text): int
    {
        return \strlen(\mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) >> 1;
    }
}
