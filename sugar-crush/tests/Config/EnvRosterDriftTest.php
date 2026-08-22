<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Config\Support\EnvReadScanner;

/**
 * `docs/ENVIRONMENT.md` OPENS WITH "EVERY ENVIRONMENT VARIABLE SUGARCRUSH
 * READS, IN ONE PLACE" — AND UNTIL NOW NOTHING CHECKED IT.
 *
 * That page is one of five claim families the round-43 audit recorded as
 * asserted-but-ungenerated: the `--flag` list, this env roster, the exit-code
 * table, the twelve built-in skills and the six agent presets. It is the roster
 * because it is the one already enumerated in `src/` — the variables are string
 * literals in the source, so the page has an oracle available and simply was
 * not wired to one. The page's own last table row records what that costs:
 * `SUGARCRUSH_DEBUG_SKILLS` was "the one variable `src/` reads that this page
 * did not list, which made the page's own 'every environment variable' claim
 * false", and it was found by hand.
 *
 * BOTH DIRECTIONS ARE ASSERTED, because they are different defects.
 * Read-but-undocumented is a variable a user cannot discover; documented-but-
 * unread is a variable a user sets and nothing happens. The first is what
 * `SUGARCRUSH_DEBUG_SKILLS` was; the second is what a rename leaves behind.
 *
 * THE ORACLE IS A TOKEN SCAN, NOT A `grep`, and that distinction is the whole
 * reason it is trustworthy — see {@see EnvReadScanner} for the four call shapes
 * it understands, the four string shapes it reads them in, and for its refusal
 * to drop what it cannot place (a refusal that was itself false for three
 * string shapes until round 44, and is now pinned by fixtures for each). Two things
 * follow that are worth stating here rather than leaving to the reader:
 *
 * - A prefixed name that appears ONLY inside a doc-comment or an error message
 *   is not a read. `src/Providers/ProviderFactory.php` mentions
 *   `SUGARCRUSH_REASONING_EFFORT` and `SUGARCRUSH_TOOL_CALL_PARSER` in
 *   doc-blocks; neither is read by any `getenv()`, both reach a config file
 *   only through the `${…}` placeholder mechanism, and the page says exactly
 *   that in prose rather than tabulating them. Comparing against the page's
 *   TABLES rather than its prose is what keeps those two out of both sets.
 * - The deprecated `SUGAR_CRUSH_*` spelling is inside the scanner's alphabet on
 *   purpose. A pattern matching only the canonical prefix would have reported a
 *   clean roster while `SUGAR_CRUSH_WORKTREES_DIR` and
 *   `SUGAR_CRUSH_SHARE_UPLOAD_URL` went untabulated — a census cannot find what
 *   its alphabet cannot spell.
 *
 * WHAT THE ORACLE FOUND THE FIRST TIME IT RAN, recorded because a generator
 * whose first result is "no defect" is the one a reader most needs convincing
 * about. The code reads TWENTY-ONE variables — nineteen canonical plus the two
 * deprecated `SUGAR_CRUSH_*` aliases — the page tabulates the same twenty-one,
 * and both directions are empty. The page was already right. What changed is
 * that "already right" is now a measurement instead of a belief, and the
 * cheapest way to check the oracle is not vacuous is that it also found two
 * things nobody was looking for: `BackgroundSessionRunner` EXPORTS
 * `SUGARCRUSH_MODEL` with `putenv()` (a write, not a read, and invisible to the
 * read-side scan because the literal carries a trailing `=`), and the read-side
 * alphabet cannot express a name assembled at runtime. Both now have their own
 * census — {@see testEveryVariableTheCodeExportsAlsoHasARowOnTheEnvironmentPage()}
 * and {@see testNoRosterNameIsAssembledFromPiecesTheScannerCannotFollow()} —
 * because a blind spot that is measured empty is worth more than one that is
 * described in prose.
 *
 * MEASURED ON PHP 8.3.6, 2026-08-22. PHP 8.4 was NOT exercised — this box has
 * only 8.3.6 while CI runs both. Nothing here is version-sensitive: it is
 * `token_get_all()` over categories that predate 8.0 and a `preg_match_all()`
 * over markdown. The stamp is provenance.
 *
 * OUT OF SCOPE, DELIBERATELY: a sibling `sugarcraft/*` library reading a
 * `SUGARCRUSH_*` variable would not be seen. Measured at the time of writing —
 * no lib outside `sugar-crush/` mentions the prefix at all — and scanning
 * siblings from here would fail in a split-repo clone, where they are Packagist
 * copies rather than a monorepo path away.
 *
 * @internal
 */
final class EnvRosterDriftTest extends TestCase
{
    private const ENVIRONMENT_DOC = __DIR__ . '/../../docs/ENVIRONMENT.md';

    // ── the harness, checked against answers already known (first) ───────

    /**
     * Each shape the scanner claims to understand, as a source it must resolve.
     *
     * RUN BEFORE THE REAL SCAN, deliberately. A harness built to check a claim
     * can carry the defect the claim is about: the earlier hand-written version
     * of this scan missed `\getenv(` because it compared the token text to
     * `'getenv'` and PHP tokenises the leading backslash into the same token,
     * and it missed `TerminalBackground::ENV_OVERRIDE` because it only looked
     * for constants used as a direct `getenv()` argument. Both were invisible
     * until fixtures with known answers were run through it.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function shapesTheScannerUnderstands(): iterable
    {
        yield 'S1 — a bare getenv() argument' => [
            '<?php class A { function f() { return getenv("SUGARCRUSH_S1"); } }',
            'SUGARCRUSH_S1',
            'S1-direct',
        ];

        yield 'S1 — a root-namespaced \\getenv() argument' => [
            '<?php class A { function f() { return \\getenv("SUGARCRUSH_S1B"); } }',
            'SUGARCRUSH_S1B',
            'S1-direct',
        ];

        yield 'S3 — forwarded through a one-parameter reader' => [
            '<?php class A {
                function f() { return self::flag("SUGARCRUSH_S3"); }
                private static function flag(string $name): bool { return getenv($name) !== false; }
            }',
            'SUGARCRUSH_S3',
            'S3-forward:flag#0',
        ];

        yield 'S3 — forwarded as the SECOND argument, not the first' => [
            '<?php class A {
                function f() { return self::pick("k", "SUGARCRUSH_S3B"); }
                private static function pick(string $key, string $var): ?string { return getenv($var) ?: null; }
            }',
            'SUGARCRUSH_S3B',
            'S3-forward:pick#1',
        ];

        yield 'S4 — an element of a foreach array literal' => [
            '<?php class A {
                function f() { foreach (["SUGARCRUSH_S4", "LEGACY"] as $n) { if (getenv($n)) { return $n; } } return null; }
            }',
            'SUGARCRUSH_S4',
            'S4-foreach:$n',
        ];

        yield 'S4 — a foreach whose loop variable is forwarded one more hop' => [
            '<?php class A {
                function f() { foreach (["SUGARCRUSH_S4B"] as $v) { if (self::read($v) !== null) { return true; } } return false; }
                private static function read(string $var): ?string { return getenv($var) ?: null; }
            }',
            'SUGARCRUSH_S4B',
            'S4-foreach:$v',
        ];

        yield 'S2 — a class constant whose USE is an S1 argument' => [
            '<?php class A {
                private const E = "SUGARCRUSH_S2";
                function f() { return getenv(self::E); }
            }',
            'SUGARCRUSH_S2',
            'S1-direct',
        ];

        yield 'S2 — a class constant whose only use is inside an S4 array' => [
            '<?php class A {
                public const E = "SUGARCRUSH_S2B";
                function f() { foreach ([self::E, "COLORFGBG"] as $k) { if (getenv($k)) { return $k; } } return null; }
            }',
            'SUGARCRUSH_S2B',
            'S4-foreach:$k',
        ];

        yield 'S1 — a NOWDOC literal argument' => [
            "<?php class A { function f() { return getenv(<<<'TXT'\nSUGARCRUSH_NOWDOC\nTXT); } }",
            'SUGARCRUSH_NOWDOC',
            'S1-direct',
        ];

        yield 'S1 — a HEREDOC literal argument' => [
            "<?php class A { function f() { return getenv(<<<TXT\nSUGARCRUSH_HEREDOC\nTXT); } }",
            'SUGARCRUSH_HEREDOC',
            'S1-direct',
        ];

        yield 'S1 — an INDENTED heredoc, whose closing marker sets the margin' => [
            "<?php class A { function f() { return getenv(<<<TXT\n        SUGARCRUSH_INDENTED\n        TXT); } }",
            'SUGARCRUSH_INDENTED',
            'S1-direct',
        ];

        yield 'S3 — a heredoc literal forwarded through a reader' => [
            "<?php class A {\n"
                . "    function f() { return self::flag(<<<TXT\nSUGARCRUSH_HEREDOC_FWD\nTXT); }\n"
                . "    private static function flag(string \$name): bool { return getenv(\$name) !== false; }\n"
                . '}',
            'SUGARCRUSH_HEREDOC_FWD',
            'S3-forward:flag#0',
        ];

        yield 'the deprecated SUGAR_CRUSH_ spelling is inside the alphabet' => [
            '<?php class A { function f() { return getenv("SUGAR_CRUSH_LEGACY"); } }',
            'SUGAR_CRUSH_LEGACY',
            'S1-direct',
        ];
    }

    /** @dataProvider shapesTheScannerUnderstands */
    public function testTheScannerResolvesEachShapeItClaimsToUnderstand(
        string $source,
        string $expectedName,
        string $expectedShape,
    ): void {
        $scanner = new EnvReadScanner(['fixture.php' => $source]);

        $this->assertSame([], $scanner->unresolved(), 'the fixture left an occurrence unplaced');
        $this->assertArrayHasKey($expectedName, $scanner->reads(), 'the scanner did not see this read at all');
        $this->assertStringContainsString(
            '[' . $expectedShape . ']',
            implode(' ', $scanner->reads()[$expectedName]),
            'the scanner resolved the read through a different shape than the one this fixture is for',
        );
    }

    /**
     * Prose is not a read — the single thing `grep` cannot get right.
     *
     * All three of these would be hits for
     * `grep -o 'SUGARCRUSH_[A-Z_]*'`, and none of them is a variable anyone
     * reads. The scanner sees no token for the first two at all (comments are
     * their own token category and are dropped before matching) and cannot
     * place the third, whose name is read nowhere.
     */
    public function testAPrefixedNameThatIsOnlyProseIsNotCountedAsARead(): void
    {
        $scanner = new EnvReadScanner(['fixture.php' => <<<'PHP'
            <?php
            /** Explains SUGARCRUSH_DOCBLOCK_ONLY, which nothing reads. */
            class A {
                // SUGARCRUSH_LINE_COMMENT_ONLY is likewise only mentioned.
                function f(): void {}
            }
            PHP]);

        $this->assertSame([], $scanner->reads(), 'a name mentioned only in a comment was counted as a read');
        $this->assertSame([], $scanner->unresolved(), 'a name mentioned only in a comment was reported as a problem');
    }

    /**
     * AN OCCURRENCE THE SCANNER CANNOT PLACE MUST GO RED, NOT VANISH.
     *
     * `$_ENV[…]` is deliberately NOT one of the four understood shapes: no
     * production path uses it today, and a scanner that quietly ignored it
     * would have a hole shaped exactly like the day someone does. This fixture
     * is that day, and it must fail.
     */
    public function testAnOccurrenceMatchingNoUnderstoodShapeIsReportedRatherThanDropped(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { return $_ENV["SUGARCRUSH_FIFTH_SHAPE"] ?? null; } }',
        ]);

        $this->assertSame([], $scanner->reads());
        $this->assertCount(1, $scanner->unresolved());
        $this->assertStringContainsString('SUGARCRUSH_FIFTH_SHAPE', $scanner->unresolved()[0]);
    }

    /**
     * A prefixed constant that never reaches `getenv()` is reported too.
     *
     * The declaration alone proves nothing — `TerminalBackground::ENV_OVERRIDE`
     * is declared far from the `foreach` that reads it, and a scanner that took
     * the declaration as evidence would have counted a constant nobody passes
     * anywhere. This is the inverse fixture: the constant exists, is used, and
     * still never reaches the environment.
     */
    public function testADeclaredConstantThatNeverReachesGetenvIsReported(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { private const E = "SUGARCRUSH_DEAD_CONST"; function f() { return self::E; } }',
        ]);

        $this->assertSame([], $scanner->reads());

        // TWO complaints and not one, which is the right answer: the constant
        // is dead, AND its one use is an occurrence the scanner could not place.
        // Either alone would be enough to red; reporting both is what tells the
        // next reader whether to teach the scanner a shape or delete a constant.
        $this->assertSame(
            [
                'const E = SUGARCRUSH_DEAD_CONST — declared, but no occurrence reaches getenv()',
                'fixture.php:1: SUGARCRUSH_DEAD_CONST — occurrence matches no understood shape',
            ],
            $scanner->unresolved(),
        );
    }

    /**
     * A name EXPORTED with `putenv()` is seen, and it is not counted as a read.
     *
     * The trailing `=` is why this needs its own pass: `'SUGARCRUSH_X='` fails
     * {@see EnvReadScanner::NAME_PATTERN}, so the read-side scan cannot see it
     * at all — an export of an undocumented variable would have been invisible
     * to every assertion in this file.
     */
    public function testANameExportedWithPutenvIsSeenButIsNotARead(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f(): void { putenv("SUGARCRUSH_EXPORTED=" . $this->v); } }',
        ]);

        $this->assertSame([], $scanner->reads(), 'a write was counted as a read');
        $this->assertSame([], $scanner->fragments(), 'the export was mistaken for an assembled fragment');
        $this->assertSame(['SUGARCRUSH_EXPORTED'], array_keys($scanner->exported()));
    }

    /**
     * A name ASSEMBLED at runtime is collected as a fragment, not silently lost.
     *
     * This is the scanner's one genuine blind spot — `getenv('SUGARCRUSH_' .
     * $suffix)` is a read no pattern here can name — and the point of
     * collecting the fragment is that the blind spot gets MEASURED empty by
     * {@see testNoRosterNameIsAssembledFromPiecesTheScannerCannotFollow()}
     * rather than asserted empty in prose.
     */
    public function testANameAssembledFromPiecesIsCollectedRatherThanLost(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { return getenv("SUGARCRUSH_" . $this->suffix); } }',
        ]);

        $this->assertSame([], $scanner->reads());
        $this->assertSame(['fixture.php:1: SUGARCRUSH_'], $scanner->fragments());
    }

    /**
     * A name INTERPOLATED into a string is a fragment, not a read of its
     * literal half.
     *
     * THREE SHAPES SURVIVED THE FIRST CUT OF THIS SCANNER, all found by a
     * reviewer injecting them into `src/Tui/TerminalBackground.php` and running
     * this file: a heredoc literal, a nowdoc literal and an interpolated
     * `"…{$x}"`. None produced a read, a fragment or an `unresolved()` entry.
     * The heredoc and nowdoc were the worse pair, because they are PLAIN
     * LITERALS of a whole roster name — exactly what {@see EnvReadScanner}'s
     * doc-block calls the one shape it definitely resolves — and they are fixed
     * by collapsing the token span rather than by exempting anything.
     *
     * THE INTERPOLATED CASE IS THE SUBTLE ONE. `"SUGARCRUSH_INTERP_{$fd}"` has
     * a literal part, `SUGARCRUSH_INTERP_`, that matches
     * {@see EnvReadScanner::NAME_PATTERN} on its own — unlike
     * `'SUGARCRUSH_' . $suffix`, whose piece does not. Treating a part of an
     * assembled string as a name would therefore have produced a CONFIDENT
     * WRONG ANSWER (a read of a variable nobody reads) rather than a reported
     * gap, which is why parts are routed to {@see EnvReadScanner::fragments()}
     * whatever they look like.
     */
    public function testANameInterpolatedIntoAStringIsAFragmentNotARead(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { return getenv("SUGARCRUSH_INTERP_{$this->fd}"); } }',
        ]);

        $this->assertSame([], $scanner->reads(), 'half an assembled name was recorded as a read');
        $this->assertSame(['fixture.php:1: SUGARCRUSH_INTERP_'], $scanner->fragments());
    }

    /** An interpolated HEREDOC is the same case with a different delimiter. */
    public function testANameInterpolatedIntoAHeredocIsAlsoAFragment(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => "<?php class A { function f() { return getenv(<<<TXT\nSUGARCRUSH_HD_{\$this->fd}\nTXT); } }",
        ]);

        $this->assertSame([], $scanner->reads(), 'half an assembled name was recorded as a read');
        $this->assertSame(['fixture.php:1: SUGARCRUSH_HD_'], $scanner->fragments());
    }

    /**
     * A whole name GLUED to a concatenation is a piece too.
     *
     * `getenv('SUGARCRUSH_MAX' . $suffix)` reads neither `SUGARCRUSH_MAX` nor
     * anything nameable, and the literal is a perfectly well-formed roster name
     * on its own — so the shape that catches `'SUGARCRUSH_' . $x` (a piece that
     * fails the pattern) does not catch this one. MEASURED at round 44: no such
     * literal exists in `src/` or `bin/`, so the rule reports nothing today.
     */
    public function testAWholeNameGluedToAConcatenationIsAFragmentNotARead(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { return getenv("SUGARCRUSH_MAX" . $this->suffix); } }',
        ]);

        $this->assertSame([], $scanner->reads(), 'a concatenated piece was recorded as a whole name');
        $this->assertSame(['fixture.php:1: SUGARCRUSH_MAX (concatenated)'], $scanner->fragments());
    }

    /**
     * And an error message that MENTIONS a variable is neither.
     *
     * THE CONTROL FOR THE RULE ABOVE, and it is not hypothetical: `Bootstrap`
     * throws `"\$SUGARCRUSH_MAX_COST is '{$trimmed}', which is not a spend
     * ceiling"`. The first cut of the interpolated-parts rule used
     * `str_contains($part, 'SUGAR')` and reported that message as a
     * half-assembled name on its very first run over `src/`. The rule is
     * `str_starts_with`, the same test the whole-literal path applies.
     */
    public function testAnInterpolatedErrorMessageMentioningAVariableIsNotAFragment(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { throw new \\RuntimeException("\\$SUGARCRUSH_MAX_COST is \'{$this->v}\'"); } }',
        ]);

        $this->assertSame([], $scanner->reads());
        $this->assertSame([], $scanner->fragments(), 'prose naming a variable was reported as an assembled name');
        $this->assertSame([], $scanner->unresolved());
    }

    // ── the real scan ────────────────────────────────────────────────────

    private function scanner(): EnvReadScanner
    {
        return new EnvReadScanner($this->sources());
    }

    /**
     * Everything the roster scan reads: every `.php` file under `src/`, and
     * every executable under `bin/`.
     *
     * `bin/` IS WALKED, not named. It held one hard-coded filename —
     * `bin/sugarcrush` — which is the same shape of assumption as a hand-kept
     * list of stale sites: correct today, silent the day a second entry point
     * lands. The files there carry no extension (they are `#!/usr/bin/env php`
     * scripts), so the filter is "a file", not "a `.php` file".
     *
     * @return array<string, string> repo-relative label => source text
     */
    private function sources(): array
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertIsString($root);

        $sources = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src')) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $sources[substr($file->getPathname(), \strlen($root) + 1)] = self::read($file->getPathname());
        }
        foreach (new \DirectoryIterator($root . '/bin') as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }
            $sources[substr($file->getPathname(), \strlen($root) + 1)] = self::read($file->getPathname());
        }
        ksort($sources);

        return $sources;
    }

    /**
     * The scan really reads both halves of what it claims to read.
     *
     * NOTHING PINNED THIS, and the gap was measurable: deleting `bin/` from the
     * scan left the suite at `OK (19 tests, 1499 assertions)`, five assertions
     * short and entirely green. `bin/sugarcrush` contributes no read today —
     * its one prefixed occurrence is inside a `//` comment, which the token
     * scan drops before matching — so no roster assertion can notice its
     * absence. That is exactly why the SCOPE has to be asserted separately from
     * the roster: a half that currently contributes nothing is the half whose
     * removal is invisible, and the first `getenv()` added to an entry point
     * would then go undocumented in silence.
     *
     * The floor is a floor for {@see GlobFigureDriftTest::testTheCensusReadsBothHalvesOfItsScope()}'s
     * reason: 288 files under `src/` and one under `bin/` at round 44.
     */
    public function testTheScanReadsEverySourceItClaimsTo(): void
    {
        $sources = $this->sources();

        $this->assertArrayHasKey(
            'bin/sugarcrush',
            $sources,
            'the entry point is no longer scanned, and no roster assertion can see that it is missing',
        );
        $this->assertArrayHasKey(
            'src/Tui/TerminalBackground.php',
            $sources,
            'the src/ half of the scan scope has collapsed',
        );

        $bin = array_filter($sources, static fn (string $k): bool => str_starts_with($k, 'bin/'), \ARRAY_FILTER_USE_KEY);

        $this->assertGreaterThanOrEqual(200, \count($sources) - \count($bin), 'the src/ half has collapsed');
        $this->assertGreaterThanOrEqual(1, \count($bin), 'the bin/ half has collapsed');
        foreach ($sources as $label => $text) {
            $this->assertNotSame('', $text, $label . ' was read as empty text');
        }
    }

    private static function read(string $path): string
    {
        $text = file_get_contents($path);
        self::assertIsString($text, $path . ' is unreadable, so any roster derived from it is void');

        return $text;
    }

    /**
     * Nothing in `src/` or `bin/` is left unplaced.
     *
     * This is the assertion that keeps the two set comparisons below honest. A
     * scanner allowed to skip what it does not understand would report an
     * agreeing roster in a tree that had grown a fifth way to read the
     * environment.
     */
    public function testTheRealScanPlacesEveryPrefixedOccurrenceItFinds(): void
    {
        $this->assertSame(
            [],
            $this->scanner()->unresolved(),
            'a SUGARCRUSH_* occurrence matched none of the shapes EnvReadScanner understands; '
            . 'teach it the shape rather than widening the exclusion',
        );
    }

    /**
     * The names the page tabulates, from its markdown tables only.
     *
     * TABLES AND NOT THE WHOLE PAGE. `SUGARCRUSH_TOOL_CALL_PARSER` is discussed
     * at length in prose precisely BECAUSE it is not read by a `getenv()` — it
     * exists only as a `${…}` placeholder a user may write into a config file —
     * so a page-wide scrape would put it in the documented set and then red
     * against a code set that correctly excludes it.
     *
     * FIRST COLUMN ONLY, and the narrowness is the safe direction rather than
     * an oversight. `/^\|\s*`NAME`\s*\|/m` sees a name only where the table
     * puts the variable it is a row ABOUT; a name mentioned in a description
     * cell is invisible to both set comparisons. That cannot hide a
     * read-but-undocumented variable — the code set is built from `src/`, so
     * such a name still reds — it can only fail to notice a name the page
     * mentions in passing and nothing reads. Widening the scrape would move
     * that case from "unnoticed" to "documented", which is strictly weaker:
     * every prose mention in a cell would start counting as a promise the code
     * has to keep.
     *
     * ROUND 45 ADDED A SECOND ORACLE OVER THE OTHER PAGES, AND IT USES THE
     * OPPOSITE SCRAPE. That is not the two rules disagreeing, and the pair has
     * to be read together or the next reader will "fix" one of them.
     * {@see mentionedNames()} scrapes a page WHOLE — prose, table cells and
     * fenced code alike. The difference is a difference between the pages, not
     * a difference of opinion about markdown:
     *
     * - THIS page is the ROSTER. Its prose is where a variable that is NOT read
     *   gets explained, which is a thing only the roster page does, and it does
     *   it for `SUGARCRUSH_TOOL_CALL_PARSER` and `SUGARCRUSH_REASONING_EFFORT`
     *   today. A page-wide scrape here would turn those two sentences into
     *   promises and red on prose that exists to record that the code makes no
     *   promise. So: first column only.
     * - EVERY OTHER page MENTIONS. It has no roster to keep and no not-read
     *   section; a `SUGARCRUSH_*` name printed on it is being handed to a
     *   reader to set. So: whole page — and the roster page is EXCLUDED from
     *   that scrape rather than exempted inside it, which is what keeps the two
     *   not-read sentences out of the widened rule without an exemption list.
     *
     * THE EXCLUSION IS LOAD-BEARING, AND THAT IS MEASURED RATHER THAN ARGUED.
     * Deleting the `continue` that skips the roster page in
     * {@see mentionedNames()} reds three tests on PHP 8.3.6 at round 45, and
     * two of them red on exactly the prose this paragraph is about: with the
     * page scraped whole, `SUGARCRUSH_TOOL_CALL_PARSER` and
     * `SUGARCRUSH_REASONING_EFFORT` become mentions, and neither is read nor
     * tabulated. The naive widening really does red on the two sentences that
     * exist to record that nothing reads them.
     *
     * @return list<string>
     */
    private function tabulatedNames(): array
    {
        $page = self::read(self::ENVIRONMENT_DOC);
        $matched = preg_match_all('/^\|\s*`(SUGAR_?CRUSH_[A-Z0-9_]+)`\s*\|/m', $page, $matches);
        $this->assertIsInt($matched, 'the table scrape failed to run, so the documented set is unknown');
        $this->assertGreaterThan(
            10,
            $matched,
            'the table scrape found almost nothing — the page\'s table syntax changed and this '
            . 'oracle is now comparing against an empty set, which passes for the wrong reason',
        );

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    public function testEveryVariableTheCodeReadsHasARowOnTheEnvironmentPage(): void
    {
        $read = array_keys($this->scanner()->reads());
        sort($read);

        $this->assertSame(
            [],
            array_values(array_diff($read, $this->tabulatedNames())),
            'src/ reads an environment variable docs/ENVIRONMENT.md does not tabulate, which is '
            . 'the exact defect SUGARCRUSH_DEBUG_SKILLS was — the page claims to list every one',
        );
    }

    /**
     * MEASURED, not assumed: nothing in `src/` builds a roster name at runtime.
     *
     * A census cannot find what its alphabet cannot spell, and this is the one
     * shape this alphabet cannot spell. Measured empty on PHP 8.3.6,
     * 2026-08-22 — the only `SUGAR`-prefixed literal in `src/` that is not a
     * whole roster name is `BackgroundSessionRunner`'s `'SUGARCRUSH_MODEL='`,
     * which is an export and is checked separately below. The day someone
     * writes `getenv('SUGARCRUSH_' . $x)` this reds, and the right answer then
     * is to teach the scanner that shape rather than to widen this exclusion.
     */
    public function testNoRosterNameIsAssembledFromPiecesTheScannerCannotFollow(): void
    {
        $this->assertSame(
            [],
            $this->scanner()->fragments(),
            'a SUGAR-prefixed string literal is not a whole roster name — if it is half of one, '
            . 'the read that assembles it is invisible to every assertion in this file',
        );
    }

    /**
     * A variable this code EXPORTS is held to the page too.
     *
     * `BackgroundSessionRunner::backend()` does `putenv('SUGARCRUSH_MODEL=' .
     * $model)` so the daemon it spawns inherits the model choice. That is a
     * legitimate write of a documented variable — but an export of an
     * UNDOCUMENTED one would be a variable a user could observe in a child
     * process and find nowhere on the page, so it is asserted in the same
     * direction as the reads.
     */
    public function testEveryVariableTheCodeExportsAlsoHasARowOnTheEnvironmentPage(): void
    {
        $exported = array_keys($this->scanner()->exported());
        sort($exported);

        $this->assertNotSame([], $exported, 'the export census found nothing at all, so it is not running');
        $this->assertSame(
            [],
            array_values(array_diff($exported, $this->tabulatedNames())),
            'src/ exports an environment variable docs/ENVIRONMENT.md does not tabulate',
        );
    }

    public function testEveryVariableTheEnvironmentPageTabulatesIsOneTheCodeReads(): void
    {
        $read = array_keys($this->scanner()->reads());
        sort($read);

        $this->assertSame(
            [],
            array_values(array_diff($this->tabulatedNames(), $read)),
            'docs/ENVIRONMENT.md tabulates an environment variable nothing in src/ reads — a user '
            . 'who sets it gets silence, which is worse than an undocumented variable',
        );
    }

    // ── the other surfaces (E123) ────────────────────────────────────────

    /**
     * THE ROSTER PAGE IS NOT THE ONLY PAGE THAT NAMES THESE VARIABLES, AND
     * UNTIL ROUND 45 IT WAS THE ONLY ONE UNDER ANY ORACLE.
     *
     * Provenance, re-derived rather than quoted, because a count written into
     * prose is invalidated by the next page anyone adds: at round 45 on PHP
     * 8.3.6, `README.md` named ten of these variables and the eleven non-roster
     * `docs/*.md` pages named fourteen between them, and not one of those
     * appearances was compared against anything. Nothing asserts those figures.
     *
     * THE RULE, AND WHY IT IS NOT SYMMETRIC. The surfaces make different
     * promises, so they get different oracles:
     *
     * - `docs/ENVIRONMENT.md` is the ROSTER. It says "every environment
     *   variable SugarCrush reads, in one place", so BOTH directions are
     *   asserted against it — {@see testEveryVariableTheCodeReadsHasARowOnTheEnvironmentPage()}
     *   and {@see testEveryVariableTheEnvironmentPageTabulatesIsOneTheCodeReads()}.
     * - Every OTHER page MENTIONS. It promises nothing about completeness — a
     *   troubleshooting page naming three variables is not claiming there are
     *   only three — so the "no page mentions it" direction is NOT asserted
     *   here, and must not be. Several of the variables the code reads are
     *   named nowhere but the roster page, and that is correct.
     *
     * ONE DIRECTION, THEN: a name a mention surface prints must be one the code
     * actually reads or exports. That is the promise a mention makes, and it is
     * the one a rename breaks — a troubleshooting page telling a reader to
     * export a variable `src/` stopped reading two rounds ago wastes their
     * afternoon and cannot be distinguished from a bug in the code.
     *
     * THE SCRAPE IS PAGE-WIDE, unlike {@see tabulatedNames()}'s first-column
     * rule; the reconciliation between the two is written out there. Short
     * form: a fenced `export SUGARCRUSH_PROVIDER=…` in `README.md` is exactly
     * as much of an instruction as a table row, and the roster page — the one
     * page whose prose deliberately discusses variables nothing reads — is
     * excluded from this scrape rather than exempted inside it.
     *
     * @return array<string, list<string>> variable => the pages that name it
     */
    private function mentionedNames(): array
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertIsString($root);

        $roster = realpath(self::ENVIRONMENT_DOC);
        self::assertIsString($roster, 'docs/ENVIRONMENT.md is gone, so the exclusion below excludes nothing');

        $surfaces = array_merge([$root . '/README.md'], glob($root . '/docs/*.md') ?: []);
        self::assertGreaterThan(
            1,
            \count($surfaces),
            'the mention surfaces collapsed to one file or none, so an empty result means nothing',
        );

        $found = [];
        foreach ($surfaces as $path) {
            if (realpath($path) === $roster) {
                continue;
            }
            $label = substr($path, \strlen($root) + 1);
            foreach ($this->prefixedNamesIn(self::read($path)) as $name) {
                $found[$name][] = $label;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Every `SUGARCRUSH_*` / `SUGAR_CRUSH_*` name in a blob of text.
     *
     * The same alphabet {@see tabulatedNames()} uses, deprecated spelling
     * included for the reason this class's doc-block records: a census cannot
     * find what its alphabet cannot spell, and the deprecated pair is exactly
     * what a canonical-prefix-only pattern misses in silence.
     *
     * @return list<string>
     */
    private function prefixedNamesIn(string $text): array
    {
        $matched = preg_match_all('/\b(SUGAR_?CRUSH_[A-Z0-9_]+)\b/', $text, $matches);
        $this->assertIsInt($matched, 'the mention scrape failed to run, so its answer means nothing');

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * A name printed on a mention surface is one the code reads.
     *
     * THE SCRAPER IS RUN OVER A KNOWN POSITIVE IN THIS SAME TEST. An assertion
     * that a diff is empty passes just as well in a tree where the scraper has
     * silently stopped matching — round 44 measured that exactly: a census
     * mutated to never match reported "nothing is stale" across eighteen
     * thousand green assertions. The fixture is what makes the empty result
     * below evidence rather than decoration.
     */
    public function testEveryVariableAnotherPageNamesIsOneTheCodeReads(): void
    {
        $this->assertSame(
            ['SUGARCRUSH_FIXTURE_ONE', 'SUGAR_CRUSH_FIXTURE_TWO'],
            $this->prefixedNamesIn('set `SUGARCRUSH_FIXTURE_ONE`, or the old `SUGAR_CRUSH_FIXTURE_TWO`'),
            'the mention scraper cannot find the variable names in a fixture that has two, '
            . 'so the census below is not evidence of anything',
        );

        $known = array_merge(array_keys($this->scanner()->reads()), array_keys($this->scanner()->exported()));

        $unkept = [];
        foreach ($this->mentionedNames() as $name => $pages) {
            if (!\in_array($name, $known, true)) {
                $unkept[$name] = $pages;
            }
        }

        $this->assertSame(
            [],
            $unkept,
            'a page names an environment variable nothing in src/ reads or exports — a promise the '
            . 'code does not keep, and the reader who sets it gets silence. Either wire it, or move '
            . 'the discussion to docs/ENVIRONMENT.md, the one page whose prose is allowed to name a '
            . 'variable the code ignores',
        );
    }

    /**
     * A name printed on a mention surface also has a row on the roster.
     *
     * SEPARATE FROM THE TEST ABOVE because it is a different defect with a
     * different fix. Above: the code does not read it, so the PAGE is wrong.
     * Here: the code reads it and the roster skipped it, so the ROSTER is wrong
     * — and the reader who meets the variable on a troubleshooting page and
     * goes looking for what it does finds nothing. This is
     * `SUGARCRUSH_DEBUG_SKILLS`' defect from the other side: that one was
     * read-but-untabulated, and `docs/SKILLS.md` named it while the roster did
     * not, which is the pair of facts this assertion would have caught.
     *
     * It cannot red today without the roster's own oracle redding too — every
     * mentioned name is read, and every read name is tabulated. That is a fact
     * about this tree and not a property of the rule: the two sets are built
     * from different files and drift apart independently.
     */
    public function testEveryVariableAnotherPageNamesHasARowOnTheRoster(): void
    {
        $tabulated = $this->tabulatedNames();

        $missing = [];
        foreach ($this->mentionedNames() as $name => $pages) {
            if (!\in_array($name, $tabulated, true)) {
                $missing[$name] = $pages;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'a page names an environment variable docs/ENVIRONMENT.md does not tabulate — the page '
            . 'that claims to list every one is missing a row another page already relies on',
        );
    }

    /**
     * THE MENTION SURFACES ARE REALLY BEING READ.
     *
     * A guard has to live outside the thing it guards. If the glob in
     * {@see mentionedNames()} stopped matching, or the roster exclusion started
     * excluding everything, both censuses above would report `[]` and pass. The
     * floors are floors rather than counts for
     * {@see GlobFigureDriftTest::testTheCensusReadsBothHalvesOfItsScope()}'s
     * reason: a count reds on every page anyone adds, a floor reds when a half
     * collapses, and only the second is a defect.
     */
    public function testTheMentionCensusActuallyReadsTheOtherPages(): void
    {
        $mentioned = $this->mentionedNames();

        $this->assertGreaterThan(
            10,
            \count($mentioned),
            'the mention census found almost no variables across README.md and docs/ — it is '
            . 'comparing against an empty set, which passes for the wrong reason',
        );

        $pages = [];
        foreach ($mentioned as $where) {
            foreach ($where as $page) {
                $pages[$page] = true;
            }
        }

        $this->assertArrayNotHasKey(
            'docs/ENVIRONMENT.md',
            $pages,
            'the roster page leaked into the mention census, whose whole-page scrape would then turn '
            . 'its deliberate not-read prose into promises — see tabulatedNames()',
        );
        $this->assertArrayHasKey('README.md', $pages, 'README.md is not being scraped');
        $this->assertGreaterThan(
            3,
            \count($pages),
            'the mention census is reading barely any pages, so its empty diffs say almost nothing',
        );
    }
}
