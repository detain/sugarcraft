<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * One implementation of `flattened()` for the guards that match PROSE against
 * PHP source, and the union of the two justifications its copies carried.
 *
 * WHY PROSE IN SOURCE MAY NOT BE MATCHED AGAINST THE RAW BYTES, which is the
 * whole reason this exists. A doc-block wraps at 80 columns with ` * ` on every
 * continuation, so a sentence is never those bytes in a row. Round 44 shipped
 * an `assertStringNotContainsString(<sentence>, $rawSource)` that survived
 * re-adding the very sentence it existed to forbid — the defect the fix was
 * committed to close, committed inside the fix for it. Anything anchoring a
 * regex at prose in a `.php` or `.md` file has to strip the continuation
 * markers first, and this is that strip.
 *
 * WHERE THIS CAME FROM AND WHY IT IS ONE METHOD NOW (E196/E224). WHAT THE TWO
 * COPIES SAID: `StderrEmitterCensusTest`'s read "DELIBERATELY A SECOND COPY …
 * the shared home for it is a test-support trait, and adding one is outside the
 * file set round 45's lane may touch — and outside round 48's too, which is why
 * E196 is still open." WHAT IS TRUE NOW: round 49's lane held both consumers,
 * so the trait was in scope and this is it.
 *
 * THE DRIFT E196 PREDICTED HAD ALREADY STARTED, AND IT STARTED IN THE PROSE
 * RATHER THAN IN THE CODE. MEASURED at round 48 by comparing the two
 * declarations token by token with whitespace and comments dropped: the BODIES
 * were identical. The justifications were not — one copy carried a paragraph on
 * why the second pattern is `\s+` and not `[ \t]+` and the other did not, and
 * that paragraph was brought across rather than left, because the copy that
 * loses a reason is the copy whose next reader simplifies it.
 *
 * WHICH IS WHY THIS DOC-BLOCK IS A UNION AND NOT A PICK. A consolidation that
 * keeps one implementation and one of the two justifications re-creates exactly
 * the asymmetry it was meant to remove, one level in: the surviving copy now
 * looks canonical, so the reason it dropped is the reason nobody goes looking
 * for. Both copies' reasoning is here. Where a reason was true of only ONE
 * consumer it stayed with that consumer rather than being generalised into a
 * claim about all of them — see `BootstrapTranscriptSeamCallSiteCensusTest`,
 * whose note on why its own per-file anchors deliberately do NOT run through
 * this method is attached to its `use` of this trait, and is itself anchored
 * prose that this trait's guards read.
 *
 * (The instrument that found the drift was wrong on its first run: anchored on
 * the token `flattened`, it compared the first CALL site in each file instead
 * of the declaration, and reported "not identical". Anchoring on `T_FUNCTION`
 * gave the answer above. Recorded because a comparison harness that silently
 * compares the wrong two things is the failure the consuming censuses are
 * about.)
 *
 * EVERY CONSUMER KEEPS ITS OWN KNOWN-POSITIVE CONTROL, and sharing the code is
 * not sharing the control (E125, rule 15). A flattener that silently returned
 * `''` would make every anchor in every consumer fail open into a zero match,
 * and a zero match is exactly what an assertion of "the anchor matched" cannot
 * distinguish from a dead instrument. So each consuming test asserts this
 * method's output on a synthetic wrapped fixture BEFORE it trusts it on a real
 * file — and each consumer's fixture is built by CONCATENATION rather than
 * written whole, because these tests scan their own source and a fixture
 * spelling an anchor phrase contiguously becomes a second match for it.
 * Measured, in both consumers: it did, on the first attempt in each.
 */
trait FlattensSourceProseTrait
{
    /**
     * `$source` with doc-block and line-comment continuation markers removed
     * and every run of whitespace collapsed to one space.
     */
    private static function flattened(string $source): string
    {
        // `\*(?!/)` — the CONTINUATION marker, never the terminator. Letting
        // `*/` be stripped too would run the end of one doc-block into the
        // start of the next, and an anchor could then match a "sentence" that
        // spans two of them and exists in neither.
        $joined = (string) preg_replace('#\n\s*(?:\*(?!/)|//)[ \t]?#', ' ', $source);

        // `\s+` and not `[ \t]+`: the marker strip leaves the newline of any
        // line it did not match (a bare code line, the last line of a file), and
        // a sentence that wraps onto one of those would still be split. Caught
        // by the fixture in the caller, which is the reason that fixture is a
        // known-POSITIVE and not a smoke test.
        return (string) preg_replace('/\s+/', ' ', $joined);
    }
}
