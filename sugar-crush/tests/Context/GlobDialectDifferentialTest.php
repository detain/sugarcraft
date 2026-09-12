<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SugarCraft\Crush\Context\Triggers\PathTrigger;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Skills\SkillPathPatternTest;
use SugarCraft\Crush\Util\PathGlob;
use ValueError;

/**
 * THE PROOF THAT P6.S5a DID WHAT IT SAID, in both directions.
 *
 * P6.S5a reduced three path-matching dialects to one compiler
 * ({@see \SugarCraft\Crush\Util\PathGlob}) and routed both production matchers
 * through it. That claim has two halves and they need opposite evidence.
 *
 * THE LIVE HALF. `SkillRegistry::pathMatches()` answers on a tool-call path, so
 * its YES-set must not have moved by even one path. Proved by an equivalence
 * harness: a FROZEN TRANSCRIPTION of the master implementation, compiled into
 * this class, answers side by side with the shipped code over a corpus derived
 * from the repository's own globs, and the two agree on every pair — including
 * the compiled regexes themselves, which is the stronger claim, because two
 * regexes can agree on a corpus and disagree on the next path. The comparison
 * count is asserted and not quoted: a zero-difference claim with no measured
 * corpus is not evidence.
 *
 * THE DELIBERATE HALF. `PathTrigger` spoke the stricter dialect and its answer
 * WAS meant to move. Pinned row by row against the premise check's 33-row
 * differential, with the moved rows derived from a second frozen transcription
 * (the deleted strict compiler) rather than typed: ten rows widen, three narrow,
 * twenty hold. The three narrowings are named in
 * {@see \SugarCraft\Crush\Context\Triggers\PathTrigger}'s doc-block and are not
 * glossed here either.
 *
 * WHY THE THIRTEEN AND NOT THE ELEVEN. The premise check's header, its Q7, and
 * the step brief all say ELEVEN rows disagree. MEASURED on `master` at
 * `f3134703f` through the two transcriptions this file carries, the figure is
 * THIRTEEN — rows 5, 6, 8, 10, 11, 12, 13, 21, 22, 23, 26, 30 and 32 — while
 * every one of the table's 33 printed per-row answers reproduces exactly. The
 * header and the row data disagreed with each other; the row data is what the
 * tree says. {@see testTheRowsThatMoveAreThirteenAndNotEleven()} derives the
 * figure from the table instead of typing it, so this paragraph cannot go stale
 * without reddening.
 *
 * WHY AN ORACLE IS A TRANSCRIPTION AND NOT A CALL. An oracle that reached the
 * code under test would agree with it by construction. So both master compilers
 * are reproduced below as private methods, byte for byte apart from their names,
 * and each is kept honest by {@see testEachFrozenOracleStillAnswersThePairItWas
 * TakenFor()} — an edit to an oracle cannot quietly turn the harness into a
 * tautology while those discriminating pairs still hold.
 *
 * THE ONE HOLE IN "ONE DIALECT", and it is a policy hole rather than a dialect
 * hole: {@see \SugarCraft\Crush\Skills\SkillRegistry::legacyPathMatch()} answers
 * the globs this compiler cannot compile, and `fnmatch()` refuses a subject
 * holding a NUL byte outright. On that input the skill channel propagates a
 * `ValueError` where the rule trigger answers no match. Both polarities are
 * pinned, and the corpus-wide test asserts that NOTHING ELSE separates the two
 * matchers any more.
 */
final class GlobDialectDifferentialTest extends TestCase
{
    /**
     * The premise check's Q3 differential, transcribed to literal bytes.
     *
     * Columns: row, label, glob, path, the answer the DELETED strict
     * `PathTrigger` compiler gave, the answer the resolved dialect gives. The
     * fifth column is not trusted — every row re-derives it from
     * {@see frozenStrictTriggerMatches()} before using it.
     *
     * @var list<array{int,string,string,string,bool,bool}>
     */
    private const Q3_ROWS = [
        [1, 'double star', '**', 'src/a/b.php', true, true],
        [2, 'globstar mid, multi seg', 'a/**/b', 'a/x/y/b', true, true],
        [3, 'globstar mid, zero seg', 'a/**/b', 'a/b', true, true],
        [4, 'globstar mid, glued', 'a/**/b', 'ab', false, false],
        [5, 'trailing globstar matches the dir itself', 'src/**', 'src', false, true],
        [6, 'star crosses separator', 'src/*.php', 'src/deep/x.php', false, true],
        [7, 'star in one segment', 'src/*.php', 'src/x.php', true, true],
        [8, 'question crosses separator', 'a?b', 'a/b', false, true],
        [9, 'question one char', 'src/?.php', 'src/x.php', true, true],
        [10, 'class as class', '[a-z].php', 'a.php', false, true],
        [11, 'class as literal', '[a-z].php', '[a-z].php', true, false],
        [12, 'negated class', '[!a].php', 'b.php', false, true],
        [13, 'POSIX class', '[[:alpha:]].php', 'a.php', false, true],
        [14, 'brace set', '{a,b}.php', 'a.php', false, false],
        [15, 'brace literal', '{a,b}.php', '{a,b}.php', true, true],
        [16, 'case', 'SRC/*.php', 'src/a.php', false, false],
        [17, 'absolute vs double star', '**', '/etc/passwd', true, true],
        [18, 'absolute prefix', '/etc/**', '/etc/passwd', true, true],
        [19, 'leading dot-slash absent in path', './src/*', 'src/a.php', false, false],
        [20, 'leading dot-slash spelled', './src/*', './src/a.php', true, true],
        [21, 'backslash escapes star', 'a\\*b', 'a*b', false, true],
        [22, 'backslash as literal', 'a\\*b', 'a\\b', true, false],
        [23, 'Windows separators', 'src\\*.php', 'src\\a.php', true, false],
        [24, 'non-UTF-8 byte', 'src/*.php', "src/\xC3\x28.php", true, true],
        [25, 'PLAN GOAL, 1 leading seg', '*/tests/**/*.php', 'candy-core/tests/FooTest.php', true, true],
        [26, 'PLAN GOAL, 2 leading segs', '*/tests/**/*.php', 'a/b/tests/FooTest.php', false, true],
        [27, 'plan goal, nested specs dir', '*/tests/**/*.php', 'candy-core/tests/Sub/FooTest.php', true, true],
        [28, 'newline vs star', '*.php', "a\nb.php", true, true],
        [29, 'trailing newline in path', 'src.php', "src.php\n", false, false],
        [30, 'three stars', 'src/***', 'src_x', false, true],
        [31, 'leading globstar, root file', '**/*.php', 'a.php', true, true],
        [32, 'bare star', '*', 'a/b.php', false, true],
        [33, 'globstar then star', '**/*', 'a/b.php', true, true],
    ];

    /**
     * Patterns the sweep adds by hand — one per shape the corpus might otherwise
     * be blind to, including the shapes that do NOT compile.
     *
     * @var list<string>
     */
    private const SWEEP_PATTERNS = [
        '*', '**', '?', 'x', '.php', '..', 'a/./b', '\\', '#', 'a|b', '(a)', 'a+b', '^', '!',
        '*/*/*/*', '**/**/**', 'a*b*c', 'a?b', '?a?', '[!a]*', '[!a-z]*.php', '[[:alpha:]].php',
        '[a-z].php', '[]]x', '[abc.php', '[z-a].php', '[#x]y', '[\\d]x', 'a[\\]]b', 'a\\*b',
        'src\\*.php', './src/*', './**/*.php', '/etc/**', '/src/**/*.php', '**/node_modules/**',
        'src/**', 'src/**/*', 'a/**', 'a/**/b', 'src/**/t.php', 'a/**/**/b', 'src/***',
        'src/***/*.php', '**/*.php', '**/*', '**/', 'a/b/**', 'docs/**', '*.php', 'src/*.php',
        'src/**/*.php', '{a,b}.php', '*\\?[', '**/?id.php', '**/[!a-z]*.php', '[[:alpha:]][!a]',
    ];

    /**
     * Paths the sweep adds by hand, including the pathological alphabet the
     * dialect doc-block promises: NUL, newline, invalid UTF-8, backslash,
     * bare-directory strings, eight-segment depth.
     *
     * @var list<string>
     */
    private const SWEEP_PATHS = [
        '', 'a', 'a/b', 'src', 'src/', '/src', '/', 'a//b', 'a/./b', 'a/../b', '..',
        '/etc/passwd', 'a b.php', 'a+b', 'a(b)', 'a$b', 'a^b', 'a!b', 'a#b', 'a|b',
        'a?b', 'a*b', '[a]', 'a\\b', 'a/b\\c', '{a,b}.php', '[a-z].php', 'a]b',
        "a\nb.php", "src.php\n", "a\nb\nc", "src/x\ny.php", 'SRC/X.PHP', 'Makefile',
        'makefile', "src/\xC3\x28.php", "\xff\xfe.php", "a\0b", "src/\0.php",
        'x/x/x/x/x/x/x/x/f.php', './a', './src/x.php', 'src/deep/x.php',
        'candy-core/tests/FooTest.php', 'a/b/tests/FooTest.php', 'a/x/y/z/b',
        'src/x]y.php', 'src/a/b/c/d/e/f/x.php', 'composer.json', 'a/b/c/d/e/f/g/h/i/j.php',
    ];

    /**
     * The directories whose `.php` files and `SKILL.md` pages the corpus harvests.
     *
     * A DERIVED POPULATION rather than a hand-list. A corpus written from the
     * dialect's own spec sheet is the same document it claims to test; harvesting
     * the globs this repository actually contains is what makes the equivalence
     * claim about shipped strings instead of about strings this author could think
     * of. Every shipped `SKILL.md` `paths:` entry and every glob-shaped string
     * literal in `src/` and `tests/` lands here.
     *
     * @var list<string>
     */
    private const HARVEST_DIRS = ['src', 'tests'];

    public function testTheResolvedDialectAnswersEveryRowOfTheDifferential(): void
    {
        self::assertCount(33, self::Q3_ROWS, 'the premise check differential is a 33-row table');

        foreach (self::Q3_ROWS as [$n, $label, $glob, $path, $strictWas, $resolved]) {
            self::assertSame(
                $strictWas,
                self::frozenStrictTriggerMatches($glob, $path),
                sprintf('row #%d (%s): the table records a different strict-dialect answer than the deleted compiler gives', $n, $label),
            );
            self::assertSame(
                $resolved,
                SkillRegistry::pathMatches($glob, $path),
                sprintf('row #%d (%s): the skill channel does not answer %s for %s over %s', $n, $label, self::say($glob), self::say($path), $resolved ? 'YES' : 'NO'),
            );
            self::assertSame(
                $resolved,
                PathTrigger::new([$glob])->matches($path),
                sprintf('row #%d (%s): the rule trigger does not answer %s for %s', $n, $label, self::say($glob), self::say($path)),
            );
        }
    }

    public function testTheRowsThatMoveAreThirteenAndNotEleven(): void
    {
        $widen = [];
        $narrow = [];
        $hold = [];
        foreach (self::Q3_ROWS as [$n, , $glob, $path, $strictWas, $resolved]) {
            self::assertSame($strictWas, self::frozenStrictTriggerMatches($glob, $path), "row #{$n} provenance");
            if ($strictWas === $resolved) {
                $hold[] = $n;
            } elseif ($resolved) {
                $widen[] = $n;
            } else {
                $narrow[] = $n;
            }
        }

        self::assertSame([5, 6, 8, 10, 12, 13, 21, 26, 30, 32], $widen);
        self::assertSame([11, 22, 23], $narrow);
        self::assertCount(20, $hold);
        self::assertSame(33, count($widen) + count($narrow) + count($hold));
    }

    /**
     * The live half: the skill channel against the implementation it replaced,
     * over every pair in the derived corpus.
     */
    public function testTheSkillChannelDidNotMoveByEvenOnePath(): void
    {
        $patterns = self::patternCorpus();
        $paths = self::pathCorpus();
        $executed = 0;
        $differences = [];

        foreach ($patterns as $glob) {
            foreach ($paths as $path) {
                $executed++;
                $before = self::outcome(static fn (): bool => self::frozenPathMatches($glob, $path));
                $after = self::outcome(static fn (): bool => SkillRegistry::pathMatches($glob, $path));
                if ($before !== $after) {
                    $differences[] = sprintf(
                        '%s ~ %s : before %s, after %s',
                        self::say($glob),
                        self::say($path),
                        $before,
                        $after,
                    );
                }
            }
        }

        self::assertSame(
            count($patterns) * count($paths),
            $executed,
            'the harness did not visit every pair it counted in its own corpus',
        );
        self::assertGreaterThan(
            100_000,
            $executed,
            sprintf('%d pairs cannot support a "not by even one path" claim', $executed),
        );
        self::assertSame([], $differences, sprintf(
            '%d of %d comparisons (%d patterns x %d paths) moved the live skill matcher',
            count($differences),
            $executed,
            count($patterns),
            count($paths),
        ));
    }

    /**
     * The pair count the compiler's own doc-block quotes is DERIVED here and
     * never retyped.
     *
     * That sentence is the evidence behind a claim of "proven identical", and
     * the figure before it rotted for the plain reason that nothing read it
     * back: the equivalence test above asserts a product it computes for itself,
     * so a corpus that grows leaves the prose quoting yesterday's count with
     * every test still green. Scraping the shipped sentence and holding it
     * against the same derivation is what makes the number self-maintaining -
     * and the parsing means it: the sentence is read by two fixed, independent
     * regexes, neither of which interpolates any number this test computes,
     * and each capture is asserted present before its figure is compared. A
     * rewording or a deletion therefore reddens this test as loudly as a stale
     * figure does, instead of letting the needle miss the prose and the body
     * skip itself into vacuous green - the exact failure mode a guard built
     * from the very figure it pins cannot catch, and the one that exists here
     * to close.
     */
    public function testTheCorpusFigureInTheDocBlockIsTheDerivedOne(): void
    {
        $source = (new ReflectionClass(PathGlob::class))->getFileName();
        self::assertIsString(
            $source,
            'the compiler class cannot be located, so the sentence inside it cannot be read',
        );
        $text = file_get_contents($source);
        self::assertIsString(
            $text,
            $source . ' is unreadable, so the figure quoted in it cannot be checked',
        );

        // Flattened the way a doc-block has to be flattened: the comment's
        // continuation `*` first, then the remaining whitespace. The first cut
        // of this line collapsed whitespace only, and the sentence re-wrapped
        // one line later than it was written, so the pin read `pairs * (363`
        // and reddened on a change that moved no word at all.
        $flat = (string) preg_replace(['/\R\s*\*\s*/', '/\s+/'], ' ', $text);

        // Two fixed patterns written out as literals, neither holding a number
        // derived anywhere in this test: the product sentence and the factor
        // parenthetical are read independently of each other, and each match
        // is guarded before its figure is compared. Coupling them into one
        // pattern would let a mutation to either half report as the same
        // generic "sentence gone" and hide which figure moved.
        $matchedProduct = preg_match(
            '/proven identical over ([0-9][0-9,]*) pattern-path pairs/u',
            $flat,
            $documented,
        );
        self::assertSame(
            1,
            $matchedProduct,
            'the doc-block no longer states the pair count in the product sentence this pin '
            . 'reads. A "proven identical" claim whose evidence nothing pins is exactly how the '
            . 'last figure rotted - restore the sentence, and move this pattern with it if the '
            . 'rewording was deliberate',
        );
        $docProduct = $documented[1];

        $matchedFactors = preg_match(
            '/pattern-path pairs \(([0-9]+) patterns x ([0-9]+) paths\)/u',
            $flat,
            $documented,
        );
        self::assertSame(
            1,
            $matchedFactors,
            'the doc-block no longer spells its pair count as the (patterns x paths) '
            . 'parenthetical this pin reads. Losing it would leave the product figure '
            . 'unaccounted for - restore the parenthetical, and move this pattern with it if the '
            . 'rewording was deliberate',
        );
        $docPatterns = $documented[1];
        $docPaths = $documented[2];

        // Ground truth: the same corpora, counted the same way, that the
        // equivalence loop above iterates - no third count path.
        $patterns = count(self::patternCorpus());
        $paths = count(self::pathCorpus());

        self::assertSame(
            $patterns * $paths,
            (int) str_replace(',', '', $docProduct),
            sprintf(
                'the PathGlob doc-block pair-count has gone stale vs the derived corpus - '
                . 're-measure and update the class doc-block census in src/Util/PathGlob.php, which quotes %s pairs against the %d '
                . 'the corpora actually derive (%d patterns x %d paths); a literal here would '
                . 'only move the rot rather than end it',
                $docProduct,
                $patterns * $paths,
                $patterns,
                $paths,
            ),
        );
        self::assertSame(
            $patterns,
            (int) $docPatterns,
            sprintf(
                'the PathGlob doc-block quotes %s patterns against a derived corpus of %d - '
                . 'update the "N patterns" figure in the (N patterns x M paths) parenthetical',
                $docPatterns,
                $patterns,
            ),
        );
        self::assertSame(
            $paths,
            (int) $docPaths,
            sprintf(
                'the PathGlob doc-block quotes %s paths against a derived corpus of %d - '
                . 'update the "N paths" figure in the (M patterns x N paths) parenthetical',
                $docPaths,
                $paths,
            ),
        );
    }

    /**
     * The compiled REGEX must be identical too, and not merely the verdict: two
     * regexes can agree over a corpus and disagree on the next path.
     */
    public function testTheSharedCompilerEmitsByteIdenticalRegexes(): void
    {
        $patterns = self::patternCorpus();
        $differences = [];
        $uncompilable = 0;

        foreach ($patterns as $glob) {
            $after = PathGlob::compile($glob);
            $before = self::frozenCompile($glob);
            if ($before !== $after) {
                $differences[] = sprintf('%s : before %s, after %s', self::say($glob), $before, $after);
            }
            if (@preg_match($after, '') === false) {
                $uncompilable++;
            }
        }

        self::assertSame([], $differences, sprintf(
            '%d of %d patterns compile differently from the implementation this one replaced',
            count($differences),
            count($patterns),
        ));
        self::assertGreaterThan(0, $uncompilable, 'the corpus lost the uncompilable shapes the fallback exists for');
    }

    /**
     * One dialect means the two matchers may differ on exactly one thing: a glob
     * the shared compiler could not answer. Every disagreement found over the
     * corpus is required to be (a) on an uncompilable glob, (b) the skill channel
     * answering what the old predicate answers, and (c) the trigger answering no.
     * A disagreement that fails any of those three is a second dialect.
     */
    public function testNothingButTheFallbackPolicySeparatesTheTwoMatchers(): void
    {
        $patterns = self::patternCorpus();
        $paths = self::pathCorpus();
        $agreements = 0;
        $policyDifferences = 0;

        foreach ($patterns as $glob) {
            $trigger = PathTrigger::new([$glob]);
            $compiles = @preg_match(PathGlob::compile($glob), '') !== false;
            foreach ($paths as $path) {
                $skill = self::outcome(static fn (): bool => SkillRegistry::pathMatches($glob, $path));
                $rule = self::outcome(static fn (): bool => $trigger->matches($path));
                if ($skill === $rule) {
                    $agreements++;
                    continue;
                }

                $policyDifferences++;
                self::assertFalse($compiles, sprintf(
                    'the two matchers disagree on %s ~ %s, whose regex COMPILES — that is a second dialect, not a policy',
                    self::say($glob),
                    self::say($path),
                ));
                self::assertSame(
                    self::outcome(static fn (): bool => self::frozenLegacyPathMatches($glob, $path)),
                    $skill,
                    sprintf('the skill channel did not answer %s from the old predicate it claims to fall back to', self::say($glob)),
                );
                self::assertSame('0', $rule, 'the rule trigger must answer an uncompiled glob with no match');
            }
        }

        self::assertGreaterThan(100_000, $agreements, 'the two matchers were compared too few times to call them one dialect');

        // The count is RE-DERIVED from the oracles rather than typed: an
        // uncompilable glob separates the two matchers on exactly those paths
        // where the old predicate answers anything at all — a YES the trigger
        // cannot reach, or the `ValueError` `fnmatch()` throws on a NUL subject
        // and which the trigger never sees because it never calls `fnmatch()`.
        $uncompilable = array_values(array_filter(
            $patterns,
            static fn (string $glob): bool => @preg_match(PathGlob::compile($glob), '') === false,
        ));
        self::assertNotEmpty($uncompilable, 'the corpus lost the uncompilable shapes entirely');
        $expected = 0;
        foreach ($uncompilable as $glob) {
            foreach ($paths as $path) {
                if (self::outcome(static fn (): bool => self::frozenLegacyPathMatches($glob, $path)) !== '0') {
                    $expected++;
                }
            }
        }
        self::assertSame($expected, $policyDifferences, 'the divergence between the two matchers is not the fallback policy alone');
    }

    public function testTheRecordedPolicyDivergenceIsExactlyTheseShapes(): void
    {
        $escapedTerminator = 'a[\\]]b';
        self::assertFalse(
            @preg_match(PathGlob::compile($escapedTerminator), '') !== false,
            'the shape this step records as uncompilable has become compilable',
        );

        self::assertTrue(SkillRegistry::pathMatches($escapedTerminator, 'a]b'));
        self::assertFalse(PathTrigger::new([$escapedTerminator])->matches('a]b'));
        self::assertTrue(SkillRegistry::pathMatches('src/**/x[\\]]y.php', 'src/x]y.php'));
        self::assertFalse(PathTrigger::new(['src/**/x[\\]]y.php'])->matches('src/x]y.php'));

        // And the polarity the fallback cannot express at all: `fnmatch()` refuses
        // a NUL subject, so the channel that reaches it propagates and the channel
        // that does not stays silent.
        self::assertSame('E:' . ValueError::class, self::outcome(static fn (): bool => SkillRegistry::pathMatches($escapedTerminator, "a\0b")));
        self::assertSame('0', self::outcome(static fn (): bool => PathTrigger::new([$escapedTerminator])->matches("a\0b")));
    }

    public function testANulByteInThePathStillThrowsOnTheSkillChannelAndNotOnTheTrigger(): void
    {
        $this->expectException(ValueError::class);
        SkillRegistry::pathMatches('a[\\]]b', "a\0b");
    }

    /**
     * The 46 x 54 grid `SkillPathPatternTest` carries was CAPTURED from the old
     * predicate and the shipped translation rather than transcribed from either,
     * which makes it the third and least author-dependent oracle of the three.
     */
    public function testTheCapturedGridStillAnswersForBothMatchers(): void
    {
        $reflection = new ReflectionClass(SkillPathPatternTest::class);
        $grid = $reflection->getReflectionConstant('AFTER')->getValue();
        $paths = $reflection->getReflectionConstant('PATHS')->getValue();
        self::assertCount(46, $grid, 'the grid is 46 patterns wide');
        self::assertCount(54, $paths, 'the grid is 54 paths deep');

        $comparisons = 0;
        $differences = [];
        $yesCells = 0;
        foreach ($grid as $glob => $bits) {
            $trigger = PathTrigger::new([$glob]);
            foreach ($paths as $i => $path) {
                $comparisons++;
                $expected = ($bits[$i] ?? '0') === '1';
                $yesCells += $expected ? 1 : 0;
                if (SkillRegistry::pathMatches($glob, $path) !== $expected) {
                    $differences[] = sprintf('skill %s ~ %s', self::say($glob), self::say($path));
                }
                if ($trigger->matches($path) !== $expected) {
                    $differences[] = sprintf('rule %s ~ %s', self::say($glob), self::say($path));
                }
            }
        }

        self::assertSame(2_484, $comparisons);
        self::assertSame(378, $yesCells, 'the grid lost its YES cells, so a green run below would mean nothing');
        self::assertSame([], $differences, sprintf('%d of %d grid cells moved', count($differences), $comparisons));
    }

    /**
     * A corpus is a claim about coverage, so the coverage is asserted and not
     * implied: a family the alphabet cannot express contributes nothing to the
     * proof above, however large the pair count looks.
     */
    public function testTheCorpusCarriesEveryDialectFamilyOnBothPolarities(): void
    {
        $patterns = self::patternCorpus();
        $paths = self::pathCorpus();

        foreach (self::SWEEP_PATTERNS as $needle) {
            self::assertContains($needle, $patterns, 'a sweep pattern left the corpus');
        }
        foreach (self::SWEEP_PATHS as $needle) {
            self::assertContains($needle, $paths, 'a sweep path left the corpus');
        }

        $patternFamilies = [
            'leading globstar' => static fn (string $p): bool => str_starts_with($p, '**/'),
            'trailing globstar' => static fn (string $p): bool => str_ends_with($p, '/**'),
            'middle globstar' => static fn (string $p): bool => str_contains($p, '/**/'),
            'bare star' => static fn (string $p): bool => $p === '*',
            'three or more stars' => static fn (string $p): bool => str_contains($p, '***'),
            'question mark' => static fn (string $p): bool => str_contains($p, '?'),
            'negated class' => static fn (string $p): bool => str_contains($p, '[!'),
            'posix class' => static fn (string $p): bool => str_contains($p, '[:'),
            'any class' => static fn (string $p): bool => str_contains($p, '['),
            'backslash' => static fn (string $p): bool => str_contains($p, '\\'),
            'braces' => static fn (string $p): bool => str_contains($p, '{'),
            'uncompilable' => static fn (string $p): bool => @preg_match(PathGlob::compile($p), '') === false,
        ];
        foreach ($patternFamilies as $name => $holds) {
            self::assertNotEmpty(array_filter($patterns, $holds), "the corpus holds no pattern in the {$name} family");
        }

        $pathFamilies = [
            'absolute' => static fn (string $p): bool => str_starts_with($p, '/'),
            'dot-prefixed' => static fn (string $p): bool => str_starts_with($p, './'),
            'newline-bearing' => static fn (string $p): bool => str_contains($p, "\n"),
            'nul-bearing' => static fn (string $p): bool => str_contains($p, "\0"),
            'invalid-utf-8' => static fn (string $p): bool => preg_match('//u', $p) !== 1,
            'backslash-bearing' => static fn (string $p): bool => str_contains($p, '\\'),
            'deep' => static fn (string $p): bool => substr_count($p, '/') >= 4,
            'bare directory string' => static fn (string $p): bool => $p === 'src',
            'empty' => static fn (string $p): bool => $p === '',
        ];
        foreach ($pathFamilies as $name => $holds) {
            self::assertNotEmpty(array_filter($paths, $holds), "the corpus holds no path in the {$name} family");
        }

        self::assertContains('**/*.php', $patterns, 'shipped skills declare it');
        self::assertContains('**/*Test.php', $patterns, 'a shipped skill declares it');
        self::assertContains('composer.json', $patterns, 'a shipped skill declares it');
    }

    /**
     * Each oracle is only an oracle if it still answers the pair it was taken
     * for; these are the discriminating pairs, one per oracle, in both
     * polarities, and they are what stops the harness being a tautology.
     */
    public function testEachFrozenOracleStillAnswersThePairItWasTakenFor(): void
    {
        self::assertSame('#^src/.*\.php$#Ds', self::frozenCompile('src/*.php'));
        self::assertSame('#^src(?:/.*)?/.*\.php$#Ds', self::frozenCompile('src/**/*.php'));
        self::assertTrue(self::frozenPathMatches('src/*.php', 'src/deep/x.php'));
        self::assertFalse(self::frozenPathMatches('src/*.php', 'src/deep/x.txt'));
        self::assertTrue(self::frozenPathMatches('src/**/x[\\]]y.php', 'src/x]y.php'));
        self::assertTrue(self::frozenLegacyPathMatches('src/***', 'src_x'));
        // Rewrite 3 of the old predicate deletes `/**` outright, so the fallback
        // agrees with the resolved dialect here — which is exactly why it cannot
        // be replaced by a bare `fnmatch()` call.
        self::assertTrue(self::frozenLegacyPathMatches('src/**', 'src'));
        self::assertFalse(self::frozenLegacyPathMatches('src/**', 'lib'));
        self::assertTrue(self::frozenLegacyPathMatches('**', '/etc/passwd'));
        self::assertSame('#\A(?:[^/]++/)*+[^/]*\.php\z#', self::frozenStrictTriggerPattern('**/*.php'));
        self::assertSame('#\A[^/]*\.php\z#', self::frozenStrictTriggerPattern('*.php'));
        self::assertFalse(self::frozenStrictTriggerMatches('src/*.php', 'src/deep/x.php'));
        self::assertFalse(self::frozenStrictTriggerMatches('src/**', 'src'));
        self::assertTrue(self::frozenStrictTriggerMatches('[a-z].php', '[a-z].php'));
        self::assertTrue(self::frozenStrictTriggerMatches('**/*.php', 'src/a.php'));
        // A LEADING `**\/` was already zero-or-more segments in the strict
        // dialect, so it claimed root files there too — table row #31 records
        // YES on both sides, and this is the pair that shows why.
        self::assertTrue(self::frozenStrictTriggerMatches('**/*.php', 'a.php'));
        self::assertTrue(self::frozenStrictTriggerMatches('*', 'a'));
        self::assertFalse(self::frozenStrictTriggerMatches('*', 'a/b'));
    }

    public function testTheSharedCompilerKeepsTheThirdAnswerAndNeitherCallerCollapsesIt(): void
    {
        $uncompilable = PathGlob::compile('a[\\]]b');
        self::assertFalse(@preg_match($uncompilable, 'a]b'));
        self::assertNull(PathGlob::matchCompiled($uncompilable, 'a]b'));
        self::assertTrue(PathGlob::matchCompiled(PathGlob::compile('a*'), 'abc'));
        self::assertFalse(PathGlob::matchCompiled(PathGlob::compile('a*'), 'xyz'));

        self::assertSame(
            self::frozenCompile('src/**\/*.php'),
            PathGlob::compile('src/**\/*.php'),
            'the escaped-globstar spelling a doc-block needs must compile the same as any other',
        );
        self::assertSame(PathGlob::compile('src/*.php'), PathGlob::compile('src/*.php'), 'compile() is not a pure function of its argument');
        self::assertSame('#^a\.b$#Ds', PathGlob::compile('a.b'));
        self::assertFalse(PathGlob::matchCompiled(PathGlob::compile('a.b'), 'axb'));
        self::assertTrue(PathGlob::matchCompiled(PathGlob::compile('a.b'), 'a.b'));
    }

    /**
     * Every family the dialect claims, answered through the shipped matcher and
     * the transcribed one in the same breath — the small, exact version of the
     * corpus claim, so a reader can see the values rather than a pair count.
     */
    public function testRepresentativePairsOfEveryFamily(): void
    {
        $pairs = [
            ['**/*.php', 'a.php', true],
            ['**/*.php', 'src/a.php', true],
            ['*/tests/**/*.php', 'a/b/tests/FooTest.php', true],
            ['src/**', 'src', true],
            ['src/*.php', 'src/deep/x.php', true],
            ['src/**/test.php', 'src/test.php', true],
            ['[a-z].php', 'a.php', true],
            ['[a-z].php', '1.php', false],
            ['[!a].php', 'b.php', true],
            ['[[:alpha:]].php', 'a.php', true],
            ['a\\*b', 'a*b', true],
            ['a\\*b', 'aXb', false],
            ['{a,b}.php', 'a.php', false],
            ['{a,b}.php', '{a,b}.php', true],
            ['src/?.php', 'src/x.php', true],
            ['src/?.php', 'src/xy.php', false],
            ['SRC/*.php', 'src/a.php', false],
            ['*.php', "a\nb.php", true],
            ['src.php', "src.php\n", false],
            ['src/***', 'src_x', true],
            ['*', 'a/b.php', true],
            ['./src/*', 'src/a.php', false],
            ['./src/*', './src/a.php', true],
            ['**', '/etc/passwd', true],
            ['docs/**', 'src/a.php', false],
        ];

        $differences = [];
        foreach ($pairs as [$glob, $path, $expected]) {
            $skill = SkillRegistry::pathMatches($glob, $path);
            $rule = PathTrigger::new([$glob])->matches($path);
            $before = self::frozenPathMatches($glob, $path);
            if ($skill !== $expected || $rule !== $expected || $before !== $expected) {
                $differences[] = sprintf(
                    '%s ~ %s : expected %s, skill %s, rule %s, before %s',
                    self::say($glob),
                    self::say($path),
                    $expected ? 'YES' : 'NO',
                    $skill ? 'YES' : 'NO',
                    $rule ? 'YES' : 'NO',
                    $before ? 'YES' : 'NO',
                );
            }
        }

        self::assertSame([], $differences, sprintf('%d representative pairs are wrong', count($differences)));
    }

    /**
     * Every glob the repository speaks, plus the sweep.
     *
     * @return list<string>
     */
    public static function patternCorpus(): array
    {
        return array_keys(self::corpus()[0]);
    }

    /**
     * Every path-shaped string the repository speaks, plus the sweep.
     *
     * @return list<string>
     */
    public static function pathCorpus(): array
    {
        return array_keys(self::corpus()[1]);
    }

    /**
     * @return array{0: array<string,true>, 1: array<string,true>}
     */
    private static function corpus(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $patterns = [];
        $paths = [];
        $admit = static function (string $value) use (&$patterns, &$paths): void {
            if ($value !== '') {
                $patterns[$value] = true;
                $paths[$value] = true;
            }
        };

        foreach (self::Q3_ROWS as [, , $glob, $path]) {
            $patterns[$glob] = true;
            $paths[$path] = true;
        }
        foreach (self::SWEEP_PATTERNS as $glob) {
            $patterns[$glob] = true;
        }
        foreach (self::SWEEP_PATHS as $path) {
            $paths[$path] = true;
            if ($path !== '') {
                $patterns[$path] = true;
            }
        }

        foreach (self::harvest() as $file) {
            $source = (string) file_get_contents($file);
            if (str_ends_with($file, '.php')) {
                foreach (token_get_all($source) as $token) {
                    if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && self::isGlobShaped($literal = self::unquote($token[1]))) {
                        $admit($literal);
                    }
                }
            }
            foreach (self::frontmatterPaths($source) as $value) {
                $admit($value);
            }
        }

        ksort($patterns);
        ksort($paths);
        $cache = [$patterns, $paths];

        return $cache;
    }

    /**
     * @return list<string>
     */
    private static function harvest(): array
    {
        $root = \dirname(__DIR__, 2);
        $found = [];
        foreach (self::HARVEST_DIRS as $dir) {
            $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($walker as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                if (str_ends_with($name, '.php') || $name === 'SKILL.md') {
                    $found[] = $file->getPathname();
                }
            }
        }
        sort($found);

        return $found;
    }

    private static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'"
            ? str_replace(["\\\\", "\\'"], ['\\', "'"], $body)
            : stripcslashes($body);
    }

    /**
     * What counts as a glob someone might have meant: it carries a wildcard, it
     * is short, and it holds no whitespace or interpolation — the shape of a
     * `paths:` entry and not the shape of a sentence about one.
     */
    private static function isGlobShaped(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 64
            && strpbrk($value, '*?') !== false
            && strpbrk($value, "\n\t\"' ,;()$") === false;
    }

    /**
     * The `paths:` entries of a frontmatter block, in both spellings the tree
     * uses: an inline flow list and a block sequence.
     *
     * @return list<string>
     */
    private static function frontmatterPaths(string $source): array
    {
        $out = [];
        if (preg_match_all('/paths:\s*\[([^\]]*)\]/', $source, $m) > 0) {
            foreach ($m[1] as $inner) {
                foreach (explode(',', $inner) as $one) {
                    $one = trim(trim($one), "\"'");
                    if ($one !== '') {
                        $out[] = $one;
                    }
                }
            }
        }
        if (preg_match_all('/paths:\s*\n((?:[ \t]*-[ \t]*\S.*\n?)+)/m', $source, $m) > 0) {
            foreach ($m[1] as $block) {
                foreach (preg_split('/\R/', trim($block)) ?: [] as $line) {
                    $one = trim(trim((string) preg_replace('/^[ \t]*-[ \t]*/', '', $line), ''), "\"'");
                    if ($one !== '') {
                        $out[] = $one;
                    }
                }
            }
        }

        return $out;
    }

    private static function outcome(callable $answer): string
    {
        try {
            return $answer() ? '1' : '0';
        } catch (ValueError $e) {
            return 'E:' . $e::class;
        }
    }

    private static function say(string $value): string
    {
        return "'" . addcslashes($value, "\0..\37\177..\377'\\") . "'";
    }

    // =====================================================================
    //  FROZEN TRANSCRIPTIONS — the code this step replaced, copied in so the
    //  harness can disagree with it. NOT a call into production: an oracle that
    //  reaches the code under test agrees with it by construction.
    //
    //  Provenance of each block, and how to re-take it:
    //    git show master:sugar-crush/src/Skills/SkillRegistry.php  (the two
    //    compile methods, at f3134703f) and
    //    git show master:sugar-crush/src/Context/Triggers/PathTrigger.php
    //    (the strict pattern() this step deleted). Renamed; doc-blocks dropped;
    //    no other edit. Their discriminating pairs are re-asserted in
    //    testEachFrozenOracleStillAnswersThePairItWasTakenFor().
    // =====================================================================
    private static function frozenCompile(string $pattern): string
    {
        $out = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];

            // fnmatch() honours backslash escapes unless FNM_NOESCAPE is
            // passed, and this call site never passed it. VERIFIED on PHP
            // 8.3.6: fnmatch('a\*b', 'a*b') is true and fnmatch('a\*b', 'aXb')
            // is false.
            if ($ch === '\\' && $i + 1 < $len) {
                $out .= preg_quote($pattern[++$i], '#');
                continue;
            }

            // `/**` — the whole point of this translation. Zero-or-more
            // `/segment` groups, so `a/**/b` claims `a/b` as well as
            // `a/x/y/b`, and `a/**` claims `a` itself.
            if ($ch === '/' && substr($pattern, $i + 1, 2) === '**') {
                $j = $i + 3;
                while ($j < $len && $pattern[$j] === '*') {
                    ++$j;
                }

                // THREE OR MORE STARS AND THE SLASH GOES OPTIONAL TOO. Nobody
                // writes `src/***` on purpose, but the predicate this replaced
                // answered it, and answering it with less is a NARROWING. Its
                // rewrite 3 deleted the `/**` outright and left the extra star
                // behind, so `src/***` became `src*` and claimed `src_x`;
                // folding every star into one `(?:/.*)?` cannot match without
                // the slash. MEASURED on PHP 8.3.6: the old union for
                // `src/***` is exactly `^src.*$`, and for `src/***\/*.php`
                // exactly `src` + anything + `/` + anything + `.php` — both of
                // which the `.*` here reproduces, so this is the old answer and
                // not a fresh widening. TWO stars keep the separator
                // mandatory-or-absent, which is what a globstar means.
                $out .= ($j - ($i + 1)) > 2 ? '.*' : '(?:/.*)?';
                $i = $j - 1;
                continue;
            }

            if ($ch === '*' && substr($pattern, $i + 1, 1) === '*') {
                $j = $i + 1;
                while ($j < $len && $pattern[$j] === '*') {
                    ++$j;
                }

                // A LEADING `**/` — the case none of the three rewrites could
                // see, because each of them needed a slash in front of the
                // stars and at position 0 there is none. Zero-or-more leading
                // directories, so `**/*.php` finally claims `a.php`.
                if ($i === 0 && $j < $len && $pattern[$j] === '/') {
                    $out .= '(?:.*/)?';
                    $i = $j;
                    continue;
                }

                $out .= '.*';
                $i = $j - 1;
                continue;
            }

            if ($ch === '*') {
                $out .= '.*';
                continue;
            }

            if ($ch === '?') {
                $out .= '.';
                continue;
            }

            if ($ch === '[') {
                $close = $i + 1;
                if ($close < $len && ($pattern[$close] === '!' || $pattern[$close] === '^')) {
                    ++$close;
                }
                // A `]` in first position is a literal member, not the
                // terminator — the POSIX rule, and PHP's: fnmatch('[]]x', ']x')
                // is true on 8.3.6.
                if ($close < $len && $pattern[$close] === ']') {
                    ++$close;
                }
                while ($close < $len && $pattern[$close] !== ']') {
                    // A POSIX CLASS CARRIES A `]` OF ITS OWN, and a scan that
                    // does not know that stops on it. `[[:alpha:]]` then
                    // yielded the body `[:alpha:` and the emitted class ran on
                    // past its own terminator. That was tolerable exactly while
                    // the result failed to COMPILE — PCRE refused
                    // `#^[[:alpha:]\]x$#Ds` and the pattern routed to
                     // {@see \SugarCraft\Crush\Skills\SkillRegistry::legacyPathMatch()}. It is not tolerable when a
                    // LATER `[` in the pattern supplies the missing `]`:
                    // MEASURED on PHP 8.3.6, `[[:alpha:]][!a]` emitted
                    // `#^[[:alpha:]\][^a]$#Ds`, which compiles, swallows the
                    // second group into the first class, and answers FALSE for
                    // `ab` where `fnmatch()` answers true. A silently wrong
                    // answer, with no fallback, from a supported shape.
                    if ($pattern[$close] === '[' && substr($pattern, $close + 1, 1) === ':') {
                        $classEnd = strpos($pattern, ':]', $close + 2);
                        if ($classEnd !== false) {
                            $close = $classEnd + 2;
                            continue;
                        }
                    }

                    ++$close;
                }

                if ($close >= $len) {
                    // Unterminated: fnmatch treats the bracket as a literal
                    // (fnmatch('a[b', 'a[b') is true on 8.3.6), so this does
                    // too, and the rest of the pattern keeps translating.
                    $out .= '\\[';
                    continue;
                }

                $body = substr($pattern, $i + 1, $close - $i - 1);
                if ($body !== '' && $body[0] === '!') {
                    $body = '^' . substr($body, 1);
                }
                $out .= '[' . self::frozenClassBody($body) . ']';
                $i = $close;
                continue;
            }

            $out .= preg_quote($ch, '#');
        }

        // /D so a TRAILING newline in a path cannot satisfy `$`; /s so an
        // EMBEDDED one cannot defeat a wildcard. The two are independent and
        // both are load-bearing: `fnmatch()`'s `*` and `?` match a newline like
        // any other byte, while PCRE's `.` refuses to without /s. MEASURED on
        // PHP 8.3.6: `fnmatch('*.php', "a\nb.php")` is TRUE and without /s
        // this translation answered false — a narrowing, on a path shape POSIX
        // genuinely permits. Neither modifier substitutes for the other, and
        // {@see \SugarCraft\Crush\Tests\Skills\SkillPathPatternTest} pins
        // one case for each.
        return '#^' . $out . '$#Ds';
    }

    private static function frozenClassBody(string $body): string
    {
        $len = strlen($body);
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];

            if ($ch === '\\') {
                if ($i + 1 >= $len) {
                    return str_replace('#', '\\#', $body);
                }

                $literal = $body[++$i];
                $out .= ctype_alnum($literal) ? $literal : '\\' . $literal;
                continue;
            }

            $out .= $ch === '#' ? '\\#' : $ch;
        }

        return $out;
    }
    /**
     * The old predicate, transcription of
     * {@see \SugarCraft\Crush\Skills\SkillRegistry::legacyPathMatch()} — which
     * this step left in place untouched, so the two must agree on everything.
     */
    private static function frozenLegacyPathMatches(string $pattern, string $path): bool
    {
        if (fnmatch($pattern, $path)) {
            return true;
        }

        if (!str_contains($pattern, '**')) {
            return false;
        }

        return fnmatch(str_replace('/**/', '/*/', $pattern), $path)
            || fnmatch(str_replace('/**', '/*', $pattern), $path)
            || fnmatch(str_replace('/**', '', $pattern), $path);
    }

    /**
     * What `pathMatches()` answered on `master`: the frozen translation, and the
     * frozen predicate when PCRE refuses the translation. Note that this is the
     * structure of the shipped method and not a simplification of it — the
     * fallback is reached on exactly the same third condition
     * ({@see PathGlob::matchCompiled()}) and the same `@` suppression covers it.
     */
    private static function frozenPathMatches(string $pattern, string $path): bool
    {
        static $cache = [];
        $regex = $cache[$pattern] ??= self::frozenCompile($pattern);
        $result = @preg_match($regex, $path);

        return $result === false ? self::frozenLegacyPathMatches($pattern, $path) : $result === 1;
    }

    /**
     * The compiler `PathTrigger::pattern()` carried before this step — a
     * segment-scoped `*`, a `?` that refused the separator, `[…]` and `{…}` as
     * literals, and no backslash escapes. Kept because the 33-row table's
     * "before" column is a claim about THIS code, and a claim about deleted code
     * that nothing re-derives is a claim nobody can check.
     */
    private static function frozenStrictTriggerPattern(string $glob): string
    {
        $body = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*') {
                if ($i + 1 < $length && $glob[$i + 1] === '*') {
                    $i++;
                    if ($i + 1 < $length && $glob[$i + 1] === '/') {
                        $i++;
                        $body = $body . '(?:[^/]++/)*+';
                    } else {
                        $body .= '.*';
                    }
                } else {
                    $body = $body . '[^/]*';
                }
                continue;
            }

            if ($char === '?') {
                $body .= '[^/]';
                continue;
            }

            $body .= preg_quote($char, '#');
        }

        return '#\A' . $body . '\z#';
    }

    private static function frozenStrictTriggerMatches(string $glob, string $path): bool
    {
        return preg_match(self::frozenStrictTriggerPattern($glob), $path) === 1;
    }

}
