<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * E85 — what a `paths:` frontmatter glob actually claims.
 *
 * {@see SkillRegistry::pathMatches()} replaced three hand-rolled
 * `str_replace()` rewrites of `**` with a real pattern-to-PCRE translation.
 * The rewrites were each keyed on a SLASH BEFORE the stars, so a pattern
 * STARTING with `**` matched none of them and fell through to a bare
 * `fnmatch()` that reads `**\/` as "some characters, then a literal slash" —
 * which is why `**\/*.php` did not claim `a.php`.
 *
 * THE POINT OF THIS FILE IS THE TABLE, not the named cases. A translation that
 * silently stops matching something the rewrites matched is a regression that
 * no individual example would catch, so the OLD predicate's full answer over
 * {@see PATTERNS} x {@see PATHS} was captured BEFORE the change and is carried
 * forward here verbatim as {@see BEFORE}. {@see AFTER} is the same grid under
 * the translation. The suite asserts three things about them: AFTER is what
 * the shipped code returns, AFTER is a strict SUPERSET of BEFORE, and every
 * gain is attributable to a named hole BY CAUSE — so a future edit that widens
 * the matcher somewhere else has to say so here.
 *
 * AND A GREEN GRID IS NOT THE SAME AS A CORRECT MATCHER, which this file
 * learned the hard way. The first version of it was 42 patterns x 50 paths =
 * 2,100 pairs and green, and a seeded differential fuzz then found THREE
 * families where the translation matched LESS than the predicate it replaced.
 * Not one of the three was a missing pattern:
 *
 *  - A newline inside a path. No path in the original 50 contained one, so the
 *    grid was blind by construction.
 *  - `src/***`. Already a pattern ROW — none of the 50 paths could distinguish
 *    `src(?:/.*)?` from the old `src*`, because none of them started with
 *    `src` and then continued with something other than `/`.
 *  - `[\d]`, a PCRE escape inside a class body. Needed a new row AND paths on
 *    both sides of the divergence.
 *
 * All three are closed in {@see SkillRegistry}, and the grid's alphabet was
 * widened by exactly the four paths and three patterns needed to SEE them and
 * to make the gain classifier falsifiable, so it is now 45 x 54 = 2,430 pairs.
 * The lesson is recorded rather than the fix alone: when an assertion misses a
 * defect, suspect its WINDOW before you suspect the defect's relevance.
 *
 * MEASURED on PHP 8.3.6 (this box has only 8.3; CI also runs 8.4). Everything
 * the table encodes is `fnmatch()`/PCRE behaviour, both of which are stable
 * across those two, but the version is on the record because `fnmatch()` is a
 * libc passthrough and the grid is a stdlib-behaviour claim.
 */
#[CoversClass(SkillRegistry::class)]
final class SkillPathPatternTest extends TestCase
{
    /**
     * Column order for {@see BEFORE} and {@see AFTER}.
     *
     * @var list<string>
     */
    private const PATHS = [
            'a.php', 'a.md', 'src/a.php', 'src/deep/a.php', 'src/x/y/a.php', 'src', 'src/', 'a', 'a/b', 'a/x/b',
            'ab', 'a/xb', 'a.php/b.php', 'docs/a.md', 'docs/x/a.md', 'tests/x/yTest.php', '/abs/x/a.php',
            '/abs/a.php', 'a/b/c', 'abc', 'a/c', 'abd', 'a*b', 'aXb', 'src/x/z/y/q/a.go', 'node_modules/x/y.js',
            'p/node_modules/y.js', '', 'x_test.php', 'q/x_test.php', 'a[b', 'a]b', 'a.b+c(d).php', 'x.php',
            'q/x.php', 'src/a', 'src/a/b', 'a/b/c/d', 'x/', 'x/y/', '.hidden/z.py', '.hidden/q/z.py', 'a{b,c}.php',
            'ab.php', 'a|b.php', 'a$b^c.php', 'ünï/f.php', 'ünï/q/f.php', '**/*.php', 'a/b/**',
            // ADDED THIS ROUND, one per narrowing family the 50 above could not
            // see. Each was found by a seeded differential fuzz AFTER this grid
            // was green, and each is a WINDOW defect rather than a missing
            // pattern: `src/***` was already a row below, and `[\d]x` needed a
            // path on both sides of the divergence. See the class doc-block.
            'src_x', "a\nb.php", 'dx', '5x',
    ];

    /**
     * Row order for {@see BEFORE} and {@see AFTER}.
     *
     * @var list<string>
     */
    private const PATTERNS = [
            '*.php', '*.md', 'src/*.php', 'src/**/*.php', '**/*.php', '**/*_test.php', 'src/**', '**', '**/',
            'docs/**/*.md', 'a/**/b', 'a/**b', 'a**b', '**.php', 'src/**/**/*.php', 'tests/**/*Test.php',
            '/abs/**/*.php', 'a/b/**', 'a?c', 'a[bc]d', 'a\\*b', 'src/*', 'src/**/x/**/*.go', '**/node_modules/**',
            'a[b', 'a]b', 'a[!x]b', 'a[]]b', 'a.b+c(d).php', 'a\\[b', '***/*.php', 'src/***', 'a/**/**/b', '*/**',
            '**/*', 'x/**/', '.hidden/**/*.py', 'a{b,c}.php', 'a|b.php', 'a$b^c.php', 'ünï/**/*.php', '**/x/**',
            // `**\/a?c` earns its row by combining a `**` PREFIX with a second
            // widenable mechanism, which is what makes
            // testEveryPairTheTranslationGains...() falsifiable: a widening in
            // `?` lands on a pattern the old prefix-based classifier would
            // have labelled "leading globstar" and waved through.
            '[\\d]x', 'a/***', '**/a?c',
    ];

    /**
     * The predicate this change REPLACED, frozen.
     *
     * Generated by running the pre-change body of `getForPaths()` — bare
     * `fnmatch($pattern, $path)`, then, when the pattern contained `**`, the
     * three rewrites `/**\/`->`/*\/`, `/**`->`/*` and `/**`->`''` ORed
     * together — over {@see PATTERNS} x {@see PATHS} at 8416d98e on PHP 8.3.6.
     * 326 of the 2,430 pairs matched.
     *
     * @var array<string, string>
     */
    private const BEFORE = [
            '*.php'              => '101110000000100111000000000011001110000000111111100100',
            '*.md'               => '010000000000011000000000000000000000000000000000000000',
            'src/*.php'          => '001110000000000000000000000000000000000000000000000000',
            'src/**/*.php'       => '001110000000000000000000000000000000000000000000000000',
            '**/*.php'           => '001110000000100111000000000001000010000000000011100000',
            '**/*_test.php'      => '000000000000000000000000000001000000000000000000000000',
            'src/**'             => '001111100000000000000000100000000001100000000000000000',
            '**'                 => '111111111111111111111111111111111111111111111111111111',
            '**/'                => '000000100000000000000000000000000000001100000000000000',
            'docs/**/*.md'       => '000000000000011000000000000000000000000000000000000000',
            'a/**/b'             => '000000001100000000000000000000000000000000000000000000',
            'a/**b'              => '000000001111000000000000000000000000000000000000000000',
            'a**b'               => '000000001111000000000011000000110000000000000000000000',
            '**.php'             => '101110000000100111000000000011001110000000111111100100',
            'src/**/**/*.php'    => '001110000000000000000000000000000000000000000000000000',
            'tests/**/*Test.php' => '000000000000000100000000000000000000000000000000000000',
            '/abs/**/*.php'      => '000000000000000011000000000000000000000000000000000000',
            'a/b/**'             => '000000001000000000100000000000000000010000000000010000',
            'a?c'                => '000000000000000000011000000000000000000000000000000000',
            'a[bc]d'             => '000000000000000000000100000000000000000000000000000000',
            'a\\*b'              => '000000000000000000000010000000000000000000000000000000',
            'src/*'              => '001110100000000000000000100000000001100000000000000000',
            'src/**/x/**/*.go'   => '000000000000000000000000100000000000000000000000000000',
            '**/node_modules/**' => '000000000000000000000000001000000000000000000000000000',
            'a[b'                => '000000000000000000000000000000100000000000000000000000',
            'a]b'                => '000000000000000000000000000000010000000000000000000000',
            'a[!x]b'             => '000000001000000000000011000000110000000000000000000000',
            'a[]]b'              => '000000000000000000000000000000010000000000000000000000',
            'a.b+c(d).php'       => '000000000000000000000000000000001000000000000000000000',
            'a\\[b'              => '000000000000000000000000000000100000000000000000000000',
            '***/*.php'          => '001110000000100111000000000001000010000000000011100000',
            'src/***'            => '001111100000000000000000100000000001100000000000001000',
            'a/**/**/b'          => '000000001000000000000000000000000000000000000000000000',
            '*/**'               => '111111111111111111111111111111111111111111111111111111',
            '**/*'               => '001110101101111111101000111001000011111111000011110000',
            'x/**/'              => '000000000000000000000000000000000000001100000000000000',
            '.hidden/**/*.py'    => '000000000000000000000000000000000000000011000000000000',
            'a{b,c}.php'         => '000000000000000000000000000000000000000000100000000000',
            'a|b.php'            => '000000000000000000000000000000000000000000001000000000',
            'a$b^c.php'          => '000000000000000000000000000000000000000000000100000000',
            'ünï/**/*.php'     => '000000000000000000000000000000000000000000000011000000',
            '**/x/**'            => '000010000100001110000000110000000000000000000000000000',
            '[\\d]x'             => '000000000000000000000000000000000000000000000000000010',
            'a/***'              => '110000011111100000111111000000111000010000111100010100',
            '**/a?c'             => '000000000000000000000000000000000000000000000000000000',
    ];

    /**
     * What the shipped translation returns over the same grid: 331 pairs.
     *
     * @var array<string, string>
     */
    private const AFTER = [
            '*.php'              => '101110000000100111000000000011001110000000111111100100',
            '*.md'               => '010000000000011000000000000000000000000000000000000000',
            'src/*.php'          => '001110000000000000000000000000000000000000000000000000',
            'src/**/*.php'       => '001110000000000000000000000000000000000000000000000000',
            '**/*.php'           => '101110000000100111000000000011001110000000111111100100',
            '**/*_test.php'      => '000000000000000000000000000011000000000000000000000000',
            'src/**'             => '001111100000000000000000100000000001100000000000000000',
            '**'                 => '111111111111111111111111111111111111111111111111111111',
            '**/'                => '000000100000000000000000000100000000001100000000000000',
            'docs/**/*.md'       => '000000000000011000000000000000000000000000000000000000',
            'a/**/b'             => '000000001100000000000000000000000000000000000000000000',
            'a/**b'              => '000000001111000000000000000000000000000000000000000000',
            'a**b'               => '000000001111000000000011000000110000000000000000000000',
            '**.php'             => '101110000000100111000000000011001110000000111111100100',
            'src/**/**/*.php'    => '001110000000000000000000000000000000000000000000000000',
            'tests/**/*Test.php' => '000000000000000100000000000000000000000000000000000000',
            '/abs/**/*.php'      => '000000000000000011000000000000000000000000000000000000',
            'a/b/**'             => '000000001000000000100000000000000000010000000000010000',
            'a?c'                => '000000000000000000011000000000000000000000000000000000',
            'a[bc]d'             => '000000000000000000000100000000000000000000000000000000',
            'a\\*b'              => '000000000000000000000010000000000000000000000000000000',
            'src/*'              => '001110100000000000000000100000000001100000000000000000',
            'src/**/x/**/*.go'   => '000000000000000000000000100000000000000000000000000000',
            '**/node_modules/**' => '000000000000000000000000011000000000000000000000000000',
            'a[b'                => '000000000000000000000000000000100000000000000000000000',
            'a]b'                => '000000000000000000000000000000010000000000000000000000',
            'a[!x]b'             => '000000001000000000000011000000110000000000000000000000',
            'a[]]b'              => '000000000000000000000000000000010000000000000000000000',
            'a.b+c(d).php'       => '000000000000000000000000000000001000000000000000000000',
            'a\\[b'              => '000000000000000000000000000000100000000000000000000000',
            '***/*.php'          => '101110000000100111000000000011001110000000111111100100',
            'src/***'            => '001111100000000000000000100000000001100000000000001000',
            'a/**/**/b'          => '000000001100000000000000000000000000000000000000000000',
            '*/**'               => '111111111111111111111111111111111111111111111111111111',
            '**/*'               => '111111111111111111111111111111111111111111111111111111',
            'x/**/'              => '000000000000000000000000000000000000001100000000000000',
            '.hidden/**/*.py'    => '000000000000000000000000000000000000000011000000000000',
            'a{b,c}.php'         => '000000000000000000000000000000000000000000100000000000',
            'a|b.php'            => '000000000000000000000000000000000000000000001000000000',
            'a$b^c.php'          => '000000000000000000000000000000000000000000000100000000',
            'ünï/**/*.php'     => '000000000000000000000000000000000000000000000011000000',
            '**/x/**'            => '000010000100001110000000110000000000001100000000000000',
            '[\\d]x'             => '000000000000000000000000000000000000000000000000000010',
            'a/***'              => '110000011111100000111111000000111000010000111100010100',
            '**/a?c'             => '000000000000000000011000000000000000000000000000000000',
    ];

    public function testTheShippedMatcherReturnsExactlyTheCharacterisedTable(): void
    {
        $actual = [];
        foreach (self::PATTERNS as $pattern) {
            $bits = '';
            foreach (self::PATHS as $path) {
                $bits .= SkillRegistry::pathMatches($pattern, $path) ? '1' : '0';
            }
            $actual[$pattern] = $bits;
        }

        self::assertSame(self::AFTER, $actual);
    }

    /**
     * The regression guard the brief asked for: no pair IN THIS GRID that the
     * three rewrites matched may stop matching.
     *
     * NAMED FOR THE GRID ON PURPOSE. An earlier name — `...NeverNarrowsWhat
     * TheOldRewritesMatched` — read as a universal, and it is not one: it is a
     * statement about {@see PATTERNS} x {@see PATHS} and nothing else. Three
     * narrowing families lived outside that window while this assertion was
     * green (see the class doc-block). The universal version of the claim is
     * carried by a seeded differential fuzz, whose generator is written down
     * in {@see SkillRegistry::pathMatches()}; this is the part of it that is
     * cheap enough to run every suite.
     *
     * Asserted SEPARATELY from the table above even though the table already
     * encodes it, because the two fail with different messages. A changed
     * table says "the grid moved"; this says WHICH pattern lost WHICH path,
     * which is the only thing a reader needs to decide whether the narrowing
     * was intended.
     */
    public function testNoPairInTheFrozenGridStoppedMatching(): void
    {
        $lost = [];
        foreach (self::PATTERNS as $pattern) {
            foreach (self::PATHS as $i => $path) {
                if (self::BEFORE[$pattern][$i] !== '1') {
                    continue;
                }
                if (!SkillRegistry::pathMatches($pattern, $path)) {
                    $lost[] = sprintf('%s <- %s', var_export($pattern, true), var_export($path, true));
                }
            }
        }

        self::assertSame([], $lost, 'the pattern translation stopped matching paths the old rewrites claimed');
    }

    /**
     * And the widening is exactly the two holes, not a blank cheque.
     *
     * Every pair the translation gains over the old predicate must be
     * attributable to a named defect in the rewrites. There are TWO, and the
     * second was found by this assertion rather than by the brief:
     *
     *  - LEADING GLOBSTAR. Each rewrite needed a slash in front of the stars,
     *    and at position 0 there is none, so `**\/*.php` never reached any of
     *    them.
     *  - ADJACENT GLOBSTARS. `str_replace('/**\/', '/*\/', 'a/**\/**\/b')`
     *    consumes the trailing slash of the first match, so the scan resumes
     *    PAST the slash the second `/**\/` needed and only the first is
     *    rewritten: the result is `a/*\/**\/b`, which claims `a/x/y/b` but not
     *    `a/x/b`. Non-overlapping replacement, not a missing case — which is
     *    why reading the three rewrites as a list of cases does not find it.
     *
     * A gain outside both means the translation changed a semantic nobody
     * decided to change.
     */
    public function testEveryPairTheTranslationGainsComesFromANamedHoleInTheRewrites(): void
    {
        $gained = [];
        foreach (self::PATTERNS as $pattern) {
            foreach (self::PATHS as $i => $path) {
                if (self::BEFORE[$pattern][$i] === '1' || !SkillRegistry::pathMatches($pattern, $path)) {
                    continue;
                }
                $gained[] = [$pattern, $path];
            }
        }

        self::assertNotSame([], $gained, 'nothing widened at all — E85 is not fixed');

        $holes = [];
        foreach ($gained as [$pattern, $path]) {
            // CLASSIFIED BY CAUSE, NOT BY PREFIX. This used to read
            // `str_starts_with($pattern, '**')`, which labels a gain by where
            // its stars happen to sit rather than by what produced it: a
            // future widening in `?`, `*` or the bracket scanner that happened
            // to land on a `**`-prefixed pattern would have been waved through
            // under a name that did not describe it. Each arm below instead
            // REPAIRS the pattern — removes the defect the hole is named for —
            // and requires the OLD predicate to claim the path once it is
            // gone. That is what "this gain came from that hole" means, and it
            // is a claim a mislabelled gain cannot satisfy.
            $withoutLeadingGlobstar = (string) preg_replace('#^\*+/#', '', $pattern);
            $withoutAdjacentGlobstars = str_replace('/**/**/', '/**/', $pattern);

            $hole = match (true) {
                $withoutLeadingGlobstar !== $pattern
                    && self::oldPredicate($withoutLeadingGlobstar, $path) => 'leading globstar',
                $withoutAdjacentGlobstars !== $pattern
                    && self::oldPredicate($withoutAdjacentGlobstars, $path) => 'adjacent globstars',
                default => 'UNCLASSIFIED',
            };
            $holes[$hole] = true;

            self::assertNotSame(
                'UNCLASSIFIED',
                $hole,
                sprintf(
                    'pattern %s newly claims %s, and repairing either named hole in it does not make the '
                    . 'old predicate claim that path either — so the translation widened something other '
                    . 'than what E85 is about',
                    var_export($pattern, true),
                    var_export($path, true),
                ),
            );
        }

        // Both holes are still exercised by the grid; dropping the pattern that
        // covers one would otherwise leave this test green over half a fix.
        self::assertSame(
            ['adjacent globstars', 'leading globstar'],
            $this->sorted(array_keys($holes)),
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * The headline case, stated once in the open so it survives a rewrite of
     * the table: the first form most people write.
     */
    public function testALeadingGlobstarClaimsFilesAtTheRootOfTheTree(): void
    {
        self::assertTrue(SkillRegistry::pathMatches('**/*.php', 'a.php'));
        self::assertTrue(SkillRegistry::pathMatches('**/*.php', 'src/a.php'));
        self::assertTrue(SkillRegistry::pathMatches('**/*.php', 'src/deep/a.php'));

        // MEASURED on PHP 8.3.6: this is what the bare fnmatch() the old code
        // fell through to answers for the first of the three, and it is the
        // whole defect.
        self::assertFalse(fnmatch('**/*.php', 'a.php'));
    }

    /**
     * `/**` still spans ZERO directories, which is what the third rewrite
     * bought and the easiest thing for a regex translation to lose.
     */
    public function testAnInteriorGlobstarStillSpansZeroDirectories(): void
    {
        self::assertTrue(SkillRegistry::pathMatches('src/**/*.php', 'src/a.php'));
        self::assertTrue(SkillRegistry::pathMatches('src/**/*.php', 'src/deep/a.php'));
        self::assertTrue(SkillRegistry::pathMatches('a/**', 'a'));
        self::assertTrue(SkillRegistry::pathMatches('a/**', 'a/b/c'));
    }

    /**
     * A single `*` still crosses `/`, because the call site never passed
     * `FNM_PATHNAME` and narrowing it would drop matches that hold today.
     *
     * VERIFIED on PHP 8.3.6, both branches, so the claim is not taken on
     * trust: without flags `fnmatch('*.php', 'src/a.php')` is true, with
     * `FNM_PATHNAME` it is false.
     */
    public function testASingleStarStillCrossesTheSeparator(): void
    {
        self::assertTrue(fnmatch('*.php', 'src/a.php'));
        self::assertFalse(fnmatch('*.php', 'src/a.php', FNM_PATHNAME));

        self::assertTrue(SkillRegistry::pathMatches('*.php', 'src/a.php'));
        self::assertTrue(SkillRegistry::pathMatches('src/*', 'src/x/y/a.php'));
        self::assertTrue(SkillRegistry::pathMatches('a?c', 'a/c'));
    }

    /**
     * The pattern is ANCHORED at both ends, which `fnmatch()` is and a regex
     * is not unless you say so.
     */
    public function testTheTranslationIsAnchoredAtBothEnds(): void
    {
        self::assertFalse(SkillRegistry::pathMatches('src/a.php', 'x/src/a.php'));
        self::assertFalse(SkillRegistry::pathMatches('src/a.php', 'src/a.phpx'));
        // /D, so a trailing newline cannot satisfy `$`.
        self::assertFalse(SkillRegistry::pathMatches('src/a.php', "src/a.php\n"));
    }

    /**
     * A pattern the translation cannot compile is answered by the FULL old
     * predicate — the three rewrites included — not by a bare `fnmatch()`, and
     * not by `false`.
     *
     * The case is not hypothetical and not malformed input. `fnmatch()`
     * inherits POSIX character classes from libc, so `[[:alpha:]]` is a
     * supported `paths:` shape; the translation emits `#^[[:alpha:]\]x$#Ds`,
     * whose class never terminates, and PCRE refuses it. Answering false there
     * would narrow the matcher for every skill that uses one.
     *
     * MEASURED on PHP 8.3.6, and this is the discriminating pair: for
     * `src/**\/[[:alpha:]]*.php` against `src/abc.php`, bare `fnmatch()` is
     * FALSE and the old predicate is TRUE — the zero-directory rewrite is what
     * carries it. A fallback that dropped the rewrites would still be green on
     * every other assertion in this file.
     */
    public function testAPosixClassPatternFallsBackToTheFullOldPredicate(): void
    {
        // The premise: PCRE really does refuse what the translation emits.
        self::assertFalse(@preg_match('#^[[:alpha:]\]x$#Ds', 'ax'));
        // ...and fnmatch really does honour the class.
        self::assertTrue(fnmatch('[[:alpha:]]x', 'ax'));

        self::assertTrue(SkillRegistry::pathMatches('[[:alpha:]]x', 'ax'));
        self::assertFalse(SkillRegistry::pathMatches('[[:alpha:]]x', '1x'));

        // The discriminator. Bare fnmatch says false here; the rewrites say true.
        self::assertFalse(fnmatch('src/**/[[:alpha:]]*.php', 'src/abc.php'));
        self::assertTrue(SkillRegistry::pathMatches('src/**/[[:alpha:]]*.php', 'src/abc.php'));
        self::assertTrue(SkillRegistry::pathMatches('src/**/[[:alpha:]]*.php', 'src/x/abc.php'));
        self::assertFalse(SkillRegistry::pathMatches('src/**/[[:alpha:]]*.php', 'src/123.php'));
    }

    /**
     * A class that is malformed rather than merely untranslated still answers
     * rather than throwing.
     *
     * `[z-a]` is a reversed range: PCRE refuses it and `fnmatch()` answers
     * false, so the value is uninteresting — what is being pinned is that a
     * tool call touching a file does not blow up on someone else's frontmatter.
     */
    public function testAMalformedClassAnswersInsteadOfThrowing(): void
    {
        self::assertFalse(SkillRegistry::pathMatches('a[z-a]b', 'azb'));
        self::assertFalse(SkillRegistry::pathMatches('a[z-a]b', 'a[z-a]b'));
    }

    /**
     * And the fix is reachable through the production entry point, not only
     * through the static helper the table exercises.
     */
    public function testGetForPathsClaimsARootFileForALeadingGlobstarSkill(): void
    {
        $registry = new SkillRegistry();
        $registry->register(['php' => $this->skill('php', ['**/*.php'])]);

        self::assertSame(
            ['php'],
            array_map(static fn (Skill $s): string => $s->name, $registry->getForPaths(['a.php'])),
        );
        self::assertSame(
            [],
            array_map(static fn (Skill $s): string => $s->name, $registry->getForPaths(['a.md'])),
        );
    }

    /**
     * The three narrowing families a seeded differential fuzz found AFTER this
     * grid was green — each one now closed, and each one pinned here because
     * the grid alone did not notice any of them.
     *
     * (A) A NEWLINE INSIDE A PATH. `fnmatch()`'s `*` and `?` match `\n` like
     * any other byte; PCRE's `.` refuses to without /s. MEASURED on PHP 8.3.6.
     * Paths with newlines in them are legal on POSIX, and every assertion here
     * was FALSE before the /s went on.
     *
     * /s AND /D ARE INDEPENDENT and both are load-bearing, which is why the
     * last assertion is here rather than only in
     * {@see testTheTranslationIsAnchoredAtBothEnds()}: /s decides whether a
     * wildcard may cross an EMBEDDED newline, /D whether `$` may sit before a
     * TRAILING one. Dropping either one alone reds exactly one of the two.
     */
    public function testAWildcardCrossesANewlineInsideAPathJustAsFnmatchDoes(): void
    {
        self::assertTrue(fnmatch('*.php', "a\nb.php"), 'premise: fnmatch crosses the newline');
        self::assertTrue(SkillRegistry::pathMatches('*.php', "a\nb.php"));

        self::assertTrue(fnmatch('a?b', "a\nb"), 'premise: `?` matches a newline too');
        self::assertTrue(SkillRegistry::pathMatches('a?b', "a\nb"));

        self::assertTrue(SkillRegistry::pathMatches('**/*.php', "a\nb.php"));
        self::assertTrue(SkillRegistry::pathMatches('a/**', "a/b\nc"));

        // ...and /s does NOT hand back what /D takes away.
        self::assertFalse(SkillRegistry::pathMatches('src/a.php', "src/a.php\n"));
    }

    /**
     * (B) THREE OR MORE STARS AFTER A SLASH, where the old rewrite that
     * deleted `/**` outright left a residual star behind.
     *
     * `src/***` went to `src*` under rewrite 3 and claimed `src_x`; folding
     * every star into one `(?:/.*)?` cannot match without the slash, so the
     * translation dropped it. `src/***` IS one of {@see PATTERNS} — the grid
     * characterised the pattern and none of its paths could tell the two
     * readings apart. THE WINDOW, NOT THE COVERAGE.
     *
     * The two-star control is the important half of this test: a globstar
     * proper still requires the separator to be present or the whole segment
     * absent, and this must not have widened with the three-star case.
     */
    public function testAStarRunAfterASlashMakesTheSlashItselfOptional(): void
    {
        self::assertTrue(SkillRegistry::pathMatches('src/***', 'src_x'));
        self::assertTrue(SkillRegistry::pathMatches('src/***', 'src'));
        self::assertTrue(SkillRegistry::pathMatches('src/***', 'src/a/b'));
        self::assertTrue(SkillRegistry::pathMatches('src/***/*.php', 'src_x/a.php'));
        self::assertTrue(SkillRegistry::pathMatches('a/****', 'axyz'));

        // THE CONTROL. Two stars is a globstar and keeps the separator
        // mandatory-or-absent; the old predicate agrees, so widening this one
        // would be a fresh invention rather than a restored answer.
        self::assertFalse(fnmatch('src/**', 'src_x'), 'premise');
        self::assertFalse(self::oldPredicate('src/**', 'src_x'), 'premise: the rewrites did not claim it');
        self::assertFalse(SkillRegistry::pathMatches('src/**', 'src_x'));
        self::assertTrue(SkillRegistry::pathMatches('src/**', 'src'));
    }

    /**
     * (C) A PCRE ESCAPE INSIDE A CLASS BODY, which narrows and widens at once.
     *
     * MEASURED on PHP 8.3.6: `fnmatch()` reads `[\d]` as the single literal
     * `d` — `dx` matches, `5x` does not, and `\x` does not either, so the
     * backslash is not a member. Copied verbatim into a PCRE class it becomes
     * the digit escape and both answers invert.
     */
    public function testAnEscapeInsideAClassBodyIsALiteralAndNotAPcreClass(): void
    {
        self::assertTrue(fnmatch('[\d]x', 'dx'), 'premise: `\d` is the literal d');
        self::assertFalse(fnmatch('[\d]x', '5x'), 'premise: and not the digit class');
        self::assertFalse(fnmatch('[\d]x', "\\x"), 'premise: nor is the backslash a member');

        self::assertTrue(SkillRegistry::pathMatches('[\d]x', 'dx'));
        self::assertFalse(SkillRegistry::pathMatches('[\d]x', '5x'));
        self::assertFalse(SkillRegistry::pathMatches('[\d]x', "\\x"));

        // Ranges, negation and a first-position `]` are NOT re-escaped — they
        // mean the same thing to both engines, and quoting them breaks them.
        self::assertTrue(SkillRegistry::pathMatches('a[a-c]d', 'abd'));
        self::assertFalse(SkillRegistry::pathMatches('a[a-c]d', 'axd'));
        self::assertTrue(SkillRegistry::pathMatches('a[!x]b', 'aXb'));
        self::assertTrue(SkillRegistry::pathMatches('a[]]b', 'a]b'));
    }

    /**
     * The class body's `#` escape is load-bearing, and it takes a pattern the
     * FALLBACK answers differently to show it.
     *
     * `#` is this translation's regex delimiter. Unescaped, it ends the
     * pattern early, PCRE refuses the result, and {@see
     * SkillRegistry::pathMatches()} quietly answers with the old predicate
     * instead — which is why a naive test of `[#x]y` against `#y` stays green
     * either way: `fnmatch()` claims it too. The discriminator has to be a
     * pattern the OLD predicate gets wrong, so this one pairs the `#` with the
     * leading globstar E85 fixed.
     */
    public function testAHashInsideAClassBodyDoesNotEndTheCompiledPattern(): void
    {
        // The discriminator: bare fnmatch and the full old predicate both say
        // false, so a silent fall back to either is visible here.
        self::assertFalse(fnmatch('**/[#x]y', '#y'), 'premise');
        self::assertFalse(self::oldPredicate('**/[#x]y', '#y'), 'premise');

        self::assertTrue(SkillRegistry::pathMatches('**/[#x]y', '#y'));
        self::assertTrue(SkillRegistry::pathMatches('**/[#x]y', 'xy'));
        self::assertTrue(SkillRegistry::pathMatches('**/[#x]y', 'a/b/#y'));
        self::assertFalse(SkillRegistry::pathMatches('**/[#x]y', 'qy'));
    }

    /**
     * A body ending in a lone backslash stays UNCOMPILABLE on purpose, and
     * that is a decision rather than an oversight.
     *
     * The scan that finds a class's closing `]` is not escape-aware, so
     * `a[\]]b` arrives at the body compiler as the fragment `\` — a class
     * whose real end the translation cannot see. Left alone it keeps the regex
     * uncompilable and the pattern routes to the old predicate, which reads it
     * correctly. Making it compile would make it compile WRONG.
     *
     * Pinned because the seam is invisible from the outside: the answer below
     * is right either way, and only this test records WHERE it came from.
     */
    public function testAnEscapedClassTerminatorIsLeftToTheFallback(): void
    {
        self::assertTrue(fnmatch('a[\]]b', 'a]b'), 'premise: fnmatch reads the escaped terminator');
        self::assertFalse(@preg_match('#^a[\]b$#Ds', 'a]b'), 'premise: what the translation emits will not compile');

        self::assertTrue(SkillRegistry::pathMatches('a[\]]b', 'a]b'));
        self::assertFalse(SkillRegistry::pathMatches('a[\]]b', 'axb'));
    }

    /**
     * The compile cache exists, which nothing else in this suite noticed.
     *
     * {@see SkillRegistry::pathMatches()}'s doc-block spends a paragraph on
     * the measured cost of the new matcher — a ratio, deliberately not quoted
     * again here, because two takes on this same box disagreed on the absolute
     * times and agreed on the ratio. The whole of that figure rests on
     * compiling each pattern once; the class doc-block on
     * `$compiledPathPatterns` justifies the STATIC lifetime on the same
     * grounds. Deleting the cache left the entire suite green — a perf claim
     * with no guard under it is a comment, so this is the guard.
     *
     * Reads a private static through reflection deliberately: the cache has no
     * public surface, and asserting on timing instead would be a coin flip.
     */
    public function testTheCompiledPatternIsCachedUnderItsRawPattern(): void
    {
        $cache = new \ReflectionProperty(SkillRegistry::class, 'compiledPathPatterns');
        $cache->setAccessible(true);

        // Unique so a sibling test cannot have warmed it first.
        $pattern = 'cache-probe-' . __FUNCTION__ . '/**/*.php';
        self::assertArrayNotHasKey($pattern, (array) $cache->getValue());

        SkillRegistry::pathMatches($pattern, 'nope');
        $compiled = (array) $cache->getValue();

        self::assertArrayHasKey(
            $pattern,
            $compiled,
            'pathMatches() no longer caches the compiled regex. The measured ratio in its doc-block is '
            . 'the cost of compiling once per PATTERN and matching once per path; SkillPathNudge calls '
            . 'it per pattern per path on a tool-call path, so without this the compile moves inside '
            . 'that product. Re-take the figure before deciding the cache is not worth it.',
        );
        self::assertSame('#^cache\-probe\-' . __FUNCTION__ . '(?:/.*)?/.*\.php$#Ds', $compiled[$pattern]);
    }

    /**
     * {@see BEFORE} really is what the old predicate answered, re-derived
     * rather than trusted.
     *
     * The table is the only record of a predicate that no longer exists in the
     * tree, and every no-narrowing claim in this file is measured against it.
     * A hand-edited row would silently retire a guard — the cheapest way to
     * make a narrowing disappear is to change the `1` to a `0` — so the three
     * rewrites are re-implemented in {@see oldPredicate()} and the whole grid
     * is recomputed here.
     */
    public function testTheFrozenBeforeTableIsWhatTheOldPredicateActuallyAnswers(): void
    {
        $rederived = [];
        foreach (self::PATTERNS as $pattern) {
            $bits = '';
            foreach (self::PATHS as $path) {
                $bits .= self::oldPredicate($pattern, $path) ? '1' : '0';
            }
            $rederived[$pattern] = $bits;
        }

        self::assertSame(self::BEFORE, $rederived);
    }

    /**
     * The predicate this change replaced, re-implemented for the two jobs the
     * frozen table cannot do: checking that the table is honest, and
     * attributing each widening to a CAUSE.
     *
     * Byte-for-byte the pre-E85 body of `getForPaths()` at 8416d98e. It lives
     * here and not in {@see SkillRegistry} because production has no use for
     * it beyond {@see SkillRegistry::legacyPathMatch()}, which answers a
     * narrower question (uncompilable patterns only) and must be free to
     * change without moving the historical baseline this file is written
     * against.
     */
    private static function oldPredicate(string $pattern, string $path): bool
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
     * @param list<string> $paths
     */
    private function skill(string $name, array $paths): Skill
    {
        return new Skill(
            name: $name,
            description: 'd',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'shared',
            paths: $paths,
            content: '',
            sourcePath: '/tmp/' . $name . '/SKILL.md',
        );
    }
}
