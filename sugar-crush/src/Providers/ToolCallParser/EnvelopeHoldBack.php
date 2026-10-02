<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

/**
 * Decides how much of a streamed text can be painted now, and how much has to
 * wait because it may be the start of a tool-call envelope (audit 15a A8).
 *
 * WHY HOLD BACK RATHER THAN RETRACT. A streamed chunk is painted the moment it
 * is yielded and appended to the turn's transcript buffer; there is no way to
 * take it back once the envelope it belonged to turns out to be a recovered
 * call. So the text-scanning path stops yielding at the first byte that could
 * be markup, and the provider decides at end of stream - with the whole
 * content in hand, which is what telling an action from a quotation needs -
 * whether the held text was an envelope (cut it) or prose (release it).
 *
 * WHAT IT COSTS, AND WHERE. Only a provider whose parser is
 * {@see EnvelopeAware} holds anything; the default OpenAI-array parser has no
 * markers, so its stream is byte-for-byte what it was. With markers armed:
 *
 * - a chunk ending in what could be the first bytes of a marker (`<`,
 *   `<｜DS`, `<minimax:`) waits for the next chunk to settle it;
 * - a trailing whitespace run containing a newline waits with it, because the
 *   blank line in front of DeepSeek-V4's envelope is part of its start token
 *   (`enc.py:726`) and must go when the envelope goes;
 * - once a complete marker has appeared, everything after it waits for the
 *   end of the stream. That includes a reply that merely QUOTES the markup:
 *   its tail is painted all at once at the end instead of token by token.
 *   That is the deliberate trade - a late tail on a rare turn, against
 *   painting tool-call markup on every recovered one.
 */
final readonly class EnvelopeHoldBack
{
    private const WHITESPACE = " \t\r\n";

    /**
     * Splits `$pending` - the text held so far plus the newest chunk - into
     * what may be painted now and what must stay held.
     *
     * Not to be called again once `markerFound` came back true: from then on
     * the caller appends to the held text unconditionally, which keeps a long
     * envelope (a `write` of a large file) linear instead of rescanning the
     * whole held text on every chunk.
     *
     * @param list<string> $markers {@see EnvelopeAware::envelopeMarkers()}.
     * @return array{0: string, 1: string, 2: bool} `[emit, hold, markerFound]`,
     *         where `emit . hold === $pending`.
     */
    public static function split(string $pending, array $markers): array
    {
        if ($markers === [] || $pending === '') {
            return [$pending, '', false];
        }

        $first = null;

        foreach ($markers as $marker) {
            $at = strpos($pending, $marker);

            if ($at !== false && ($first === null || $at < $first)) {
                $first = $at;
            }
        }

        if ($first !== null) {
            $cut = self::whitespaceRunStart($pending, $first);

            return [substr($pending, 0, $cut), substr($pending, $cut), true];
        }

        $cut = \strlen($pending) - self::longestMarkerPrefixSuffix($pending, $markers);
        $runStart = self::whitespaceRunStart($pending, $cut);

        if (str_contains(substr($pending, $runStart, $cut - $runStart), "\n")) {
            $cut = $runStart;
        }

        return [substr($pending, 0, $cut), substr($pending, $cut), false];
    }

    /**
     * Length of the longest suffix of `$text` that is a PROPER prefix of some
     * marker - a marker that may be completed by the next chunk. A complete
     * marker is the caller's other branch, so it is never counted here.
     *
     * @param list<string> $markers
     */
    private static function longestMarkerPrefixSuffix(string $text, array $markers): int
    {
        $longest = 0;

        foreach ($markers as $marker) {
            for ($length = min(\strlen($marker) - 1, \strlen($text)); $length > $longest; --$length) {
                if (str_ends_with($text, substr($marker, 0, $length))) {
                    $longest = $length;

                    break;
                }
            }
        }

        return $longest;
    }

    /**
     * Where the run of whitespace that ends at byte `$end` begins - `$end`
     * itself when the byte before it is not whitespace.
     */
    private static function whitespaceRunStart(string $text, int $end): int
    {
        while ($end > 0 && str_contains(self::WHITESPACE, $text[$end - 1])) {
            --$end;
        }

        return $end;
    }
}
