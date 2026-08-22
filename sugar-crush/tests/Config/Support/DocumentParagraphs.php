<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Support;

/**
 * THE WINDOW EVERY DOC-DRIFT GUARD ASSERTS INSIDE.
 *
 * Three suites — `GlobFigureDriftTest`, `ConfigWriteProducerDocumentationDriftTest`
 * and `ThemePersistenceFramingTest` — each carried a byte-identical private
 * `paragraphs()`. The duplication was the small half. The large half is that
 * all three asked the same question of the same window, so a claim the window
 * cannot isolate was invisible to EVERY doc-drift guard at once.
 *
 * THERE IS A FOURTH COPY, and it is NOT routed here yet:
 * `tests/Chat/ChatConfigChangeDoorsDocumentationDriftTest`'s `private static
 * function paragraphs()` is the same rule again, spelled `static`, which is why
 * a grep for the instance form finds three. It is left alone this round on
 * file-ownership grounds, not because it is different. MEASURED before saying
 * so, on PHP 8.3.6 at round 45: both doc-blocks it reads are plain prose with
 * no list, table or fence, so the old and new windows give it identical
 * verdicts and nothing is hiding in it today. It is a latent carrier of the
 * blind spot, and routing it here is a one-line change.
 *
 * THE OLD WINDOW WAS `preg_split('/\n\s*\n/')` — a blank line, and nothing
 * else. Markdown does not put blank lines between table rows, between list
 * items, or inside a fenced code block, so each of those collapsed into ONE
 * unit. NO CARDINALITY IS QUOTED HERE ON PURPOSE — a count measured over
 * `docs/` is wrong the next time anyone edits a page, and a stale figure
 * inside the helper the doc-drift guards read through is not a joke worth
 * shipping twice. The SHAPES are what matter, and both were live on this tree
 * at round 45 (PHP 8.3.6): `README.md`'s largest unit was a lead paragraph
 * welded to five subsystem bullets, and the whole variable table on
 * `docs/ENVIRONMENT.md` was one unit. What is ASSERTED is the behaviour those
 * shapes illustrate — see
 * {@see \SugarCraft\Crush\Tests\Config\DocumentParagraphsTest}'s fixture
 * table, which derives BOTH rules' answers on every run.
 *
 * WHY A COARSE WINDOW IS A DEFECT AND NOT MERELY UNTIDY. Two of the guards
 * decide their verdict by asking whether the SAME unit also contains an
 * exculpating phrase:
 *
 * - `GlobFigureDriftTest::carriesTheStaleFigure()` spares a unit that spells
 *   the current count and quotes the glob — a retraction. In a 14 KB unit that
 *   retraction may be six bullets away from the stale sentence it exempts.
 * - `ConfigWriteProducerDocumentationDriftTest`'s four-doors rule required ONE
 *   unit to name all four producers. A table whose four rows each name one
 *   satisfies that without any single row making the claim — and the finer
 *   window turned out to move its verdict on a real document, so the rule is
 *   now per key. That test is
 *   `testTheEnumerationNamesBothDoorsOfEveryPersistedKey()`; its doc-block
 *   carries the reasoning.
 *
 * and one decides by asking whether a locator picks out exactly one unit
 * (`soleParagraphContaining()`), which a 14 KB unit answers uselessly.
 *
 * THE RULE, STATED SO THE NEXT READER CAN CHECK IT: **a unit is the smallest
 * span a reader can judge on its own.**
 *
 * - A **fenced code block** is one unit, opening fence to closing fence. Blank
 *   lines inside it do NOT split it, and it is never merged with adjacent
 *   prose. It is not excluded: a sample `config.json` showing a wrong default
 *   is a stale claim like any other, and a scanner that quietly ignores what
 *   it cannot parse has a hole shaped exactly like the next defect. Giving it
 *   its own unit is what stops a retraction leaking across the fence in either
 *   direction.
 * - A **table row** is its own unit. Each row is an independent promise.
 * - A **list item** is its own unit, together with its continuation lines.
 * - Everything else splits on a blank line, exactly as before.
 *
 * THE OLD RULE ALSO MIS-SPLIT FENCES, in the opposite direction, and that is
 * the half that is easy to miss: a fenced block containing a blank line was cut
 * in two, leaving an unbalanced fence in each half. THE SHAPE IS LIVE IN SCOPE
 * AND THE COUNT IS DERIVED, NOT WRITTEN DOWN — see
 * {@see \SugarCraft\Crush\Tests\Config\DocumentParagraphsTest::testTheOldWindowsFenceSplitIsLiveInScope()},
 * which derives it from the two rules disagreeing rather than from a second
 * copy of the fence detector, and asserts a floor.
 *
 * AN EARLIER DRAFT OF THIS PARAGRAPH QUOTED A COUNT, AND THE COUNT IS THE
 * REASON THIS ONE DOES NOT. WHAT IT SAID: "Three were in that state at round
 * 45", naming `docs/AGENTS_AUTHORING.md`, `docs/SKILLS.md` and `README.md`, and
 * describing two distinct outcomes — the closing fence landing inside the
 * following prose paragraph in two of them, both halves being code in the
 * third. WHAT IS TRUE: on PHP 8.3.6, over this scope, it is roughly fourfold
 * that, `README.md` alone accounts for the whole quoted figure, and five
 * documents the sentence never named are affected — including two blocks in
 * one `src/` file. The outcome split was inverted as well: in the two named
 * documents the prose welded to the closing fence is prose INSIDE the block,
 * not the paragraph that follows it. WHY THE FINDING STILL EARNS ITS PLACE:
 * the direction is real and it is the half a reader misses; what was wrong was
 * quoting a cardinality over `docs/` at all, and enumerating the three cases
 * already known instead of asking how many there were.
 *
 * AN OPENING FENCE FOLLOWS COMMONMARK'S INFO-STRING RULE — the remainder of a
 * backtick fence's opening line may not itself contain a backtick. This is not
 * pedantry: without it, `src/Providers/ToolCallParser/MinimaxXmlFallbackToolCallParser.php`'s
 * doc-block, which quotes an inline ```` ```<minimax:tool_call>…``` ```` span
 * at the start of a wrapped line, opened a fence that never closed and
 * swallowed the rest of the file into one unit — SILENTLY. It was the first
 * thing this helper's own scan got wrong, which is why {@see unclosedFenceAt()}
 * exists and is asserted over the whole census scope rather than trusted.
 *
 * THE DOC-BLOCK LEADER STRIP is inherited unchanged: the opening `/**`, its
 * closing marker and a leading `*` are removed, so a `*`-prefixed doc-block
 * line and a markdown line reach the same shape. IT WOULD EAT A MARKDOWN `*`
 * BULLET MARKER, and the item would silently re-merge into whatever precedes
 * it — the exact blind spot this helper closes for `-` bullets. No page in
 * scope uses `*` bullets at round 45 (PHP 8.3.6), so the hazard is latent
 * rather than live. It is pinned by a test that runs its probe over a known
 * positive first, rather than left to be rediscovered.
 *
 * @internal
 */
final class DocumentParagraphs
{
    /**
     * The units of a doc-block or a markdown page, whitespace-normalised.
     *
     * NORMALISED because the claims being checked are line-wrapped: the phrase
     * `"Switch Model"` lands with a newline in the middle of it in
     * `docs/SETTINGS.md`, and a raw `str_contains()` would miss it and report a
     * line break as a defect.
     *
     * @return list<string>
     */
    public static function of(string $text): array
    {
        $units = [];
        $current = [];
        $fence = null;

        $flush = static function () use (&$current, &$units): void {
            $normalised = trim((string) preg_replace('/\s+/', ' ', implode("\n", $current)));
            if ($normalised !== '') {
                $units[] = $normalised;
            }
            $current = [];
        };

        foreach (self::lines($text) as $line) {
            if ($fence !== null) {
                $current[] = $line;
                if (self::closesFence($line, $fence)) {
                    $fence = null;
                    $flush();
                }

                continue;
            }

            $opens = self::opensFence($line);
            if ($opens !== null) {
                $flush();
                $fence = $opens;
                $current[] = $line;

                continue;
            }

            if (trim($line) === '') {
                $flush();

                continue;
            }

            if (self::isTableRow($line)) {
                $flush();
                $current[] = $line;
                $flush();

                continue;
            }

            if (self::opensListItem($line)) {
                $flush();
            }

            $current[] = $line;
        }

        $flush();

        return $units;
    }

    /**
     * Is this UNIT (as returned by {@see of()}) a list item or a table row?
     *
     * The finer window turns an enumeration into several units, so a guard that
     * used to read "the paragraph introducing the list" and get the list with
     * it now needs to walk forward deliberately. This is the predicate for that
     * walk: a run of consecutive item units following a lead-in sentence is the
     * enumeration that sentence introduces.
     */
    public static function startsAnItem(string $unit): bool
    {
        return self::opensListItem($unit) || self::isTableRow($unit);
    }

    /**
     * The 1-based line on which a fence opened and never closed, or `null`.
     *
     * A GUARD MUST GO RED ON WHAT IT CANNOT PARSE. An unclosed fence makes
     * {@see of()} swallow every following line into one unit, which is the
     * coarse-window defect this whole helper exists to remove, arriving by the
     * back door and looking exactly like a clean parse.
     */
    public static function unclosedFenceAt(string $text): ?int
    {
        $fence = null;
        $openedAt = null;

        foreach (self::lines($text) as $index => $line) {
            if ($fence !== null) {
                if (self::closesFence($line, $fence)) {
                    $fence = null;
                    $openedAt = null;
                }

                continue;
            }

            $opens = self::opensFence($line);
            if ($opens !== null) {
                $fence = $opens;
                $openedAt = $index + 1;
            }
        }

        return $openedAt;
    }

    /**
     * Lines with the doc-block leader removed.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $lines[] = preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? $line;
        }

        return $lines;
    }

    /** The opening fence marker this line starts, or `null`. */
    private static function opensFence(string $line): ?string
    {
        // `([^`]*)$` is CommonMark's info-string rule for a backtick fence, and
        // it is load-bearing here — see the class doc-block's MinimaxXml case.
        if (preg_match('/^\s*(`{3,})([^`]*)$/', $line, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^\s*(~{3,})/', $line, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function closesFence(string $line, string $fence): bool
    {
        $marker = $fence[0] === '`' ? '`' : '~';
        if (preg_match('/^\s*(' . $marker . '{3,})\s*$/', $line, $m) !== 1) {
            return false;
        }

        return \strlen($m[1]) >= \strlen($fence);
    }

    private static function isTableRow(string $line): bool
    {
        return preg_match('/^\s*\|/', $line) === 1;
    }

    private static function opensListItem(string $line): bool
    {
        return preg_match('/^\s*(?:[-+*]|\d+[.)])\s+/', $line) === 1;
    }
}
