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
 * THE OLD WINDOW WAS `preg_split('/\n\s*\n/')` — a blank line, and nothing
 * else. Markdown does not put blank lines between table rows, between list
 * items, or inside a fenced code block, so each of those collapsed into ONE
 * unit. Measured on this tree at round 45 (PHP 8.3.6): `README.md` split into
 * 164 units under that rule, of which one was 14,244 bytes — a lead paragraph
 * plus five subsystem bullets — and `docs/ENVIRONMENT.md`'s whole variable
 * table was a single 10,828-byte unit. Those figures are a snapshot and are
 * not asserted anywhere; what IS asserted is the behaviour they illustrate,
 * in {@see \SugarCraft\Crush\Tests\Config\DocumentParagraphsTest}.
 *
 * WHY A COARSE WINDOW IS A DEFECT AND NOT MERELY UNTIDY. Two of the guards
 * decide their verdict by asking whether the SAME unit also contains an
 * exculpating phrase:
 *
 * - `GlobFigureDriftTest::carriesTheStaleFigure()` spares a unit that spells
 *   the current count and quotes the glob — a retraction. In a 14 KB unit that
 *   retraction may be six bullets away from the stale sentence it exempts.
 * - `ConfigWriteProducerDocumentationDriftTest::testOneParagraphNamesAllFourDoors()`
 *   requires ONE unit to name all four producers. A table whose four rows each
 *   name one satisfies it without any single row making the claim.
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
 * the half that is easy to miss: a fenced block containing a blank line was
 * cut in two, and its closing fence was glued onto the prose paragraph that
 * followed. `docs/AGENTS_AUTHORING.md`'s `yaml` preset example, `docs/SKILLS.md`'s
 * frontmatter example and `README.md`'s `ProviderInterface` example were all
 * in that state — prose and code in one unit, with an unbalanced fence in it.
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
 * THE DOC-BLOCK LEADER STRIP is inherited unchanged: the opening `/**`, the closing marker, and a leading
 * `*` are removed so a `*`-prefixed doc-block line and a markdown line reach
 * the same shape. IT WOULD EAT A MARKDOWN `*` BULLET MARKER. Measured at round
 * 45: `grep -rE '^\s*\*\s' docs/*.md README.md` matches nothing, so no page in
 * scope uses `*` bullets today and the hazard is latent rather than live. It is
 * pinned by a fixture rather than left to be rediscovered.
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
