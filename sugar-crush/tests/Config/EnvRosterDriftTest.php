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
 * it understands and for its refusal to drop what it cannot place. Two things
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

    // ── the real scan ────────────────────────────────────────────────────

    private function scanner(): EnvReadScanner
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
        $sources['bin/sugarcrush'] = self::read($root . '/bin/sugarcrush');
        ksort($sources);

        return new EnvReadScanner($sources);
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
}
