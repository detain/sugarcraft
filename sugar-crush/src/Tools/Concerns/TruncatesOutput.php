<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

/**
 * Bounds the size of a tool result before it reaches the model.
 *
 * Every string a {@see \SugarCraft\Crush\Tools\Tool} returns is copied verbatim
 * into a ToolResultMessage, and that message is replayed inside EVERY
 * subsequent request of the turn. An unbounded result is therefore not a
 * one-off cost — it is paid again on each step until the conversation is
 * compacted, and a single oversized one can exhaust the context window
 * outright, at which point the turn fails with nothing to show for it.
 *
 * The exposures this closes are ordinary usage, not abuse: `Glob('**\/*.php')`
 * over a repo with a `vendor/` tree, a `Grep` for a common identifier, and a
 * `Bash` running `find /`, `cat` on a large file, or a noisy build.
 *
 * Truncation is ANNOUNCED rather than silent. A quietly-clipped list reads to
 * the model as a complete answer, so it concludes "there are exactly these
 * files" from a partial set — a wrong answer stated confidently, which is worse
 * than an obviously-partial one. The marker names the byte counts so the model
 * can tell how much it is missing and narrow the query itself.
 *
 * Mirrors the existing {@see \SugarCraft\Crush\Tools\BuiltIn\Read} cap, which
 * has always bounded single-file reads; this generalises the same rule to the
 * tools that can produce far more than one file's worth of text.
 */
trait TruncatesOutput
{
    /**
     * 64 KiB — roughly 16k tokens, i.e. a large-but-survivable slice of any
     * current context window, and enough to hold ~1000 file paths or a few
     * thousand grep hits. Deliberately far below {@see
     * \SugarCraft\Crush\Tools\BuiltIn\Read}'s 1 MiB: Read is a targeted
     * request for one named file, whereas these tools answer open-ended
     * queries whose size the caller cannot predict.
     */
    private const DEFAULT_MAX_OUTPUT_BYTES = 65536;

    /**
     * Clip $output to $maxBytes and append a marker naming what was dropped.
     *
     * $alreadyDropped lets a caller that discarded bytes BEFORE building
     * $output (a bounded process capture, a walk that stopped early) fold that
     * loss into the same total, so the marker reports the real size of the
     * answer rather than only the part that survived long enough to be
     * measured here. It must count only losses the model would OTHERWISE have
     * seen — bytes the caller was always going to discard are not truncation,
     * and reporting them labels a complete answer partial.
     *
     * $endsMidLine says the caller's own bound already landed inside a line,
     * so the repair below has to run even though nothing is clipped here. A
     * discard count cannot carry that fact: a cut that happened to fall on a
     * newline drops bytes without leaving damage.
     *
     * A non-positive $maxBytes disables the cap, which keeps the trait usable
     * from a tool whose caller has deliberately opted out.
     */
    private function truncateOutput(
        string $output,
        int $maxBytes,
        int $alreadyDropped = 0,
        bool $endsMidLine = false,
    ): string {
        return $this->truncateMerged([
            'head' => $output,
            'tail' => '',
            'dropped' => $alreadyDropped,
            'headMidLine' => $endsMidLine,
            'tailMidLine' => false,
        ], $maxBytes);
    }

    /**
     * Bound a two-part result — a bulk `head` plus a trailing `tail` that has
     * to survive — to $maxBytes, announcing everything lost along the way.
     *
     * The tail is budgeted FIRST, and that ordering is the whole point. A
     * failing command's `head` is its stdout (a build log, a test run) and its
     * `tail` is the stderr saying why it failed; joining them and clipping
     * from the front guarantees the reason is what falls off the end, which is
     * exactly the diagnostic loss {@see CapturesProcessOutput} exists to
     * prevent. Reserving the tail's share up front means the answer to "why
     * did this fail" arrives even when the noise before it is a megabyte.
     *
     * The tail's reservation is capped at HALF the budget so the inverse
     * failure cannot happen either: a chatty stderr must not starve the stdout
     * the question was actually about.
     *
     * @param array{head: string, tail: string, dropped: int, headMidLine: bool, tailMidLine: bool} $merged
     */
    private function truncateMerged(array $merged, int $maxBytes): string
    {
        $head = $merged['head'];
        $tail = $merged['tail'];
        $separator = ($head !== '' && $tail !== '') ? "\n" : '';
        $joined = $head . $separator . $tail;

        if ($maxBytes <= 0) {
            return $joined;
        }

        // Nothing was lost anywhere and it all fits: hand it back untouched.
        if (
            strlen($joined) <= $maxBytes
            && $merged['dropped'] === 0
            && !$merged['headMidLine']
            && !$merged['tailMidLine']
        ) {
            return $joined;
        }

        $total = strlen($joined) + $merged['dropped'];

        // The marker is part of what the model receives, so a cap that
        // excludes it is not the bound it is documented to be. Sizing the
        // reserve with $total in BOTH slots is a true upper bound on the
        // marker finally emitted, since the dropped count can never exceed
        // the total.
        $budget = max(0, $maxBytes - (strlen($this->truncationMarker($total, $total)) + 1));

        $tailClip = $tail === ''
            ? ['kept' => '', 'dropped' => 0]
            : self::clipToLine($tail, intdiv($budget, 2), $merged['tailMidLine']);

        $headBudget = $budget - strlen($tailClip['kept']) - strlen($separator);
        $headClip = self::clipToLine($head, max(0, $headBudget), $merged['headMidLine']);

        $kept = $headClip['kept'];
        if ($tailClip['kept'] !== '') {
            $kept = $kept === '' ? $tailClip['kept'] : $kept . "\n" . $tailClip['kept'];
        }

        $dropped = $merged['dropped'] + $headClip['dropped'] + $tailClip['dropped'];
        if ($dropped <= 0) {
            return $kept;
        }

        return $kept . "\n" . $this->truncationMarker($dropped, $total);
    }

    /**
     * Keep at most $budget bytes of $text, ending on a complete line.
     *
     * A path or a grep hit sliced mid-string is not just noise, it is a
     * plausible-looking WRONG value the model may go on to use as a real
     * filename. $endsMidLine covers the case where the damage was done
     * upstream — the text arrives already cut, under the budget, and would
     * otherwise sail through unrepaired.
     *
     * The one case that keeps a partial line is a KEPT WINDOW containing no
     * newline — whether because the text has none at all (`cat` of a
     * single-line file) or because the budget ran out before the first one (a
     * grep hit inside a minified bundle). There is no complete line to fall
     * back to, and returning nothing at all is a worse answer than a fragment,
     * which the caller always pairs with the truncation marker.
     *
     * @return array{kept: string, dropped: int}
     */
    private static function clipToLine(string $text, int $budget, bool $endsMidLine): array
    {
        $length = strlen($text);
        $clipped = $length > $budget;
        $kept = $clipped ? substr($text, 0, $budget) : $text;

        if ($clipped || $endsMidLine) {
            $lastNewline = strrpos($kept, "\n");
            if ($lastNewline !== false) {
                $kept = substr($kept, 0, $lastNewline);
            }
        }

        return ['kept' => $kept, 'dropped' => $length - strlen($kept)];
    }

    /**
     * The one wording every capped tool uses, so the model learns a single
     * signal instead of three near-miss phrasings.
     */
    private function truncationMarker(int $droppedBytes, int $totalBytes): string
    {
        return sprintf(
            '... [truncated: %d of %d bytes omitted. This result is PARTIAL, not the complete answer '
            . '— narrow the query (a more specific pattern, a deeper path, or a filter) to see the rest.]',
            $droppedBytes,
            $totalBytes,
        );
    }
}
