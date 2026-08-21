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
 *
 * A cap also has to bound the RIGHT text. Five tools compose a result out of
 * two things — the answer, and the instruction-file body governing the paths
 * the answer names — and the second is not something the caller asked for or
 * can predict the size of. Left to share one budget it wins, because it is a
 * whole markdown file and the answer is a list of lines: measured at a
 * 200-byte cap, {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} returned the rule
 * book and none of the five paths it had matched. Left OUTSIDE the budget it
 * is worse, because then there is no bound at all:
 * {@see \SugarCraft\Crush\Tools\BuiltIn\Grep} returned 19.3x its cap and
 * {@see \SugarCraft\Crush\Tools\BuiltIn\Read} 37.1x.
 *
 * So {@see instructionBudget()} splits the cap instead, and the split is what
 * makes both failures unreachable: the instruction section can spend at most a
 * quarter, the answer keeps at least three quarters, and whichever section is
 * cut says so in its own words — see {@see instructionTruncationMarker()}.
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

    /**
     * The instruction bound for a tool that has NO output cap to take a
     * fraction of.
     *
     * 16 KiB — a quarter of {@see DEFAULT_MAX_OUTPUT_BYTES}, so the two agree
     * rather than one silently contradicting the other.
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Edit} and
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Write} are the callers: their
     * own result is one line ("File updated: <path>"), so a cap on it would
     * bound nothing, but the instruction body they prepend to it is a whole
     * markdown file replayed into every following request of the turn.
     */
    private const DEFAULT_MAX_INSTRUCTION_BYTES = 16384;

    /**
     * The share of a tool's output budget that instruction-file text may take.
     *
     * A QUARTER, so three quarters of the cap is a FLOOR under the results —
     * which is the property that actually fixes the reported bug. The bug was
     * never "the instructions are too long"; it was that a tool asked "which
     * files match" answered with a rule book and none of the matches. A cap
     * split by a fixed fraction cannot produce that outcome at any input size:
     * the instruction section can only ever spend its own quarter, and the
     * answer keeps the rest whether or not the quarter is used.
     *
     * A fraction rather than a separate setting because the two numbers are
     * not independently meaningful. `maxOutputBytes` is already the statement
     * of "how much of the context window one tool result may occupy"; a second
     * absolute byte setting beside it can be configured into exactly the
     * starvation this method exists to prevent (set it to the cap and the
     * floor is zero). Deriving it means the floor holds for every value of the
     * cap, including the ones a caller invents.
     *
     * Mirrors the tail reservation in {@see truncateMerged()}, which caps
     * stderr's share at a half for the same reason in the other direction.
     *
     * A non-positive cap means "no cap" throughout this trait, and returns 0
     * here to say the same thing.
     */
    private function instructionBudget(int $maxOutputBytes): int
    {
        return $maxOutputBytes > 0 ? intdiv($maxOutputBytes, 4) : 0;
    }

    /**
     * The most of an instruction file's FIRST line that survives a budget too
     * small to hold anything else.
     *
     * A rule reduced to nothing but a truncation marker names no subject: the
     * model is told some rules exist and cannot tell which. The first line of
     * a `CLAUDE.md` is its heading in practice, so keeping it is the
     * difference between "instructions were withheld" and "the section on X
     * was withheld". Bounded because a first line is not guaranteed to be
     * short — a minified or single-line file has exactly one.
     */
    private const INSTRUCTION_HEAD_BYTES = 120;

    /**
     * Bound one instruction-file body to $budget, announcing what was cut.
     *
     * $budget <= 0 disables the bound, matching {@see truncateOutput()}. A
     * caller that has a reserve but no room left in it must therefore pass 1,
     * not 0 — passing the arithmetic straight through is how the first cut of
     * this change silently disabled the very bound it added.
     */
    private function clipInstructions(string $text, int $budget): string
    {
        if ($budget <= 0 || strlen($text) <= $budget) {
            return $text;
        }

        $total = strlen($text);
        // Sized with $total in both slots for the reason truncateMerged() does
        // it: the marker is part of what the model receives, so a budget that
        // excludes it is not the bound it claims to be, and the dropped count
        // can never exceed the total.
        $room = $budget - (strlen($this->instructionTruncationMarker($total, $total)) + 1);
        $clip = self::clipToLine($text, max(0, $room), false);

        if ($clip['dropped'] <= 0) {
            return $clip['kept'];
        }

        $kept = $clip['kept'];
        if ($kept === '') {
            $firstLine = strstr($text, "\n", true);
            $kept = substr($firstLine === false ? $text : $firstLine, 0, self::INSTRUCTION_HEAD_BYTES);
        }

        return $kept === ''
            ? $this->instructionTruncationMarker($total, $total)
            : $kept . "\n" . $this->instructionTruncationMarker($total - strlen($kept), $total);
    }

    /**
     * Bound a whole SET of instruction bodies to $budget.
     *
     * Every body gets a share, and that is deliberate rather than tidy.
     * {@see \SugarCraft\Crush\Context\InstructionFileLoader::loadForPath()}
     * marks a file emitted AT LOAD TIME, so a body loaded and then dropped
     * whole is an instruction file retired for the rest of the session without
     * ever having been shown — the precise failure
     * `GrepInstructionWiringTest::testTheAnnounceOnceMarkIsSpentOnlyOnWhatTheModelReceived()`
     * exists to prevent. Clipping each body instead keeps every governing file
     * present, with its own marker saying it is partial.
     *
     * The shares are handed out sequentially against what is LEFT rather than
     * as a fixed 1/n slice, so short bodies (the common case — a nested
     * `CLAUDE.md` is usually a few lines) leave their unused room to the
     * bodies after them instead of stranding it.
     *
     * @param list<string> $bodies
     */
    private function clipInstructionSet(array $bodies, int $budget): string
    {
        if ($bodies === []) {
            return '';
        }
        if ($budget <= 0) {
            return implode("\n", $bodies);
        }

        $remaining = $budget;
        $count = count($bodies);
        $kept = [];

        foreach ($bodies as $i => $body) {
            $share = intdiv($remaining, $count - $i);
            $clipped = $this->clipInstructions($body, $share);
            $kept[] = $clipped;
            // +1 for the newline implode() will put back between the parts.
            $remaining = max(0, $remaining - strlen($clipped) - 1);
        }

        return implode("\n", $kept);
    }

    /**
     * A DIFFERENT wording from {@see truncationMarker()}, on purpose.
     *
     * "Narrow the query to see the rest" is the right advice about a clipped
     * result and the wrong advice about a clipped rule: no pattern the model
     * can write will make a project's instruction file shorter, and a rule
     * read halfway is a rule the model may believe it has followed. What it
     * needs to know is that the text is PARTIAL, in the same word the other
     * markers use.
     *
     * SHORT, where {@see truncationMarker()} is discursive, and that is a
     * measured constraint rather than a stylistic one. This marker is charged
     * against a QUARTER of the cap, so it competes with the very text it
     * describes: at the trait's default cap the difference is noise, but a
     * caller that passes a few hundred bytes gets a reserve of a few dozen,
     * and a marker longer than its own budget crowds out every line of the
     * rule it was written to annotate. A marker that cannot fit inside the
     * budget it announces is not a marker, it is the truncation.
     */
    private function instructionTruncationMarker(int $droppedBytes, int $totalBytes): string
    {
        return sprintf(
            '... [instructions truncated: %d of %d bytes omitted; these project rules are PARTIAL.]',
            $droppedBytes,
            $totalBytes,
        );
    }
}
