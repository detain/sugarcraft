<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Support;

/**
 * Model output → platform messages. Composition is shared (§9.4); the
 * limits are per-platform (§7.7: Discord 2000 UTF-16 units / 17 lines;
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
     * @return list<string>
     */
    private static function wordSplit(string $line, int $limit): array
    {
        $room = \max(16, $limit - 16); // headroom for the fence decoration
        $pieces = [];
        $body = $line;
        while (self::len($body) > $room) {
            $window = self::codepointsWithin($body, $room);
            $cut = \mb_strrpos(\mb_substr($body, 0, $window), ' ');
            $cut = $cut === false ? $window : $cut + 1;
            $pieces[] = \rtrim(\mb_substr($body, 0, $cut));
            $body = \ltrim(\mb_substr($body, $cut));
        }
        if ($body !== '') {
            $pieces[] = $body;
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

    /**
     * How many leading codepoints of $text fit within $room UTF-16 units.
     * Slicing stays in codepoint space (mb_substr's currency) while the
     * budget is counted in units, so a hard-cut piece never straddles its
     * ceiling and a surrogate pair is never split in half.
     */
    private static function codepointsWithin(string $text, int $room): int
    {
        $fit = 0;
        $units = 0;
        foreach (\mb_str_split($text, 1, 'UTF-8') as $ch) {
            $width = self::len($ch);
            if ($units + $width > $room) {
                break;
            }
            $units += $width;
            ++$fit;
        }

        return $fit;
    }
}
