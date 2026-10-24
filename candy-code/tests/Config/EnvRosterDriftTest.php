<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Config\Support\DocumentParagraphs;
use SugarCraft\Code\Tests\Config\Support\EnvReadScanner;
use SugarCraft\Core\Util\ColorProfile;

/**
 * `docs/ENVIRONMENT.md` OPENS WITH "EVERY ENVIRONMENT VARIABLE CANDY_CODE
 * READS, IN ONE PLACE" — AND UNTIL NOW NOTHING CHECKED IT.
 *
 * That page is one of five claim families the round-43 audit recorded as
 * asserted-but-ungenerated: the `--flag` list, this env roster, the exit-code
 * table, the twelve built-in skills and the six agent presets. It is the roster
 * because it is the one already enumerated in `src/` — the variables are string
 * literals in the source, so the page has an oracle available and simply was
 * not wired to one. The page's own last table row records what that costs:
 * `CANDY_CODE_DEBUG_SKILLS` was "the one variable `src/` reads that this page
 * did not list, which made the page's own 'every environment variable' claim
 * false", and it was found by hand.
 *
 * BOTH DIRECTIONS ARE ASSERTED, because they are different defects.
 * Read-but-undocumented is a variable a user cannot discover; documented-but-
 * unread is a variable a user sets and nothing happens. The first is what
 * `CANDY_CODE_DEBUG_SKILLS` was; the second is what a rename leaves behind.
 *
 * THE ORACLE IS A TOKEN SCAN, NOT A `grep`, and that distinction is the whole
 * reason it is trustworthy — see {@see EnvReadScanner} for the five call shapes
 * it understands, the four string shapes it reads them in, and for its refusal
 * to drop what it cannot place (a refusal that was itself false for three
 * string shapes until round 44, and is now pinned by fixtures for each). Two things
 * follow that are worth stating here rather than leaving to the reader:
 *
 * - A prefixed name that appears ONLY inside a doc-comment or an error message
 *   is not a read. `src/Providers/ProviderFactory.php` mentions
 *   `CANDY_CODE_REASONING_EFFORT` and `CANDY_CODE_TOOL_CALL_PARSER` in
 *   doc-blocks; neither is read by any `getenv()`, and both reach a config file
 *   only through the `${…}` placeholder mechanism. Comparing against the page's
 *   TABLES rather than its prose is what keeps those two out of both sets.
 *   THE LAST CLAUSE OF THAT SENTENCE USED TO COVER BOTH NAMES AND DOES NOT.
 *   WHAT IT SAID: "and the page says exactly that in prose rather than
 *   tabulating them". WHAT IS TRUE, measured on PHP 8.3.6:
 *   `docs/ENVIRONMENT.md` says it for `CANDY_CODE_TOOL_CALL_PARSER` only;
 *   `CANDY_CODE_REASONING_EFFORT` appears nowhere on that page, in a table, in
 *   prose or in a fence. WHY THE POINT SURVIVES: it never depended on the page
 *   saying anything. A doc-comment mention is not a read because the SCANNER
 *   follows call shapes, and the table-only scrape is what keeps a name the
 *   page discusses out of the documented set. This half-sentence was copied
 *   forward into a round-45 claim about the roster page and stamped
 *   "measured"; the correction lives on {@see tabulatedNames()}.
 * - The pre-rename `SUGARCRUSH_*`/`SUGAR_CRUSH_*` spellings are deliberately // @rename-keep
 *   OUTSIDE the scanner's alphabet since the 2026-10 rename: they fold onto
 *   `CANDY_CODE_*` once at startup (LegacyEnv) plus two @rename-keep'd
 *   fallback reads in WorktreeManager/ShareCommand, and the alias table in
 *   docs/ENVIRONMENT.md tabulates those legacy shapes on purpose.
 *
 * WHAT THE ORACLE FOUND THE FIRST TIME IT RAN, recorded because a generator
 * whose first result is "no defect" is the one a reader most needs convincing
 * about. The code reads its canonical `CANDY_CODE_*` set, the page tabulates
 * the same names, and both directions are empty. The page was already right. What changed is
 * that "already right" is now a measurement instead of a belief, and the
 * cheapest way to check the oracle is not vacuous is that it also found two
 * things nobody was looking for: `BackgroundSessionRunner` EXPORTS
 * `CANDY_CODE_MODEL` with `putenv()` (a write, not a read, and invisible to the
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
 * `CANDY_CODE_*` variable would not be seen. Measured at the time of writing —
 * no lib outside `candy-code/` mentions the prefix at all — and scanning
 * siblings from here would fail in a split-repo clone, where they are Packagist
 * copies rather than a monorepo path away.
 *
 * THE CARVE-OUT THIS CLASS CANNOT SEE, AND HOW IT IS CLOSED ANYWAY: the oracle
 * follows READS INSIDE `candy-code/src` only, so an environment variable that
 * `src/` never names is structurally invisible to it — and yet the page opens
 * with "every environment variable". That is exactly the shape of the terminal
 * colour family: `NO_COLOR`, `FORCE_COLOR` and `CLICOLOR_FORCE` are consulted by
 * `sugar-core`'s `ColorProfile::detect()`, which `src/Tui/Renderer.php` hands
 * the whole environment snapshot at launch, so no `getenv()` call anywhere in
 * this package names them. Widening the scanner to sibling sources is NOT the
 * remedy (the split-repo problem above). Instead the colour family lives in a
 * manual roster, `AMBIENT_COLOR_ROSTER`, policed inside
 * {@see testTheOsEnvironmentRosterIsPinnedInBothDirections()} by the same three
 * legs the OS roster uses (pinned⊆documented, documented⊆pinned, and a liveness
 * check that each pinned name is still a quoted env key in the real
 * `ColorProfile::detect()` source, resolved through reflection so it works from
 * a vendor copy as well as from a monorepo path). Plain `CLICOLOR` joins
 * neither the roster nor the table, and the absence is pinned too: the page
 * states it is not consulted, and the colour source is asserted not to read it.
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
            '<?php class A { function f() { return getenv("CANDY_CODE_S1"); } }',
            'CANDY_CODE_S1',
            'S1-direct',
        ];

        yield 'S1 — a root-namespaced \\getenv() argument' => [
            '<?php class A { function f() { return \\getenv("CANDY_CODE_S1B"); } }',
            'CANDY_CODE_S1B',
            'S1-direct',
        ];

        yield 'S3 — forwarded through a one-parameter reader' => [
            '<?php class A {
                function f() { return self::flag("CANDY_CODE_S3"); }
                private static function flag(string $name): bool { return getenv($name) !== false; }
            }',
            'CANDY_CODE_S3',
            'S3-forward:flag#0',
        ];

        yield 'S3 — forwarded as the SECOND argument, not the first' => [
            '<?php class A {
                function f() { return self::pick("k", "CANDY_CODE_S3B"); }
                private static function pick(string $key, string $var): ?string { return getenv($var) ?: null; }
            }',
            'CANDY_CODE_S3B',
            'S3-forward:pick#1',
        ];

        yield 'S4 — an element of a foreach array literal' => [
            '<?php class A {
                function f() { foreach (["CANDY_CODE_S4", "LEGACY"] as $n) { if (getenv($n)) { return $n; } } return null; }
            }',
            'CANDY_CODE_S4',
            'S4-foreach:$n',
        ];

        yield 'S4 — a foreach whose loop variable is forwarded one more hop' => [
            '<?php class A {
                function f() { foreach (["CANDY_CODE_S4B"] as $v) { if (self::read($v) !== null) { return true; } } return false; }
                private static function read(string $var): ?string { return getenv($var) ?: null; }
            }',
            'CANDY_CODE_S4B',
            'S4-foreach:$v',
        ];

        yield 'S2 — a class constant whose USE is an S1 argument' => [
            '<?php class A {
                private const E = "CANDY_CODE_S2";
                function f() { return getenv(self::E); }
            }',
            'CANDY_CODE_S2',
            'S1-direct',
        ];

        yield 'S2 — a class constant whose only use is inside an S4 array' => [
            '<?php class A {
                public const E = "CANDY_CODE_S2B";
                function f() { foreach ([self::E, "COLORFGBG"] as $k) { if (getenv($k)) { return $k; } } return null; }
            }',
            'CANDY_CODE_S2B',
            'S4-foreach:$k',
        ];

        yield 'S1 — a NOWDOC literal argument' => [
            "<?php class A { function f() { return getenv(<<<'TXT'\nCANDY_CODE_NOWDOC\nTXT); } }",
            'CANDY_CODE_NOWDOC',
            'S1-direct',
        ];

        yield 'S1 — a HEREDOC literal argument' => [
            "<?php class A { function f() { return getenv(<<<TXT\nCANDY_CODE_HEREDOC\nTXT); } }",
            'CANDY_CODE_HEREDOC',
            'S1-direct',
        ];

        yield 'S1 — an INDENTED heredoc, whose closing marker sets the margin' => [
            "<?php class A { function f() { return getenv(<<<TXT\n        CANDY_CODE_INDENTED\n        TXT); } }",
            'CANDY_CODE_INDENTED',
            'S1-direct',
        ];

        yield 'S3 — a heredoc literal forwarded through a reader' => [
            "<?php class A {\n"
                . "    function f() { return self::flag(<<<TXT\nCANDY_CODE_HEREDOC_FWD\nTXT); }\n"
                . "    private static function flag(string \$name): bool { return getenv(\$name) !== false; }\n"
                . '}',
            'CANDY_CODE_HEREDOC_FWD',
            'S3-forward:flag#0',
        ];

        yield 'a double-quoted canonical literal is a read' => [
            '<?php class A { function f() { return getenv("CANDY_CODE_DQ"); } }',
            'CANDY_CODE_DQ',
            'S1-direct',
        ];

        yield 'S5 — a fluent withEnvVar() schema marker is placed' => [
            '<?php class D { function f() { return $this->def()->withEnvVar("CANDY_CODE_S5"); }
                private function def() { return new D(); } }',
            'CANDY_CODE_S5',
            'S5-schema:withEnvVar',
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
     * S5 is the `withEnvVar` channel and nothing else.
     *
     * The shape is placed BY CHANNEL, not by any getter-following, so the
     * boundary of that claim must be pinned: a different fluent setter
     * carrying the same string is NOT a schema binding, and a same-named
     * STATIC call is not the instance channel either. Both stay unplaced and
     * are reported — the census may learn a channel, it may not learn to
     * trust a word.
     */
    public function testASetterThatIsNotTheWithEnvVarChannelIsStillUnplaced(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => <<<'PHP'
                <?php
                class A {
                    function f(): void {
                        $this->def()->withLabel('CANDY_CODE_NOT_A_BINDING');
                        self::withEnvVar('CANDY_CODE_STATIC_IMPOSTOR');
                    }
                    private function def(): A { return $this; }
                    private static function withEnvVar(string $x): void {}
                }
                PHP,
        ]);

        $this->assertSame([], $scanner->reads(), 'a non-channel setter was placed as a schema binding');
        $this->assertCount(2, $scanner->unresolved(), 'both impostor shapes must be reported');
        $joined = implode("\n", $scanner->unresolved());
        $this->assertStringContainsString('CANDY_CODE_NOT_A_BINDING', $joined);
        $this->assertStringContainsString('CANDY_CODE_STATIC_IMPOSTOR', $joined);
    }

    /**
     * S3 forwarding resolves through the CALL SITE'S declaring scope, not
     * through file order (E127).
     *
     * THE TRAIT IS DECLARED FIRST, ON PURPOSE. The old whole-file sweep reached
     * the trait's `flag()` — which forwards to `getenv()` — and recorded a
     * read, while PHP would run the CLASS's own `flag()`, which does not read
     * anything. So the honest verdict on this fixture is the opposite of what
     * a passing roster looks like: the literal is NOT a read and it IS
     * reported, in `unresolved()`. A resolution that silently counted it would
     * have been the defect — a name documented because a body that never runs
     * appears earlier in a file.
     *
     * THE SECOND HALF IS THE FALL-THROUGH, and it is asserted in the same
     * method because the fold has to keep working for the shape the rule
     * actually resolves today: when nothing in the enclosing scope declares
     * the name, the same-file trait declaration is as close as a token
     * scanner can get to the declaring scope, and the read must resolve
     * exactly as before. Reverting E127 reddens the first half; breaking the
     * fall-through reddens the second; neither can be satisfied by the other.
     */
    public function testASameNamedTraitAndClassMethodResolveByShadowingNotByFileOrder(): void
    {
        $shadowing = new EnvReadScanner(['shadow.php' => <<<'PHP'
            <?php
            trait Reader {
                private static function flag(string $name): bool { return getenv($name) !== false; }
            }
            class A {
                use Reader;
                function f() { return self::flag("CANDY_CODE_SHADOWED"); }
                private static function flag(string $ignored): bool { return false; }
            }
            PHP]);

        $this->assertSame(
            [],
            $shadowing->reads(),
            'a forwarded literal was resolved through the trait declaration the class shadows — '
            . 'file order is not PHP dispatch order, and this read belongs to a body that never runs',
        );
        $this->assertCount(
            1,
            $shadowing->unresolved(),
            'the shadowed occurrence vanished instead of being reported — an unplaced literal is '
            . 'allowed to disappear only when the same name resolved elsewhere, and nothing did',
        );
        $this->assertStringContainsString('CANDY_CODE_SHADOWED', $shadowing->unresolved()[0]);

        $inheriting = new EnvReadScanner(['inherit.php' => <<<'PHP'
            <?php
            trait Reader {
                private static function flag(string $name): bool { return getenv($name) !== false; }
            }
            class A {
                use Reader;
                function f() { return self::flag("CANDY_CODE_INHERITED"); }
            }
            PHP]);

        $this->assertSame([], $inheriting->unresolved());
        $this->assertArrayHasKey(
            'CANDY_CODE_INHERITED',
            $inheriting->reads(),
            'the same-file trait fall-through stopped resolving — every legitimate S3 read through '
            . 'a trait method now vanishes from the roster',
        );
        $this->assertStringContainsString(
            '[S3-forward:flag#0]',
            implode(' ', $inheriting->reads()['CANDY_CODE_INHERITED']),
        );
    }

    /**
     * Prose is not a read — the single thing `grep` cannot get right.
     *
     * All three of these would be hits for
     * `grep -o 'CANDY_CODE_[A-Z_]*'`, and none of them is a variable anyone
     * reads. The scanner sees no token for the first two at all (comments are
     * their own token category and are dropped before matching) and cannot
     * place the third, whose name is read nowhere.
     */
    public function testAPrefixedNameThatIsOnlyProseIsNotCountedAsARead(): void
    {
        $scanner = new EnvReadScanner(['fixture.php' => <<<'PHP'
            <?php
            /** Explains CANDY_CODE_DOCBLOCK_ONLY, which nothing reads. */
            class A {
                // CANDY_CODE_LINE_COMMENT_ONLY is likewise only mentioned.
                function f(): void {}
            }
            PHP]);

        $this->assertSame([], $scanner->reads(), 'a name mentioned only in a comment was counted as a read');
        $this->assertSame([], $scanner->unresolved(), 'a name mentioned only in a comment was reported as a problem');
    }

    /**
     * AN OCCURRENCE THE SCANNER CANNOT PLACE MUST GO RED, NOT VANISH.
     *
     * `$_ENV[…]` is deliberately NOT one of the five understood shapes: no
     * production path uses it today, and a scanner that quietly ignored it
     * would have a hole shaped exactly like the day someone does. This fixture
     * is that day, and it must fail.
     */
    public function testAnOccurrenceMatchingNoUnderstoodShapeIsReportedRatherThanDropped(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { return $_ENV["CANDY_CODE_UNPLACED_SHAPE"] ?? null; } }',
        ]);

        $this->assertSame([], $scanner->reads());
        $this->assertCount(1, $scanner->unresolved());
        $this->assertStringContainsString('CANDY_CODE_UNPLACED_SHAPE', $scanner->unresolved()[0]);
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
            'fixture.php' => '<?php class A { private const E = "CANDY_CODE_DEAD_CONST"; function f() { return self::E; } }',
        ]);

        $this->assertSame([], $scanner->reads());

        // TWO complaints and not one, which is the right answer: the constant
        // is dead, AND its one use is an occurrence the scanner could not place.
        // Either alone would be enough to red; reporting both is what tells the
        // next reader whether to teach the scanner a shape or delete a constant.
        $this->assertSame(
            [
                'const E = CANDY_CODE_DEAD_CONST — declared, but no occurrence reaches getenv()',
                'fixture.php:1: CANDY_CODE_DEAD_CONST — occurrence matches no understood shape',
            ],
            $scanner->unresolved(),
        );
    }

    /**
     * A name EXPORTED with `putenv()` is seen, and it is not counted as a read.
     *
     * The trailing `=` is why this needs its own pass: `'CANDY_CODE_X='` fails
     * {@see EnvReadScanner::NAME_PATTERN}, so the read-side scan cannot see it
     * at all — an export of an undocumented variable would have been invisible
     * to every assertion in this file.
     */
    public function testANameExportedWithPutenvIsSeenButIsNotARead(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f(): void { putenv("CANDY_CODE_EXPORTED=" . $this->v); } }',
        ]);

        $this->assertSame([], $scanner->reads(), 'a write was counted as a read');
        $this->assertSame([], $scanner->fragments(), 'the export was mistaken for an assembled fragment');
        $this->assertSame(['CANDY_CODE_EXPORTED'], array_keys($scanner->exported()));
    }

    /**
     * A name ASSEMBLED at runtime is collected as a fragment, not silently lost.
     *
     * This is the scanner's one genuine blind spot — `getenv('CANDY_CODE_' .
     * $suffix)` is a read no pattern here can name — and the point of
     * collecting the fragment is that the blind spot gets MEASURED empty by
     * {@see testNoRosterNameIsAssembledFromPiecesTheScannerCannotFollow()}
     * rather than asserted empty in prose.
     */
    public function testANameAssembledFromPiecesIsCollectedRatherThanLost(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { return getenv("CANDY_CODE_" . $this->suffix); } }',
        ]);

        $this->assertSame([], $scanner->reads());
        $this->assertSame(['fixture.php:1: CANDY_CODE_'], $scanner->fragments());
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
     * THE INTERPOLATED CASE IS THE SUBTLE ONE. `"CANDY_CODE_INTERP_{$fd}"` has
     * a literal part, `CANDY_CODE_INTERP_`, that matches
     * {@see EnvReadScanner::NAME_PATTERN} on its own — unlike
     * `'CANDY_CODE_' . $suffix`, whose piece does not. Treating a part of an
     * assembled string as a name would therefore have produced a CONFIDENT
     * WRONG ANSWER (a read of a variable nobody reads) rather than a reported
     * gap, which is why parts are routed to {@see EnvReadScanner::fragments()}
     * whatever they look like.
     */
    public function testANameInterpolatedIntoAStringIsAFragmentNotARead(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { return getenv("CANDY_CODE_INTERP_{$this->fd}"); } }',
        ]);

        $this->assertSame([], $scanner->reads(), 'half an assembled name was recorded as a read');
        $this->assertSame(['fixture.php:1: CANDY_CODE_INTERP_'], $scanner->fragments());
    }

    /** An interpolated HEREDOC is the same case with a different delimiter. */
    public function testANameInterpolatedIntoAHeredocIsAlsoAFragment(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => "<?php class A { function f() { return getenv(<<<TXT\nCANDY_CODE_HD_{\$this->fd}\nTXT); } }",
        ]);

        $this->assertSame([], $scanner->reads(), 'half an assembled name was recorded as a read');
        $this->assertSame(['fixture.php:1: CANDY_CODE_HD_'], $scanner->fragments());
    }

    /**
     * A whole name GLUED to a concatenation is a piece too.
     *
     * `getenv('CANDY_CODE_MAX' . $suffix)` reads neither `CANDY_CODE_MAX` nor
     * anything nameable, and the literal is a perfectly well-formed roster name
     * on its own — so the shape that catches `'CANDY_CODE_' . $x` (a piece that
     * fails the pattern) does not catch this one. MEASURED at round 44: no such
     * literal exists in `src/` or `bin/`, so the rule reports nothing today.
     */
    public function testAWholeNameGluedToAConcatenationIsAFragmentNotARead(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { return getenv("CANDY_CODE_MAX" . $this->suffix); } }',
        ]);

        $this->assertSame([], $scanner->reads(), 'a concatenated piece was recorded as a whole name');
        $this->assertSame(['fixture.php:1: CANDY_CODE_MAX (concatenated)'], $scanner->fragments());
    }

    /**
     * And an error message that MENTIONS a variable is neither.
     *
     * THE CONTROL FOR THE RULE ABOVE, and it is not hypothetical: `Bootstrap`
     * throws `"\$CANDY_CODE_MAX_COST is '{$trimmed}', which is not a spend
     * ceiling"`. The first cut of the interpolated-parts rule used
     * a loose `str_contains` on the brand prefix and reported that message as a
     * half-assembled name on its very first run over `src/`. The rule is
     * `str_starts_with`, the same test the whole-literal path applies.
     */
    public function testAnInterpolatedErrorMessageMentioningAVariableIsNotAFragment(): void
    {
        $scanner = new EnvReadScanner([
            'fixture.php' => '<?php class A { function f() { throw new \\RuntimeException("\\$CANDY_CODE_MAX_COST is \'{$this->v}\'"); } }',
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
     * `bin/candy-code` — which is the same shape of assumption as a hand-kept
     * list of stale sites: correct today, silent the day a second entry point
     * lands. The files there carry no extension (they are `#!/usr/bin/env php`
     * scripts), so the filter is "a file", not "a `.php` file".
     *
     * PUBLIC AND STATIC SINCE ROUND 62 — {@see \SugarCraft\Code\Tests\Cli\HelpTest}
     * builds the same scanner over the same walk for the help-screen roster
     * guard (E37). One walk, one source of truth for what `src/` and `bin/`
     * are; a second copy would be a second census that can drift.
     *
     * @return array<string, string> repo-relative label => source text
     */
    public static function sources(): array
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
     * short and entirely green. `bin/candy-code` contributes no read today —
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
            'bin/candy-code',
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
            'a CANDY_CODE_* occurrence matched none of the shapes EnvReadScanner understands; '
            . 'teach it the shape rather than widening the exclusion',
        );
    }

    /**
     * The names the page tabulates, from its markdown tables only.
     *
     * TABLES AND NOT THE WHOLE PAGE. `CANDY_CODE_TOOL_CALL_PARSER` is discussed
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
     *   it for `CANDY_CODE_TOOL_CALL_PARSER` today — that name alone. A
     *   page-wide scrape here would turn that section, and the `${…}`
     *   placeholder example in the fenced block above it, into promises, and
     *   red on prose that exists to record that the code makes no promise. So:
     *   first column only.
     * - EVERY OTHER page MENTIONS. It has no roster to keep and no not-read
     *   section; a `CANDY_CODE_*` name printed on it is being handed to a
     *   reader to set. So: whole page — and the roster page is EXCLUDED from
     *   that scrape rather than exempted inside it, which is what keeps the two
     *   not-read sentences out of the widened rule without an exemption list.
     *
     * THE EXCLUSION IS LOAD-BEARING, AND THAT IS MEASURED RATHER THAN ARGUED.
     * Deleting the roster `continue` in {@see mentionSurfaces()} reds three
     * tests on PHP 8.3.6, and both of the set comparisons among them red on a
     * ONE-ELEMENT diff: with the page scraped whole,
     * `CANDY_CODE_TOOL_CALL_PARSER` becomes a mention, and it is neither read
     * nor tabulated. The naive widening really does red on the prose that
     * exists to record that nothing reads it.
     *
     * THAT SENTENCE NAMED TWO VARIABLES WHEN IT WAS FIRST WRITTEN, and the
     * correction is kept because the way it went wrong is the point.
     * WHAT IT SAID: the widening turns `CANDY_CODE_TOOL_CALL_PARSER` AND
     * `CANDY_CODE_REASONING_EFFORT` into mentions.
     * WHAT IS TRUE: `CANDY_CODE_REASONING_EFFORT` does not appear on
     * `docs/ENVIRONMENT.md` at all — not in a table, not in prose, not in a
     * fence — so it cannot become a mention when the exclusion is removed. It
     * exists in exactly one place in this package, a doc-block in
     * `src/Providers/ProviderFactory.php`.
     * WHY THE CLAIM SURVIVES IN CORRECTED FORM: the exclusion really is
     * load-bearing and the mutation really does red; only the arithmetic was
     * borrowed. The pairing came from this class's own doc-block, which names
     * the two variables together for a DIFFERENT reason — both are mentioned in
     * `ProviderFactory`'s doc-blocks and neither is read — and the pairing was
     * copied across into a claim about the ROSTER PAGE and then stamped
     * "measured". A sentence inherited from a neighbouring paragraph is not a
     * measurement, however true the paragraph it came from.
     *
     * @return list<string>
     */
    private function tabulatedNames(): array
    {
        $page = self::read(self::ENVIRONMENT_DOC);
        $matched = preg_match_all('/^\|\s*`(CANDY_CODE_[A-Z0-9_]+)`\s*\|/m', $page, $matches);
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
            . 'the exact defect CANDY_CODE_DEBUG_SKILLS was — the page claims to list every one',
        );
    }

    /**
     * MEASURED, not assumed: nothing in `src/` builds a roster name at runtime.
     *
     * A census cannot find what its alphabet cannot spell, and this is the one
     * shape this alphabet cannot spell. Measured empty on PHP 8.3.6,
     * 2026-08-22 (pre-rename alphabet) — the only brand-prefixed literal in `src/` that is not a
     * whole roster name is `BackgroundSessionRunner`'s `'CANDY_CODE_MODEL='`,
     * which is an export and is checked separately below.
     *
     * THE ONE LEGAL EXCEPTION SINCE THE RENAME is the fold map:
     * `LegacyEnv::import()` pairs each deprecated spelling to the truncated new
     * prefix, and that truncated literal is a pairing target, not an assembled
     * read. The scanner recognises the fold region and accounts for its
     * literals separately ({@see EnvReadScanner::foldPrefixes()}). This test
     * pins BOTH polarities — a genuine assembly outside any fold map must still
     * land in `fragments()` so the guard is measurably not weakened — plus the
     * live census of where the exemption fires. The day someone writes
     * `getenv('CANDY_CODE_' . $x)` this reds, and the right answer then is to
     * teach the scanner that shape rather than to widen this exclusion.
     */
    public function testNoRosterNameIsAssembledFromPiecesTheScannerCannotFollow(): void
    {
        $this->assertSame(
            [],
            $this->scanner()->fragments(),
            'a CANDY_CODE-prefixed string literal is not a whole roster name — if it is half of one, '
            . 'the read that assembles it is invisible to every assertion in this file',
        );

        // Polarity 1: a fold map is recognised, and its prefix literals are
        // accounted for rather than silently skipped.
        $fold = new EnvReadScanner([
            'fold.php' => "<?php LegacyEnv::import(['SUGARCRUSH_X' => 'CANDY_CODE_', 'SUGAR_CRUSH_X' => 'CANDY_CODE_']);", // @rename-keep fixture of the deprecated spelling
        ]);
        $this->assertSame([], $fold->fragments(), 'a fold map pairing was accused of assembling a roster name');
        $this->assertCount(2, $fold->foldPrefixes(), 'the scanner did not see both sides of the fold map');

        // Polarity 2: an assembly OUTSIDE any fold map still reaches the guard.
        $assembly = new EnvReadScanner([
            'x.php' => '<?php class A { function f() { return getenv("CANDY_CODE_" . $suffix); } }',
        ]);
        $this->assertCount(1, $assembly->fragments(), 'the fold recognition weakened the assembly guard');
        $this->assertSame([], $assembly->foldPrefixes(), 'a plain concatenation was counted as a fold pairing');

        // Live: the only fold exemptions in src/ today are LegacyMigration's
        // two pairings — an exemption that spreads is a roster lying quietly.
        $files = array_map(
            static fn (string $site): string => substr($site, 0, strrpos($site, ':')),
            $this->scanner()->foldPrefixes(),
        );
        $this->assertSame(
            ['src/Cli/LegacyMigration.php', 'src/Cli/LegacyMigration.php'],
            $files,
            'the fold-map exemption fires somewhere other than the rename shim — every fold map is a '
            . 'deliberate migration pairing and belongs on the environment page, not silently in this census',
        );
    }

    /**
     * A variable this code EXPORTS is held to the page too.
     *
     * `BackgroundSessionRunner::backend()` does `putenv('CANDY_CODE_MODEL=' .
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
     * NO CARDINALITY IS QUOTED HERE, which is discipline rather than vagueness:
     * a count measured over `docs/` is invalidated by the next page anyone
     * adds, and this file's whole subject is prose drifting away from what is
     * true. What is claimed is the SHAPE — `README.md` and several of the
     * non-roster `docs/*.md` pages each name variables from this roster, and
     * before this test not one of those appearances was compared against
     * anything at all. Every number behind that sentence is derived by
     * {@see testTheMentionCensusActuallyReadsTheOtherPages()}, which asserts
     * floors rather than counts.
     *
     * THE FIRST DRAFT OF THIS PARAGRAPH ANNOUNCED "NO CARDINALITY IS QUOTED
     * HERE" AND THEN QUOTED TWO — "six of the eleven non-roster pages". Both
     * were correct when written and both are counts over `docs/`, which is the
     * thing the banner above them forbids. They are gone rather than refreshed:
     * a fresher number is the same defect with a later date on it.
     *
     * THE RULE, AND WHY IT IS NOT SYMMETRIC. The surfaces make different
     * promises, so they get different oracles:
     *
     * - `docs/ENVIRONMENT.md` is the ROSTER. It says "every environment
     *   variable CandyCode reads, in one place", so BOTH directions are
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
     * form: a fenced `export CANDY_CODE_PROVIDER=…` in `README.md` is exactly
     * as much of an instruction as a table row, and the roster page — the one
     * page whose prose deliberately discusses variables nothing reads — is
     * excluded from this scrape rather than exempted inside it.
     *
     * @return array<string, list<string>> variable => the pages that name it
     */
    private function mentionedNames(): array
    {
        return $this->mentionNamesIn($this->mentionSurfaces());
    }

    /**
     * The census itself, over any set of labelled texts.
     *
     * SPLIT OUT SO A FIXTURE CAN DRIVE IT, and that is a repair rather than a
     * tidy-up. WHAT THE TESTS BELOW SAID: that they run "the scraper" over a
     * known positive, so their empty results are evidence in round 44's sense.
     * WHAT WAS TRUE: the fixture they ran exercised {@see prefixedNamesIn()},
     * the NAME PATTERN, while the answer they then asserted on came from this
     * census. MEASURED on PHP 8.3.6 at round 45: with the accumulation line
     * blinded, both empty-result tests passed — 641 assertions, entirely green,
     * over a dead instrument. That is round 44's exact failure, reproduced
     * inside the fix written to invoke it. WHY THE SENTENCE STILL EARNS ITS
     * PLACE: the requirement was right and the seam was missing. The fixture
     * now drives THIS method, so blinding it reds.
     *
     * WHAT A FIXTURE HERE STILL CANNOT SEE is the surface walk —
     * {@see mentionSurfaces()}' glob, its `README.md`, and the roster exclusion
     * — because that half reads the disk by construction. It is guarded from
     * outside by {@see testTheMentionCensusActuallyReadsTheOtherPages()}, which
     * is named rather than assumed so the next reader does not delete the one
     * test holding that half up.
     *
     * @param array<string, string> $surfaces label => the page's text
     *
     * @return array<string, list<string>> variable => the pages that name it
     */
    private function mentionNamesIn(array $surfaces): array
    {
        $found = [];
        foreach ($surfaces as $label => $text) {
            foreach ($this->prefixedNamesIn($text) as $name) {
                $found[$name][] = $label;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * The monorepo's per-lib landing page, E124's fourth surface.
     *
     * OUTSIDE THE LIB — this is the one surface a split-repo clone does not
     * have, and the assertion below is deliberately FAIL-CLOSED about that:
     * a mention surface that cannot be located reddens rather than quietly
     * leaving the census one page short. `docs/index.html` generates
     * `docs/lib/candy-code.html` from exactly this file, so the prose that
     * names `CANDY_CODE_BACKEND_CMD`, `CANDY_CODE_MODEL` and
     * `CANDY_CODE_PROVIDER` to every visitor of the public site was, until
     * round 62, the one user-facing surface with no oracle over it — a name
     * renamed in `src/` would have kept advertising on the website as a
     * variable the reader could set to no effect.
     */
    private const BODY_HTML_DOC = __DIR__ . '/../../../docs/_data/candy-code.body.html';

    /**
     * Every page that MENTIONS, keyed by its repo-relative path.
     *
     * The roster is EXCLUDED here rather than exempted inside the census, for
     * the reason {@see tabulatedNames()} sets out: it is the one page whose
     * prose deliberately discusses a variable nothing reads.
     *
     * `docs/` IS WALKED RECURSIVELY (E148). WHAT THIS USED TO BE:
     * `glob($root . '/docs/*.md')` — accurate while `docs/` was flat, silent
     * the moment anyone filed a page one directory deeper, and there is no
     * `GlobFigureDriftTest`-shaped guard for a surface census: a page the walk
     * skips is not a missing hit, it is a missing PAGE, indistinguishable from
     * a page that names nothing. Recursion costs nothing today and buys the
     * first `docs/reference/` for free; the walk itself is pinned by
     * {@see testTheMentionCensusActuallyReadsTheOtherPages()} against a fixture
     * tree rather than against this repo's layout, because the half of the
     * defect is that the disk shape and the code shape coincide.
     *
     * SKILL.MD FILES ARE DELIBERATELY NOT MENTION SURFACES, and the decision
     * is recorded rather than defaulted. MEASURED 2026-09-11: not one
     * `SKILL.md` under `src/Skills/BuiltIn` carries a prefixed name today, and
     * the only `.md` files outside the surface set that do —
     * `CALIBER_LEARNINGS.md` naming `CANDY_CODE_SEARCH_ENDPOINT` and
     * `CHANGELOG.md` naming `CANDY_CODE_SESSION_RETENTION_DAYS` — name
     * variables the census already covers through `README.md`, so widening
     * would close no gap today. It is declined for a shape reason: a
     * `SKILL.md` is prompt text a model reads, and users author and import
     * them; making every skill body a promise the roster oracle enforces would tie third-party prose into a docs rule no
     * skill author was told about. The day a shipped skill tells the USER to
     * set a variable, the right fix is to add that file to the surface list
     * with a line saying so — not to let the name ride ungoverned, which is
     * what a silent walk would otherwise do.
     *
     * @return array<string, string>
     */
    private function mentionSurfaces(): array
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertIsString($root);

        $roster = realpath(self::ENVIRONMENT_DOC);
        self::assertIsString($roster, 'docs/ENVIRONMENT.md is gone, so the exclusion below excludes nothing');

        // The census's historical tier, kept as an explicit floor: every page
        // the walk finds through recursion it also proves by the FLAT sweep
        // still having something at its top. A re-layout that moved ALL pages
        // one directory down is a layout decision worth a red, not an
        // absorption.
        $flatTier = glob($root . '/docs/*.md') ?: [];
        self::assertNotEmpty(
            $flatTier,
            'not one page sits directly in docs/ anymore — the surface census predates a layout change '
            . 'and its floors below were written against the old tier',
        );

        $paths = array_merge([$root . '/README.md'], $this->docsSurfacePaths($root . '/docs'));
        self::assertGreaterThan(
            1,
            \count($paths),
            'the mention surfaces collapsed to one file or none, so an empty result means nothing',
        );

        $surfaces = [];
        foreach ($paths as $path) {
            if (realpath($path) === $roster) {
                continue;
            }
            $surfaces[substr($path, \strlen($root) + 1)] = self::read($path);
        }

        $body = realpath(self::BODY_HTML_DOC);
        self::assertIsString(
            $body,
            'docs/_data/candy-code.body.html is not readable from here — the suite is running outside a '
            . 'monorepo checkout, where the landing page does not live; run it from the monorepo, do not '
            . 'delete the surface',
        );
        $surfaces['docs/_data/candy-code.body.html'] = self::read($body);

        return $surfaces;
    }

    /**
     * Every `.md` under `$dir`, at any depth.
     *
     * A METHOD, not an inlined walk, because E148's whole claim is about DEPTH
     * and the repo today cannot demonstrate depth without rotting the first
     * time a page moves — so the fixture tree below is the demonstration, and
     * this is the seam it drives.
     *
     * A GLOB DESCENT rather than an iterator: the per-directory `*.md` sweep
     * recursed through directory children is exactly as deep, and it keeps the
     * `docs/*.md` glob vocabulary in the tree, which the derived corpus of
     * `\SugarCraft\Code\Tests\Context\GlobDialectDifferentialTest` harvests —
     * a walk refactor that silently moves that census's pair product would
     * make this file a stranger's red.
     *
     * @return list<string>
     */
    private function docsSurfacePaths(string $dir): array
    {
        $found = glob($dir . '/*.md') ?: [];
        foreach (glob($dir . '/*', \GLOB_ONLYDIR) ?: [] as $sub) {
            $found = array_merge($found, $this->docsSurfacePaths($sub));
        }
        sort($found);

        return $found;
    }

    /**
     * Two pages whose census answer is known, for the tests that assert `[]`.
     *
     * THE WRAPPED NAME IS E149'S FIXTURE, and it earns its place the way every
     * fixture in this file does: the live census cannot demonstrate the shape
     * on demand — no shipped page wraps a name today (measured 2026-09-11) —
     * so the mechanism is pinned where its answer is known instead. The wrap
     * is asserted in BOTH directions by one exact `assertSame`: the rejoined
     * `CANDY_CODE_FIXTURE_WRAPPED` must be in the answer (positive), and the
     * truncated head `CANDY_CODE_FIXTURE_`, which the raw pattern happily
     * captures on its own, must NOT be (negative — it would otherwise surface
     * as an unkept promise about a variable nobody spells that way).
     *
     * @return array<string, string>
     */
    private static function censusFixture(): array
    {
        return [
            'fixture-a.md' => 'set `CANDY_CODE_FIXTURE_ONE`, or the old `CANDY_CODE_FIXTURE_TWO`',
            'fixture-b.md' => "and `CANDY_CODE_FIXTURE_ONE` again, in a second page\n"
                . "the wrapped `CANDY_CODE_FIXTURE_\nWRAPPED` sits across a soft line break",
        ];
    }

    /** The census answer {@see censusFixture()} must produce. */
    private const CENSUS_FIXTURE_ANSWER = [
        'CANDY_CODE_FIXTURE_ONE' => ['fixture-a.md', 'fixture-b.md'],
        'CANDY_CODE_FIXTURE_TWO' => ['fixture-a.md'],
        'CANDY_CODE_FIXTURE_WRAPPED' => ['fixture-b.md'],
    ];

    /**
     * Every `CANDY_CODE_*` name in a blob of text.
     *
     * The same alphabet {@see tabulatedNames()} uses: canonical
     * `CANDY_CODE_*` names. Pre-rename spellings fold in at startup and the
     * alias table is where the page keeps them, outside this census.
     *
     * NORMALISED THROUGH THE SHARED WINDOW (E149). WHAT THIS USED TO BE: one
     * `preg_match_all()` over the raw page text — a different window from the
     * one every other doc-drift oracle in this suite reads through, so the two
     * halves of the family could disagree about where a paragraph ends. What
     * it is now: {@see DocumentParagraphs::of()} splits the text into units
     * and each unit is scraped, which puts both censuses on one window.
     *
     * THE WINDOW IS ALSO WHERE THE WRAP FIX LIVES, and the wrap was the
     * reason normalising was not enough on its own. A name broken by a
     * markdown soft line break reaches a unit as
     * `CANDY_CODE_SESSION_ RETENTION_DAYS` (the newline collapsed, not
     * removed), and the raw pattern then sees a truncated head
     * `CANDY_CODE_SESSION_` — a well-formed-looking NAME the code has never
     * heard of, reported as an unkept promise — plus a prefix-less tail that
     * matches nothing at all. The second pattern below admits EXACTLY ONE
     * whitespace gap inside the name body, rejoins it, and vetoes the raw
     * head so the census reports the name rather than its halves. ONE GAP,
     * not more, because a soft wrap replaces one newline, and an unbounded
     * gap would let any name followed by an ALL-CAPS word —
     * "`CANDY_CODE_X` OR" — conjure a candidate; the cap keeps the ambiguity
     * on the side where rejoining is the only reading a wrapped identifier
     * has. THE LIMIT IS STATED RATHER THAN SMOOTHED: a break INSIDE the fixed
     * prefix (`CANDY_\nCODE_X`) is not rejoined, because admitting a gap
     * there would make the ordinary words `CANDY CODE...` in any casing a
     * name candidate — the hazard is one-sided and this oracle refuses to
     * buy coverage with a prose-hunting regex. A break is also invisible to
     * the tabulated side of the roster in exactly the same way; both halves
     * see whole tokens, and that is the shared assumption now written down.
     * MEASURED 2026-09-11 over every surface the census reads: no prefixed
     * name ends a line anywhere in scope, and no unit contains a
     * name-then-ALL-CAPS-word collision, so the joined half reports exactly
     * what the raw half reported before, and the difference in the two sets
     * today is zero. The fixture in {@see censusFixture()} is the day that
     * stops being true, because a real wrapped page is not something the
     * census can be asked to demonstrate on demand.
     *
     * @return list<string>
     */
    private function prefixedNamesIn(string $text): array
    {
        $names = [];
        foreach (DocumentParagraphs::of($text) as $unit) {
            $matched = preg_match_all('/\b(CANDY_CODE_[A-Z0-9_]+)\b/', $unit, $matches);
            $this->assertIsInt($matched, 'the mention scrape failed to run, so its answer means nothing');
            $whole = $matches[1];

            $matched = preg_match_all('/\b(CANDY_CODE_[A-Z0-9_]* [A-Z0-9_]+)\b/', $unit, $wrapped);
            $this->assertIsInt($matched, 'the wrapped-name scrape failed to run, so its answer means nothing');
            foreach ($wrapped[1] as $spelled) {
                $joined = str_replace(' ', '', $spelled);
                if (preg_match(EnvReadScanner::NAME_PATTERN, $joined) !== 1) {
                    continue;
                }
                $whole[] = $joined;
                // The head the raw pattern captured across the break is a
                // piece of this name, not a name; keeping it would report the
                // wrap as an unkept promise about a variable nobody has ever
                // spelled that way.
                $head = strstr($spelled, ' ', true);
                foreach ($whole as $k => $candidate) {
                    if ($candidate === $head) {
                        unset($whole[$k]);
                    }
                }
            }

            foreach ($whole as $name) {
                $names[$name] = true;
            }
        }

        $out = array_keys($names);
        sort($out);

        return $out;
    }

    /**
     * A name printed on a mention surface is one the code reads.
     *
     * THE CENSUS IS RUN OVER A KNOWN POSITIVE IN THIS SAME TEST. An assertion
     * that a diff is empty passes just as well in a tree where the census has
     * silently stopped matching — round 44 measured that exactly: a census
     * mutated to never match reported "nothing is stale" across eighteen
     * thousand green assertions. The fixture is what makes the empty result
     * below evidence rather than decoration, and it drives
     * {@see mentionNamesIn()} — the method whose answer this test asserts on —
     * rather than {@see prefixedNamesIn()} alone, which is the half the round-45
     * version of this doc-block claimed and did not do. The surface walk is the
     * remaining half and is guarded by
     * {@see testTheMentionCensusActuallyReadsTheOtherPages()}.
     */
    public function testEveryVariableAnotherPageNamesIsOneTheCodeReads(): void
    {
        $this->assertSame(
            self::CENSUS_FIXTURE_ANSWER,
            $this->mentionNamesIn(self::censusFixture()),
            'the mention census cannot place the variable names in a two-page fixture that has '
            . 'three occurrences of two names, so the census below is not evidence of anything',
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
     * `CANDY_CODE_DEBUG_SKILLS`' defect from the other side: that one was
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
        // THE SAME KNOWN POSITIVE AS THE TEST ABOVE, and it belongs here too:
        // this assertion is also an empty-diff assertion, and round 45 shipped
        // it with no fixture at all. Blinding the census left it green.
        $this->assertSame(
            self::CENSUS_FIXTURE_ANSWER,
            $this->mentionNamesIn(self::censusFixture()),
            'the mention census cannot place the variable names in a two-page fixture that has '
            . 'three occurrences of two names, so the census below is not evidence of anything',
        );

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
     * {@see mentionSurfaces()} stopped matching, or its roster exclusion started
     * excluding everything, both censuses above would report `[]` and pass —
     * and their own fixtures could not tell, because a fixture drives
     * {@see mentionNamesIn()} and cannot reach the disk walk. This test is the
     * one that holds that half up; the two above name it for that reason. The
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
        $this->assertArrayHasKey(
            'docs/_data/candy-code.body.html',
            $pages,
            'the monorepo landing page is not being scraped — the E124 surface would silently vanish '
            . 'from the census if its glob-shaped half were deleted, exactly how the docs/ half of '
            . 'GlobFigureDriftTest went blind in round 43',
        );
        $this->assertGreaterThan(
            3,
            \count($pages),
            'the mention census is reading barely any pages, so its empty diffs say almost nothing',
        );

        // THE RECURSIVE HALF OF THE WALK, pinned against a tree that actually
        // HAS depth (E148). This repo cannot demonstrate it — `docs/` is flat
        // today, so the live walk matches a flat `glob()` exactly and deleting
        // the recursion changes nothing the assertions above can see. A depth
        // fixture is therefore the only witness, and a temp tree (not a page
        // committed under `docs/`) so the census itself is not held hostage to
        // a file that exists only to be walked.
        $deep = \sys_get_temp_dir() . '/env_surface_walk_' . \getmypid() . '_' . \bin2hex(\random_bytes(4)) . '/docs';
        \mkdir($deep . '/reference', 0777, true);
        \file_put_contents($deep . '/flat.md', "flat page\n");
        \file_put_contents($deep . '/reference/nested.md', "a page one directory down\n");

        try {
            $walked = array_map(
                static fn (string $p): string => substr($p, \strlen($deep) + 1),
                $this->docsSurfacePaths($deep),
            );

            $this->assertSame(
                ['flat.md', 'reference/nested.md'],
                $walked,
                'the docs/ walk no longer reaches a page one directory deep — E148 was filed against '
                . 'exactly this shape, and a skipped surface is invisible to every set diff in this file',
            );
        } finally {
            \unlink($deep . '/flat.md');
            \unlink($deep . '/reference/nested.md');
            \rmdir($deep . '/reference');
            \rmdir($deep);
            \rmdir(\dirname($deep));
        }
    }

    /**
     * rv-devops-3 (fix lane f-docs, 2026-10-14): THE OS-VARIABLE ROSTER,
     * PINNED BOTH DIRECTIONS.
     *
     * Everything else in this class speaks the `CANDY_CODE_*` alphabet — the
     * scanner's and the table scrape's alike — so an OS variable could sit
     * read-but-undocumented (or documented-but-renamed) with no oracle at all.
     * That is exactly how `LANG`, `DISPLAY`, `CDPATH` and `TMPDIR` lived
     * untabulated while the page opened with "every environment variable".
     *
     * These names churn rarely, so the remedy is a hardcoded roster plus two
     * set comparisons: every pinned name has a row in the page's "OS
     * variables" table, and every name the table carries is pinned. The third
     * leg asks that each pinned name still appears as a quoted string literal
     * in `src/` — a floor, not a proof: it cannot see WHICH call shape reads
     * it (the locale trio is read through a `foreach` over exactly these
     * literals, which no call-shape scanner could follow), but it reddens the
     * moment a name is renamed or its last read deleted, which is the drift
     * class a roster like this can actually catch.
     *
     * @var list<string>
     */
    private const OS_ENV_ROSTER = [
        'HOME', 'USERPROFILE', 'LANG', 'LC_ALL', 'LC_MESSAGES',
        'PATH', 'VISUAL', 'EDITOR', 'TMUX', 'TERM_PROGRAM', 'TERM', 'COLORFGBG',
        'DISPLAY', 'WAYLAND_DISPLAY', 'CDPATH', 'TMPDIR',
    ];

    /**
     * THE CARVE-OUT ROSTER: variables the launch reads through the foundation
     * palette rather than through `src/`, so the token scan above cannot see
     * them (see the class doc-block). Pinned against the page's "Terminal
     * colour variables" table by the same legs, plus a liveness check against
     * the real `ColorProfile::detect()` source.
     *
     * @var list<string>
     */
    private const AMBIENT_COLOR_ROSTER = [
        'CLICOLOR_FORCE', 'FORCE_COLOR', 'NO_COLOR',
    ];

    public function testTheOsEnvironmentRosterIsPinnedInBothDirections(): void
    {
        $pinned = self::OS_ENV_ROSTER;
        sort($pinned);
        $documented = $this->osTableNames();

        $this->assertSame(
            [],
            array_values(array_diff($pinned, $documented)),
            'the pinned OS roster names variables docs/ENVIRONMENT.md no longer tabulates — '
            . 'the page lost rows it promised, or the roster needs the same judgment the rows do',
        );
        $this->assertSame(
            [],
            array_values(array_diff($documented, $pinned)),
            'docs/ENVIRONMENT.md tabulates OS variables the pinned roster does not carry — '
            . 'add them to OS_ENV_ROSTER deliberately, not silently',
        );

        $literals = $this->srcStringLiterals();
        $unseen = [];
        foreach ($pinned as $name) {
            if (!\in_array($name, $literals, true)) {
                $unseen[] = $name;
            }
        }
        $this->assertSame(
            [],
            $unseen,
            'pinned OS variables with no quoted literal anywhere in src/ — the reads moved, '
            . 'renamed, or died; the table rows are now promises nothing keeps',
        );

        // ── the carve-out legs: colour variables consulted OUTSIDE src/ ────
        //
        // The same three legs, against the page's "Terminal colour variables"
        // table and the palette source that actually reads them — the class
        // doc-block explains why the scanner cannot reach this family itself.
        $colourPinned = self::AMBIENT_COLOR_ROSTER;
        sort($colourPinned);
        $colourDocumented = $this->colourTableNames();

        $this->assertSame(
            [],
            array_values(array_diff($colourPinned, $colourDocumented)),
            'the pinned colour roster names a variable docs/ENVIRONMENT.md no longer tabulates — '
            . 'the page lost a row it promised, or the roster needs the same judgment the rows do',
        );
        $this->assertSame(
            [],
            array_values(array_diff($colourDocumented, $colourPinned)),
            'docs/ENVIRONMENT.md tabulates a terminal colour variable the pinned roster does not carry — '
            . 'add it to AMBIENT_COLOR_ROSTER deliberately, not silently',
        );

        $palette = (new \ReflectionMethod(ColorProfile::class, 'detect'))->getFileName();
        $this->assertIsString(
            $palette,
            'the palette source cannot be resolved — the colour liveness leg below would measure nothing',
        );
        $colourSource = (string) file_get_contents($palette);
        $unlive = [];
        foreach ($colourPinned as $name) {
            if (!str_contains($colourSource, "'" . $name . "'")) {
                $unlive[] = $name;
            }
        }
        $this->assertSame(
            [],
            $unlive,
            'pinned colour variables with no quoted env key in the palette source — it stopped '
            . 'consulting them, and the page still tells readers to set them',
        );
        $this->assertSame(
            0,
            substr_count($colourSource, "'CLICOLOR'"),
            'docs/ENVIRONMENT.md says plain CLICOLOR is deliberately not consulted — the palette now '
            . 'reads it, so the page sentence and this roster need the same judgment',
        );
    }

    /**
     * THE PROVIDER-FAMILY AMBIENT ROSTER, PINNED BOTH DIRECTIONS.
     *
     * The OS table above hand-pins its list because call-shape proof is
     * exactly what that roster cannot supply for a `foreach`-read name. The
     * provider family CAN be derived: {@see EnvReadScanner::ambientReads()}
     * follows the same five call shapes over its second alphabet and records
     * a name only when a `getenv()` — direct, forwarded, or `foreach`-fed,
     * through a constant if that is how the file spells it — proves the read.
     * So this oracle compares DERIVED against documented, three ways:
     *
     * - the allowlist in `EnvReadScanner::AMBIENT_NAMES` must equal what the
     *   real scan actually places — an entry whose read is renamed or deleted
     *   reddens HERE, before anyone wonders why the table lost its mirror;
     * - every placed name must have a row in the page's provider-credential
     *   or Claude Code tables (the read-but-undocumented defect);
     * - every first-column name in those tables must be placed (the
     *   documented-but-dead defect — a row telling a reader to export a
     *   variable nothing reads).
     *
     * WHY AMBIENT NAMES DO NOT JOIN `reads()`: that map feeds the help-screen
     * roster in `HelpTest`, whose entries live in the `cli.help.screen`
     * catalogue — widening `reads()` would demand twelve-plus screen entries
     * in one silent step, a product decision wearing a census costume. The
     * separate channel keeps both censuses honest about their own scope. And
     * `GOOGLE_APPLICATION_CREDENTIALS` is in NEITHER: this package's `src/`
     * never reads it; the vendored Google SDK does, at its own layer, and a
     * census documents what its own code touches.
     *
     * THE KNOWN POSITIVE is not decoration: round 44 measured that an empty
     * derived set passes every set comparison, so the fixture below proves
     * the channel places a `getenv()` argument AND refuses to place the same
     * name in a non-read position, in one run.
     */
    public function testTheProviderAmbientRosterIsPinnedBothDirections(): void
    {
        $probe = new EnvReadScanner([
            'fixture.php' => '<?php class F { public function e() { return getenv(\'OPENAI_API_KEY\'); }'
                . ' public function h(): array { return [\'hint\' => \'OPENAI_BASE_URL\']; } }',
        ]);
        $this->assertSame(
            ['OPENAI_API_KEY'],
            array_keys($probe->ambientReads()),
            'the ambient channel cannot place a direct getenv() argument, or placed a name that only '
                . 'sat in an array — either way the derived roster below is measuring nothing',
        );
        $this->assertSame(
            [],
            $probe->unresolved(),
            'an ambient mention in a non-read position was reported as an unplaced promise — the '
                . 'fail-closed channel speaks the CANDY_CODE alphabet only',
        );

        $allowlist = explode('|', EnvReadScanner::AMBIENT_NAMES);
        sort($allowlist);
        $placed = array_keys($this->scanner()->ambientReads());
        sort($placed);
        $this->assertSame(
            $allowlist,
            $placed,
            'the ambient allowlist and the scan disagree — a listed name whose read moved, died, or '
                . 'was never shaped like a read; drop it from AMBIENT_NAMES, do not widen the table',
        );

        $documented = $this->ambientTableNames();
        $this->assertSame(
            [],
            array_values(array_diff($placed, $documented)),
            'src/ reads an ambient provider variable that docs/ENVIRONMENT.md does not tabulate',
        );
        $this->assertSame(
            [],
            array_values(array_diff($documented, $placed)),
            'docs/ENVIRONMENT.md tabulates an ambient variable nothing reads any more — the row is '
                . 'a promise the code stopped keeping',
        );
    }

    /**
     * First-column backticked upper-case names across the page's two
     * provider-facing tables — "Provider credential variables" and "Claude
     * Code variables" — each bounded to its own section exactly the way
     * {@see osTableNames()} bounds the OS one, so a description cell naming
     * another variable cannot vote in this census.
     *
     * @return list<string>
     */
    private function ambientTableNames(): array
    {
        $page = self::read(self::ENVIRONMENT_DOC);
        $names = [];
        foreach (["## Provider credential variables\n", "## Claude Code variables\n"] as $heading) {
            $start = strpos($page, $heading);
            $this->assertNotFalse($start, 'the section heading moved — this oracle is now blind');
            $rest = substr($page, $start);
            $end = strpos(substr($rest, 3), "\n## ");
            $section = $end === false ? $rest : substr($rest, 0, $end + 3);
            foreach (explode("\n", $section) as $line) {
                if (preg_match('/^\|\s*(.+?)\s\|/', $line, $cell) !== 1) {
                    continue;
                }
                if (preg_match_all('/`([A-Z][A-Z0-9_]*)`/', $cell[1], $found) >= 1) {
                    foreach ($found[1] as $name) {
                        $names[$name] = true;
                    }
                }
            }
        }

        $list = array_keys($names);
        sort($list);
        $this->assertGreaterThan(
            5,
            \count($list),
            'the ambient table scrape found almost nothing — table syntax changed and this oracle '
                . 'compares against an empty-ish set',
        );

        return $list;
    }

    /**
     * All backticked upper-case names in the FIRST column of the page's
     * "OS variables" table, across the section only — the scrape is bounded
     * between the heading and the next `##` so an app-tier row elsewhere on
     * the page can never leak into this oracle (or vice versa).
     *
     * @return list<string>
     */
    private function osTableNames(): array
    {
        $page = self::read(self::ENVIRONMENT_DOC);
        $start = strpos($page, "## OS variables\n");
        $this->assertNotFalse($start, 'the "OS variables" section heading moved — this oracle is now blind');
        $rest = substr($page, $start);
        $end = strpos(substr($rest, 3), "\n## ");
        $section = $end === false ? $rest : substr($rest, 0, $end + 3);

        $names = [];
        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^\|\s*(.+?)\s\|/', $line, $cell) !== 1) {
                continue;
            }
            if (preg_match_all('/`([A-Z][A-Z0-9_]*)`/', $cell[1], $found) >= 1) {
                foreach ($found[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        $list = array_keys($names);
        sort($list);
        $this->assertGreaterThan(10, \count($list), 'the OS-table scrape found almost nothing — table syntax changed and this oracle compares against an empty-ish set');

        return $list;
    }

    /**
     * First-column backticked upper-case names in the page's "Terminal colour
     * variables" table — the carve-out section {@see osTableNames()} cannot
     * reach — bounded to that section exactly the same way, so a description
     * cell naming another variable cannot vote in this census.
     *
     * @return list<string>
     */
    private function colourTableNames(): array
    {
        $page = self::read(self::ENVIRONMENT_DOC);
        $start = strpos($page, "## Terminal colour variables\n");
        $this->assertNotFalse($start, 'the "Terminal colour variables" section heading moved — this oracle is now blind');
        $rest = substr($page, $start);
        $end = strpos(substr($rest, 3), "\n## ");
        $section = $end === false ? $rest : substr($rest, 0, $end + 3);

        $names = [];
        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^\|\s*(.+?)\s\|/', $line, $cell) !== 1) {
                continue;
            }
            if (preg_match_all('/`([A-Z][A-Z0-9_]*)`/', $cell[1], $found) >= 1) {
                foreach ($found[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        $list = array_keys($names);
        sort($list);
        $this->assertGreaterThan(
            2,
            \count($list),
            'the colour-table scrape found almost nothing — table syntax changed and this oracle '
            . 'compares against an empty-ish set',
        );

        return $list;
    }

    /**
     * Every fully-quoted upper-case string literal in `src/`, token-scanned
     * (comments and identifiers excluded by construction — a name that only
     * survives in prose is not a read).
     *
     * @return list<string>
     */
    private function srcStringLiterals(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (\is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = trim($token[1], "\"'");
                    if ($value !== '' && preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1) {
                        $found[$value] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }
}
