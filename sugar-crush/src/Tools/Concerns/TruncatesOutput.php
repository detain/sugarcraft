<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

use SugarCraft\Crush\Context\InstructionFileLoader;

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
 * {@see \SugarCraft\Crush\Tools\BuiltIn\Grep} returned 25.2x its cap and
 * {@see \SugarCraft\Crush\Tools\BuiltIn\Read} 48.3x.
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
        // mb_strcut, not substr. The newline trim below RESCUES a mid-sequence
        // cut only when the kept window contains a newline, and the one case
        // this method documents as keeping a partial line — a kept window with
        // no newline in it — is exactly the case where it does not. MEASURED
        // before this: Grep over a single-line 4 KiB file whose 214th byte
        // begins U+2014 returned invalid UTF-8 at caps 439 and 440
        // (mb_check_encoding() false). mb_strcut backs up to the previous
        // sequence boundary, so it never returns more than $budget bytes.
        $kept = $clipped ? mb_strcut($text, 0, $budget, 'UTF-8') : $text;

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
     * here to say the same thing. Callers under a POSITIVE cap must not pass
     * this value straight on when intdiv() rounds it to zero — see
     * {@see instructionSectionCeiling()}, which is what they size their result
     * floor with instead.
     *
     * THE RESIDUAL, RE-MEASURED. An earlier statement of it said the section
     * overruns its reserve by "a fixed ~210 bytes below a cap of roughly 700",
     * and neither half was right: the overrun began at 800 for Glob and 750
     * for Grep, and the section is not fixed — it is label plus heading plus
     * marker, so a 120-byte heading made it 347 and the overrun 247. There is
     * no residual now. {@see instructionSection()} holds the section inside
     * {@see instructionSectionCeiling()} at EVERY cap, and the result floor is
     * computed from that same ceiling, so the total lands inside the cap
     * wherever the cap is above the base truncation marker's own 185 bytes —
     * which is a property of {@see truncateOutput()} and is paid with or
     * without an instruction loader wired.
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
     * $budget <= 0 disables the bound, matching {@see truncateOutput()}. No
     * caller reaches it any more — instrumented and measured across the whole
     * suite, zero calls arrive with a non-positive budget — but a caller that
     * has a reserve and no room left in it must still pass 1, not 0, because
     * passing the arithmetic straight through is how the first cut of
     * this change silently disabled the very bound it added, and how the
     * SECOND cut did it again thirty lines below: the set loop handed
     * `intdiv($remaining, $count - $i)` straight in, so `intdiv(0, n) === 0`
     * hit this sentinel and every body after the reserve ran out came back
     * VERBATIM. MEASURED through `new Glob($dir, $loader)` at the shipped
     * 65,536-byte default, two files and one instruction file per directory:
     * 200 directories returned 199,767 bytes, 300 returned 551,537, and 500
     * returned 1,091,833 — 16.7x the cap, a worse exemption than the one this
     * change exists to close.
     *
     * A byte guard alone does not fix it, which is why there are two. Each
     * body costs a floor of {@see instructionBodyFloor()} bytes that no share
     * can pay for, so the section grows LINEARLY in the number of governing
     * files whatever the share arithmetic says; {@see instructionSection()}
     * therefore bounds the COUNT before any body is loaded.
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

        // No `dropped <= 0` short-circuit here, and its absence is the point.
        // The early return above already guarantees strlen($text) > $budget,
        // and $room is $budget less a positive marker cost, so the clip always
        // loses bytes. A branch guarding that read as prudence and was in fact
        // unreachable — mutating it to `if (false)` left the whole suite green,
        // which is the only evidence that matters about a branch nothing can
        // enter. The invariant is stated here instead of being enforced by
        // code no input reaches.
        // A window with NO newline in it is a fragment of the first line, not
        // a line — clipToLine() keeps one deliberately, because for a result
        // a fragment beats nothing. For a RULE it does not: `# BIG-RU` names
        // its subject worse than the bounded head does, and worse than the
        // same call with a SMALLER budget, which took the head path and
        // returned `# BIG-RULE` whole. MEASURED at f1fda934, Grep cap 400:
        // an 8-byte `# BIG-RU` where the head path gives `# BIG-RULE`. So the
        // head is the fallback for every fragment, not only for the empty one
        // — that is what instructionBodyFloor() is priced for.
        $kept = $clip['kept'];
        if (!str_contains($kept, "\n")) {
            $firstLine = strstr($text, "\n", true);
            // mb_strcut, not substr: this is the one clip in the instruction
            // path with NO line-boundary fallback behind it, so a plain byte
            // cut lands inside a UTF-8 sequence and puts invalid bytes into a
            // tool result the model reads. MEASURED through Read before this:
            // a first line of 118 'A' then U+2014 came back as
            // `41 41 41 41 e2 80 0a`, a truncated three-byte sequence, and
            // mb_check_encoding() returned false at first-line offsets 118
            // and 119.
            $kept = mb_strcut(
                $firstLine === false ? $text : $firstLine,
                0,
                self::INSTRUCTION_HEAD_BYTES,
                'UTF-8',
            );
        }

        return $kept === ''
            ? $this->instructionTruncationMarker($total, $total)
            : $kept . "\n" . $this->instructionTruncationMarker($total - strlen($kept), $total);
    }

    /**
     * The smallest number of bytes ONE instruction body can occupy.
     *
     * A body clipped to nothing still emits its first line and its own marker
     * — {@see clipInstructions()} guarantees that so a withheld rule names its
     * subject — plus the newline `implode()` puts between entries. Nothing
     * charges that floor against the reserve, so it is what makes the section
     * grow linearly in the NUMBER of governing files however the byte shares
     * are divided. MEASURED with the share arithmetic guarded and the count
     * unbounded, one `CLAUDE.md` and one file per directory: 800 governed
     * directories returned 129,517 bytes against the 65,536-byte default cap
     * (2.0x) and 1,500 returned 144,245 (2.2x). It PLATEAUS there rather than
     * growing without limit, and only because {@see
     * \SugarCraft\Crush\Tools\BuiltIn\Glob::DEFAULT_MAX_MATCHES} stops the walk
     * at a thousand paths — a ceiling on a different quantity, in one of the
     * two tools, which is not a bound on this one. Bounding the bytes is
     * necessary and is not sufficient; {@see instructionSection()} bounds the
     * count too, and at every size above the total holds at 1.0x.
     *
     * The marker is sized with PHP_INT_MAX in both slots because that is its
     * longest possible form, which makes this a true upper bound rather than
     * an estimate that a large file quietly exceeds.
     */
    private function instructionBodyFloor(): int
    {
        return self::INSTRUCTION_HEAD_BYTES
            + 1
            + strlen($this->instructionTruncationMarker(PHP_INT_MAX, PHP_INT_MAX))
            + 1;
    }

    /**
     * The most bytes an instruction section can ever occupy.
     *
     * The reserve, EXCEPT where a single body's floor does not fit inside it.
     * The two invariants "the section spends at most a quarter" and "a rule
     * governing a path the model was shown is surfaced" are in direct conflict
     * once the quarter is smaller than one body floor, and this is where the
     * conflict is resolved: the rule is shown, and the RESULT budget is
     * computed from this ceiling rather than from the reserve, so the total
     * still lands inside the cap. Callers size their result floor with this,
     * which is what keeps their probe no tighter than their final clip.
     */
    private function instructionSectionCeiling(int $maxOutputBytes): int
    {
        return max($this->instructionBudget($maxOutputBytes), $this->instructionBodyFloor());
    }

    /**
     * Introduces the section. LABELLED, because it lands after a run of
     * `... [note]` lines and an unlabelled markdown blob in that position is
     * indistinguishable from tool output that failed to parse.
     */
    private function instructionSetLabel(int $shown): string
    {
        return sprintf(
            "... [instructions: %d file(s) govern the matched paths, surfaced once per session:]\n",
            $shown,
        );
    }

    /**
     * Says how many paths were never LOOKED AT, which is a different statement
     * from "how many rules were dropped" and the only one that is true.
     *
     * The count is of paths rather than of instruction files precisely because
     * the files were never loaded — see {@see instructionSection()} for why
     * they must not be. Most paths in a long list have no governing file of
     * their own, or one already surfaced this session, so this is an upper
     * bound on what is missing and is worded as one.
     */
    private function instructionsWithheldNote(int $withheld): string
    {
        return sprintf(
            "... [instructions: %d further path(s) not examined for governing rules.]\n",
            $withheld,
        );
    }

    /**
     * The whole instruction section for $paths, bounded by a QUARTER of
     * $maxOutputBytes in COUNT as well as in bytes.
     *
     * TWO BOUNDS, BECAUSE ONE OF THEM CANNOT WORK ALONE. A byte share divided
     * among N bodies still pays {@see instructionBodyFloor()} per body, which
     * no share is charged for, so "at most a quarter of the cap" and "one
     * entry per governing file" are in direct conflict once N is unbounded —
     * and the first cut of this change resolved that conflict by silently
     * dropping the cap. The conflict is resolved here the other way: the CAP
     * wins, the count of bodies is bounded to what the reserve can actually
     * hold, and the paths beyond it are counted rather than shown.
     *
     * THE COUNT BOUND IS CHECKED BEFORE loadForPath(), AND THAT ORDERING IS
     * THE WHOLE OF IT.
     * {@see \SugarCraft\Crush\Context\InstructionFileLoader::loadForPath()}
     * marks a file emitted AT LOAD TIME, so a body loaded and then dropped is
     * an instruction file retired for the rest of the session without ever
     * having been shown — the precise failure
     * `GrepInstructionWiringTest::testTheAnnounceOnceMarkIsSpentOnlyOnWhatTheModelReceived()`
     * exists to prevent. A path never EXAMINED is not retired: the next call
     * with room in its reserve surfaces its rules in full. Bounding the count
     * after loading would have been the same defect wearing a bound.
     *
     * The room left is re-read before every path rather than divided 1/n up
     * front, so short bodies — the common case, a nested `CLAUDE.md` is
     * usually a few lines — leave their unused room to the bodies after them
     * instead of stranding it, and the count that fits is decided by what the
     * bodies actually cost rather than by their worst case.
     *
     * The bound is exact and not approximate: a body is loaded only while the
     * room left less the withheld note's cost is at least one body floor, and
     * it is then clipped to one byte under that room, so `label + bodies +
     * note <= reserve` holds at EVERY cap. Where the reserve cannot hold even
     * the note, the section is empty — no rules are shown and, because none
     * were loaded, none are spent.
     *
     * @param list<string> $paths
     */
    private function instructionSection(array $paths, ?InstructionFileLoader $loader, int $maxOutputBytes): string
    {
        if ($loader === null || $paths === []) {
            return '';
        }

        $capped = $maxOutputBytes > 0;
        $room = $capped ? $this->instructionSectionCeiling($maxOutputBytes) : PHP_INT_MAX;
        $bodyFloor = $this->instructionBodyFloor();

        // Sized with count($paths) in both slots for the reason
        // truncateMerged() sizes its marker with $total in both: a cost the
        // budget under-reserves is a cost the budget does not bound. Neither
        // count printed below can exceed the number of paths handed in.
        $labelCost = strlen($this->instructionSetLabel(count($paths)));
        $noteCost = strlen($this->instructionsWithheldNote(count($paths)));

        // The note is only owed when a path goes unexamined, which cannot
        // happen with one path in hand.
        $noteRoom = count($paths) > 1 ? $noteCost : 0;
        // Held UP FRONT only where a body can still follow it. Where it cannot,
        // the label is decided after the loop out of what the bodies left —
        // a reserve too small to hold the label AND a worst-case body is
        // usually big enough to hold the label and the SHORT body that
        // actually turned up.
        $labelRoom = $room >= $labelCost + $noteRoom + $bodyFloor ? $labelCost : 0;

        $bodies = [];
        $spent = $labelRoom;
        $examined = 0;

        foreach ($paths as $path) {
            // THE COUNT BOUND, AND IT IS CHECKED BEFORE loadForPath().
            // The FIRST path is always examined, because a governing rule the
            // model is shown a path for has to be surfaced somewhere and this
            // is the only place it can be; every path after it is examined
            // only while a whole body floor still fits.
            if ($capped && $bodies !== [] && $room - $spent - $noteRoom < $bodyFloor) {
                break;
            }

            $examined++;
            $body = $loader->loadForPath($path);
            if ($body === null) {
                continue;
            }

            // -1 so the newline implode() adds after this body is inside the
            // room measured, and max(1, ...) because 0 is this trait's "no
            // cap" sentinel: handing the arithmetic straight through is the
            // defect being fixed.
            //
            // THE max() IS UNREACHABLE TODAY AND IS KEPT ANYWAY. $room is at
            // least instructionBodyFloor(), the note costs less than that, and
            // the guard above leaves every body after the first a whole floor,
            // so this expression cannot come out below 1. MEASURED rather than
            // argued: instrumenting this site and clipInstructions()'s own
            // sentinel and running the entire suite reported ZERO calls with a
            // non-positive budget, and a mutation to max(0, ...) leaves the
            // suite green — the honest reading of which is that the branch has
            // no reachable input, not that it is untested. It stays because
            // this exact arithmetic has silently disabled this exact bound
            // twice: once by passing intdiv() into clipInstructions(), once by
            // rounding a quarter to zero in Read and Glob.
            $clipped = $capped
                ? $this->clipInstructions($body, max(1, $room - $spent - $noteRoom - 1))
                : $body;

            $bodies[] = $clipped;
            $spent += strlen($clipped) + 1;
        }

        $withheld = count($paths) - $examined;
        if ($bodies === []) {
            return $withheld > 0 && $noteCost <= $room
                ? $this->instructionsWithheldNote($withheld)
                : '';
        }

        // What the bodies left. The note goes first because its absence loses
        // INFORMATION — how much was not looked at — where the label's absence
        // loses only presentation, and a clipped body already carries the word
        // `instructions` in its own marker.
        $leftover = $room - $spent;

        $note = '';
        if ($withheld > 0 && $noteCost <= $leftover) {
            $note = "\n" . $this->instructionsWithheldNote($withheld);
            $leftover -= $noteCost;
        }

        $label = $labelRoom > 0 || $labelCost <= $leftover
            ? $this->instructionSetLabel(count($bodies))
            : '';

        return $label . implode("\n", $bodies) . $note;
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
