<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Config\Support\DocumentParagraphs;

/**
 * THE SHARED WINDOW, AND THE BLIND SPOT IT USED TO HAVE.
 *
 * {@see DocumentParagraphs} replaced three byte-identical private
 * `paragraphs()` methods. A refactor that preserved the blind spot in one
 * place instead of three would have fixed nothing, so the deliverable here is
 * not the consolidation — it is {@see windowFixtures()}, which runs the OLD
 * rule and the NEW rule over the same text and records what each one sees.
 *
 * THE OLD RULE IS RE-IMPLEMENTED IN THIS FILE, in {@see blankLineRule()}, and
 * that is deliberate. A fixture table claiming "the old rule saw one unit
 * here" is a figure without a generator if the old rule is only a memory; with
 * it present, every row is re-derived on every run and a row that stops being
 * true reds instead of rotting. It is a byte-for-byte copy of what the three
 * suites carried at `06126017` — `git show 06126017:sugar-crush/tests/Config/GlobFigureDriftTest.php`
 * has the original.
 *
 * @internal
 */
final class DocumentParagraphsTest extends TestCase
{
    /**
     * The window as it was: split on a blank line, and nothing else.
     *
     * @return list<string>
     */
    private function blankLineRule(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $lines[] = preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? $line;
        }

        $out = [];
        foreach (preg_split('/\n\s*\n/', implode("\n", $lines)) ?: [] as $para) {
            $normalised = trim((string) preg_replace('/\s+/', ' ', $para));
            if ($normalised !== '') {
                $out[] = $normalised;
            }
        }

        return $out;
    }

    /**
     * Every fixture, with what each rule makes of it.
     *
     * `$oneUnitHoldsBoth` is the blind spot in one boolean: does SOME single
     * unit contain both `$needleA` and `$needleB`? That is the exact question
     * `GlobFigureDriftTest`'s retraction exemption and
     * `ConfigWriteProducerDocumentationDriftTest`'s four-doors rule each ask,
     * so a fixture where it answers `true` under the old rule and `false`
     * under the new one is a defect the old window could not see.
     *
     * @return iterable<string, array{0: string, 1: int, 2: int, 3: string, 4: string, 5: bool, 6: bool}>
     */
    public static function windowFixtures(): iterable
    {
        // ── the headline: a table ────────────────────────────────────────
        $table = <<<'MD'
            | Key | Note |
            |---|---|
            | `alpha` | the glob is eight characters long |
            | `beta`  | unrelated |
            | `gamma` | retracted: `[!B]*` is five characters |
            MD;

        yield 'a table row is its own claim' => [$table, 1, 5, 'eight characters', 'five characters', true, false];

        // ── a bullet list ────────────────────────────────────────────────
        $bullets = <<<'MD'
            - `alpha` writes the first key
            - `beta` writes the second key
            - `gamma` writes nothing at all
            MD;

        yield 'a list item is its own claim' => [$bullets, 1, 3, 'first key', 'second key', true, false];

        // ── a fence with a blank line in it ──────────────────────────────
        $fenceWithBlank = <<<'MD'
            ```yaml
            name: reviewer

            description: reviews a diff
            ```
            The body is the prompt when no `initialPrompt:` is declared.
            MD;

        yield 'a blank line inside a fence does not split it' => [
            $fenceWithBlank, 2, 2, 'description: reviews a diff', 'The body is the prompt', true, false,
        ];

        // ── a fence butted against prose ─────────────────────────────────
        $fenceTouchingProse = <<<'MD'
            Set the key like this:
            ```json
            { "disabledTools": ["[!B]*"] }
            ```
            which removes ten of the eleven tools.
            MD;

        yield 'a fence is never merged with the prose around it' => [
            $fenceTouchingProse, 1, 3, 'Set the key', 'removes ten', true, false,
        ];

        // ── the direction the new rule is COARSER in ─────────────────────
        // Not every difference narrows. A fenced block containing a blank line
        // is TWO units under the old rule and ONE under the new one, so a
        // retraction anywhere inside a block now exempts a stale figure
        // elsewhere in the SAME block. That is a real weakening of
        // GlobFigureDriftTest::carriesTheStaleFigure() and it is here so the
        // fixture table measures both directions rather than only the flattering
        // one.
        $fenceHoldingTwoClaims = <<<'MD'
            ```json
            { "disabledTools": "the glob is eight characters long" }

            { "disabledTools": "retracted: `[!B]*` is five characters" }
            ```
            MD;

        yield 'a fence welds two claims a blank line used to separate' => [
            $fenceHoldingTwoClaims, 2, 1, 'eight characters', 'five characters', false, true,
        ];

        // ── the case that must NOT change ────────────────────────────────
        $prose = <<<'MD'
            The first paragraph makes one claim.

            The second paragraph makes a different one.
            MD;

        yield 'plain prose is untouched' => [$prose, 2, 2, 'first paragraph', 'second paragraph', false, false];

        // ── an inline code span at the head of a wrapped line ────────────
        // The shape from src/Providers/ToolCallParser/MinimaxXmlFallbackToolCallParser.php.
        // A fence detector without CommonMark's info-string rule opens a fence
        // here that never closes, and swallows the whole rest of the text.
        $inlineSpan = <<<'MD'
            a message reading "to call a tool you emit markup like this:
            ```<minimax:tool_call>…name="rm_rf"…</minimax:tool_call>``` … I have not
            actually called anything" returned ONE REAL CALL.

            The parser whose purpose is that a call is never MISSED was INVENTING one.
            MD;

        yield 'an inline ``` span does not open a fence' => [
            $inlineSpan, 2, 2, 'returned ONE REAL CALL', 'INVENTING one', false, false,
        ];
    }

    /**
     * @dataProvider windowFixtures
     */
    public function testTheFixtureTableIsWhatBothRulesActuallyDo(
        string $fixture,
        int $oldUnits,
        int $newUnits,
        string $needleA,
        string $needleB,
        bool $oldHoldsBoth,
        bool $newHoldsBoth,
    ): void {
        $old = $this->blankLineRule($fixture);
        $new = DocumentParagraphs::of($fixture);

        $this->assertCount($oldUnits, $old, 'the old blank-line rule no longer produces the units this row records');
        $this->assertCount($newUnits, $new, 'the new rule no longer produces the units this row records');

        $this->assertSame(
            $oldHoldsBoth,
            $this->someUnitHoldsBoth($old, $needleA, $needleB),
            'the old rule\'s verdict on "does one unit hold both claims" moved',
        );
        $this->assertSame(
            $newHoldsBoth,
            $this->someUnitHoldsBoth($new, $needleA, $needleB),
            'the new rule\'s verdict on "does one unit hold both claims" moved',
        );
    }

    /**
     * At least one fixture must EXHIBIT the narrowing — and one the widening.
     *
     * Without the narrowing check, every row of the table could quietly become
     * `true => true` — a table that proves the two rules agree everywhere,
     * shipped as evidence that one of them is better.
     *
     * THE WIDENING CHECK IS THE HALF THAT WAS MISSING, and its absence was the
     * same instrument failure twice over. The round-45 table counted
     * `$narrowed` and `$unchanged` only, so it could only ever report that the
     * new rule was finer; its sibling table in
     * {@see ReadmeJsonErrorContractDriftTest} already counted a `$widened`
     * column in the same diff. The new window is NOT uniformly finer: a fenced
     * block containing a blank line is two units under the old rule and one
     * under the new, which welds together claims the old window kept apart and
     * measurably weakens
     * `GlobFigureDriftTest::carriesTheStaleFigure()`'s retraction exemption
     * inside such a block. Counting only the flattering direction is how a
     * trade-off gets shipped as an improvement.
     */
    public function testTheTableContainsFixturesTheOldWindowCouldNotSee(): void
    {
        $narrowed = [];
        $widened = [];
        $unchanged = [];
        foreach (self::windowFixtures() as $label => [, , , , , $oldHoldsBoth, $newHoldsBoth]) {
            if ($oldHoldsBoth && !$newHoldsBoth) {
                $narrowed[] = $label;
            }
            if (!$oldHoldsBoth && $newHoldsBoth) {
                $widened[] = $label;
            }
            if ($oldHoldsBoth === $newHoldsBoth) {
                $unchanged[] = $label;
            }
        }

        $this->assertNotEmpty(
            $widened,
            'the fixture table no longer records the direction in which the new window is COARSER '
            . 'than the old one, so it reads as a table of pure improvement',
        );

        $this->assertGreaterThanOrEqual(
            4,
            \count($narrowed),
            'the fixture table no longer exhibits the blind spot the new rule exists to close',
        );
        $this->assertNotEmpty(
            $unchanged,
            'every fixture narrows, so the table says nothing about the new rule leaving ordinary prose alone',
        );
    }

    /**
     * THE OLD WINDOW CUT FENCED BLOCKS IN TWO, AND THAT IS LIVE IN SCOPE.
     *
     * {@see DocumentParagraphs}' class doc-block used to name three documents
     * and quote the number three. It is roughly fourfold that, and five of the
     * affected documents were never named — the enumeration listed the cases
     * that were already known, which is the failure mode that costs the most
     * because it looks like evidence. So the figure is DERIVED here and the
     * prose points at this test instead of quoting it: a count over `docs/` is
     * wrong the next time anyone edits a page, and a floor is the only shape of
     * that claim which stays true.
     *
     * DERIVED FROM THE TWO RULES DISAGREEING, NOT FROM A SECOND FENCE DETECTOR.
     * A copy of {@see DocumentParagraphs}' `opensFence()`/`closesFence()` pair
     * living in the test would agree with the shipped one by construction and
     * would stop agreeing the moment either was edited. Instead a fenced unit
     * counts as SPLIT when no single old-rule unit contains it — which is
     * exactly the damage, and which correctly answers "not split" for a fence
     * the old rule merged with its neighbouring prose instead.
     *
     * THE KNOWN POSITIVE AND THE KNOWN NEGATIVE ARE BOTH RUN HERE. A floor
     * asserted over live documents also passes in a tree where the derivation
     * has stopped deriving; the three fixtures are what make the live figure
     * mean anything.
     */
    public function testTheOldWindowsFenceSplitIsLiveInScope(): void
    {
        $this->assertSame(
            1,
            $this->fencedUnitsTheOldRuleSplit("```yaml\nname: reviewer\n\ndescription: x\n```\n"),
            'the derivation cannot see the old rule splitting a fence that contains a blank line, '
            . 'so the live figure below is not evidence of anything',
        );
        $this->assertSame(
            0,
            $this->fencedUnitsTheOldRuleSplit("```yaml\nname: reviewer\ndescription: x\n```\n"),
            'the derivation reports a split for a fence with no blank line in it, so it is counting '
            . 'something other than the damage',
        );
        $this->assertSame(
            0,
            $this->fencedUnitsTheOldRuleSplit("Set it:\n```json\n{}\n```\nwhich removes ten.\n"),
            'the derivation counts a fence the old rule MERGED with its prose as one it SPLIT — the '
            . 'two are opposite defects and the figure would be the sum of both',
        );

        $live = [];
        foreach ($this->scope() as $label => $text) {
            $split = $this->fencedUnitsTheOldRuleSplit($text);
            if ($split > 0) {
                $live[$label] = $split;
            }
        }

        $this->assertGreaterThan(
            0,
            array_sum($live),
            'no document in scope still has a fenced block containing a blank line, so the class '
            . 'doc-block\'s claim that the old window\'s fence split was LIVE rather than '
            . 'hypothetical has stopped being true — rewrite it, do not delete it',
        );
        $this->assertGreaterThan(
            1,
            \count($live),
            'the fence split is confined to a single document, so the doc-block should say which '
            . 'one rather than describing a scope-wide shape',
        );
    }

    /**
     * How many fenced units of `$text` did the old blank-line rule cut apart?
     *
     * A fenced unit the old rule merely MERGED into a bigger unit is still held
     * whole by that unit, so `str_contains()` rather than `in_array()` is what
     * separates the two directions.
     */
    private function fencedUnitsTheOldRuleSplit(string $text): int
    {
        $old = $this->blankLineRule($text);

        $split = 0;
        foreach (DocumentParagraphs::of($text) as $unit) {
            if (preg_match('/^(?:`{3,}|~{3,})/', $unit) !== 1) {
                continue;
            }
            foreach ($old as $paragraph) {
                if (str_contains($paragraph, $unit)) {
                    continue 2;
                }
            }
            $split++;
        }

        return $split;
    }

    /** @param list<string> $units */
    private function someUnitHoldsBoth(array $units, string $a, string $b): bool
    {
        foreach ($units as $unit) {
            if (str_contains($unit, $a) && str_contains($unit, $b)) {
                return true;
            }
        }

        return false;
    }

    // ── the unclosed-fence guard ─────────────────────────────────────────

    /**
     * @return iterable<string, array{0: string, 1: int|null}>
     */
    public static function fenceBalance(): iterable
    {
        yield 'balanced' => ["prose\n\n```sh\nls\n```\n\nmore prose\n", null];
        yield 'unclosed' => ["prose\n\n```sh\nls\nmore prose\n", 3];
        yield 'a longer closer still closes' => ["```sh\nls\n`````\n", null];
        yield 'a shorter closer does not close' => ["````sh\nls\n```\n", 1];
        yield 'a tilde fence is not closed by backticks' => ["~~~sh\nls\n```\n", 1];
        yield 'an inline span opens nothing' => ["```a``` and more text\n", null];
    }

    /**
     * @dataProvider fenceBalance
     */
    public function testAnUnclosedFenceIsReportedRatherThanSwallowed(string $text, ?int $expected): void
    {
        $this->assertSame($expected, DocumentParagraphs::unclosedFenceAt($text));
    }

    /**
     * Nothing in the census scope has an unclosed fence.
     *
     * AND THE SAME SCANNER IS RUN OVER A KNOWN POSITIVE IN THIS TEST. An
     * assertion that a list is empty also passes in a tree where the scanner
     * has silently stopped matching, which is the failure round 44 measured:
     * a census mutated to never match reported "nothing is stale", entirely
     * green. The fixture below is the only thing that makes the empty result
     * mean anything.
     */
    public function testNoDocumentInScopeLeavesAFenceOpen(): void
    {
        $this->assertSame(
            4,
            DocumentParagraphs::unclosedFenceAt("a\nb\n\n```json\n{\n"),
            'the unclosed-fence scanner cannot find an unclosed fence in a fixture that has one, '
            . 'so the empty result below is not evidence of anything',
        );

        $open = [];
        foreach ($this->scope() as $label => $text) {
            $at = DocumentParagraphs::unclosedFenceAt($text);
            if ($at !== null) {
                $open[$label] = $at;
            }
        }

        $this->assertSame(
            [],
            $open,
            'a document in scope opens a fence it never closes, so every unit after that line is one '
            . 'giant unit and every doc-drift guard reading it is asserting about the wrong window',
        );
    }

    /**
     * THE LEADER STRIP RUNS ON PHP AND LEAVES MARKDOWN ALONE.
     *
     * WHAT THIS TEST SAID, until this round: "NO PAGE IN SCOPE USES `*`
     * BULLETS, AND THAT IS LOAD-BEARING" — the strip would eat a markdown
     * `* item` marker, no page used one, so the hazard was "latent rather than
     * live", pinned by a probe for `/^[ \t]*\*[ \t]+\S/m`.
     *
     * WHAT IS TRUE NOW: the pin was scoped narrower than the family it belonged
     * to, and the hazard was never latent. The same strip eats the first
     * asterisk of a line-leading `**bold**` lead — `**Bold lead**` became
     * `*Bold lead**` — and the probe required whitespace after the marker, so
     * it could not match one of those. The live count is derived below rather
     * than written into this sentence; it is the large majority of the
     * line-leading asterisks in scope, spread across every markdown page that
     * has any. Nothing was broken by it: no needle any Config guard uses sits
     * on such a line. What was wrong was the pin, which gave cover the family
     * did not have.
     *
     * WHY THE CONCERN STILL EARNS A TEST: the strip is now conditional on the
     * text being PHP ({@see DocumentParagraphs}' `isPhpSource()`), so both
     * shapes are safe — but a discriminator is a thing that can be got wrong in
     * two directions, and the census feeds WHOLE `.php` FILES through this
     * window as well as reflected doc-comments. So both directions are asserted
     * here on fixtures, and the classification is asserted over the live scope.
     */
    public function testTheLeaderStripAppliesToPhpSourceOnly(): void
    {
        $this->assertSame(
            ['one claim.', '- a bullet', '- another bullet'],
            DocumentParagraphs::of("/**\n * one claim.\n *\n * - a bullet\n * - another bullet\n */"),
            'the leader strip no longer runs on a reflected doc-comment, which is nothing but '
            . 'leader-prefixed lines — every doc-block unit in every Config guard is now wrong',
        );
        $this->assertSame(
            ['<?php', 'a claim in a doc-block.'],
            DocumentParagraphs::of("<?php\n\n/**\n * a claim in a doc-block.\n */\n"),
            'the leader strip no longer runs on a whole .php file, which is how '
            . 'GlobFigureDriftTest::censusScope() feeds src/ through this window',
        );

        $this->assertSame(
            ['intro', '* one', '* two'],
            DocumentParagraphs::of("intro\n\n* one\n* two\n"),
            'the leader strip is eating a markdown `*` bullet marker, so the item re-merges into '
            . 'whatever precedes it — the exact blind spot this window closes for `-` bullets',
        );
        $this->assertSame(
            ['**Bold lead** and the rest of the sentence.'],
            DocumentParagraphs::of("**Bold lead** and the rest of the sentence.\n"),
            'the leader strip is eating the first asterisk of a line-leading `**bold**` lead',
        );

        // AND THE DISCRIMINATOR AGREES WITH THE FILE EXTENSIONS IN LIVE SCOPE.
        // A fixture pair proves the two branches exist; only this proves the
        // right branch is taken for the documents the guards actually read.
        //
        // THE FIRST FORM OF THIS CHECK ASKED THE WRONG QUESTION and reported
        // ten false offenders, which is worth recording because the mistake is
        // the one rule 2 names. It compared `of($text)` against `of($text)`
        // with the classification forced to markdown, and called them "the same"
        // evidence of "not stripped". For a `.php` file with no doc-comment
        // line at all the two are identical because there is nothing to strip,
        // not because the wrong branch was taken. The probe below asks the
        // discriminator directly: an appended leader-prefixed sentinel survives
        // as `* SENTINEL` on markdown and as `SENTINEL` on PHP, and appending
        // cannot change a classification keyed to the start of the text. It is
        // safe only because `testNoDocumentInScopeLeavesAFenceOpen()` holds —
        // an unclosed fence would swallow the sentinel.
        $misfiled = [];
        foreach ($this->scope() as $label => $text) {
            $probed = DocumentParagraphs::of($text . "\n\n * SENTINEL\n");
            $treatedAsPhp = \in_array('SENTINEL', $probed, true);
            if (!$treatedAsPhp && !\in_array('* SENTINEL', $probed, true)) {
                $misfiled[$label] = 'the sentinel did not survive in either form, so this row measured nothing';

                continue;
            }
            if ($treatedAsPhp !== str_ends_with($label, '.php')) {
                $misfiled[$label] = $treatedAsPhp ? 'stripped, but is not PHP' : 'not stripped, but is PHP';
            }
        }
        $this->assertSame([], $misfiled, 'the leader-strip discriminator disagrees with the file extension');

        // AND THE CORRUPTING SHAPE IS LIVE, so the conditional strip is a
        // repair rather than a hypothetical. Derived, not quoted: a count over
        // docs/ is wrong the next time anyone edits a page.
        $boldLead = 0;
        foreach ($this->scope() as $label => $text) {
            if (!str_ends_with($label, '.md')) {
                continue;
            }
            $boldLead += preg_match_all('/^[ \t]*\*\*/m', $text);
        }
        $this->assertGreaterThan(
            0,
            $boldLead,
            'no markdown page in scope has a line-leading `**bold**` lead any more, so the '
            . 'justification above has stopped describing this tree — rewrite it, do not delete it',
        );
    }

    /**
     * `src/**.php`, `docs/**.md` and `README.md`.
     *
     * The same shape as `GlobFigureDriftTest::censusScope()` plus `README.md`,
     * which that census leaves out on lane grounds rather than on principle.
     *
     * @return array<string, string>
     */
    private function scope(): array
    {
        $lib = realpath(__DIR__ . '/../..');
        self::assertIsString($lib);

        $scope = ['README.md' => self::readOrFail($lib . '/README.md')];
        foreach ([['src', 'php'], ['docs', 'md']] as [$subdir, $extension]) {
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib . '/' . $subdir));
            foreach ($walk as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== $extension) {
                    continue;
                }
                $scope[substr($file->getPathname(), \strlen($lib) + 1)] = self::readOrFail($file->getPathname());
            }
        }
        ksort($scope);

        self::assertArrayHasKey('docs/SETTINGS.md', $scope);
        self::assertArrayHasKey('src/Config/LayeredSettings.php', $scope);

        return $scope;
    }

    private static function readOrFail(string $path): string
    {
        $text = file_get_contents($path);
        self::assertIsString($text, $path . ' could not be read');

        return $text;
    }
}
