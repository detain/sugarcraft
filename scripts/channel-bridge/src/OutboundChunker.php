<?php

declare(strict_types=1);

namespace ChannelBridge;

/**
 * Model output → platform messages. Composition is shared (§9.4); the
 * limits are per-platform (§7.7: Discord 2000 chars / 17 lines; Teams takes
 * its own cap from config). Chunking happens here, never in the model loop.
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
            return [$text];
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
                $out[] = $decorate(\implode("\n", $chunk), $before[\array_key_first($chunk)], $after[$i - 1]);
                $chunk = [];
                $chunkChars = 0;
                continue; // re-try this line into the fresh chunk
            }

            $chunk[] = $line;
            $chunkChars = $chunkChars === 0 ? $lineLen : $chunkChars + 1 + $lineLen;
            $i++;
        }

        if ($chunk !== []) {
            $out[] = $decorate(\implode("\n", $chunk), $before[\array_key_first($chunk)], $after[$n - 1]);
        }

        $out = \array_values(\array_filter($out, static fn (string $c): bool => \trim($c) !== ''));

        // A chunk that is nothing but fence decoration carries no text.
        return \array_values(\array_filter(
            $out,
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
            $cut = \mb_strrpos(\mb_substr($body, 0, $room), ' ');
            $cut = $cut === false ? $room : $cut + 1;
            $pieces[] = \rtrim(\mb_substr($body, 0, $cut));
            $body = \ltrim(\mb_substr($body, $cut));
        }
        if ($body !== '') {
            $pieces[] = $body;
        }

        return $pieces;
    }

    public static function len(string $text): int
    {
        return \mb_strlen($text, 'UTF-8');
    }
}
