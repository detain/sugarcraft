<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A TEST THAT REDIRECTS ONE SPELLING OF `HOME` AND NOT THE OTHER IS WRITING
 * INTO THE DEVELOPER'S REAL HOME DIRECTORY, AND NOTHING SAID SO.
 *
 * {@see HomeSandboxTrait}'s doc-block has argued this since it was written --
 * "half a sandbox is not a sandbox", because `$_SERVER['HOME']` does not follow
 * a `putenv()` and `getenv('HOME')` does not follow an assignment to the
 * superglobal. It was an argument with no reader. E482 found a live
 * counter-example one directory away: `Integration/MultiAgentRefactorTest`
 * moved only the superglobal, and every reader it reached goes through
 * {@see \SugarCraft\Crush\Support\HomeDirectory}, which prefers `getenv()`.
 *
 * WHAT THAT COST, measured rather than feared. One run of that file created
 * exactly THREE directories under the real `~/.sugar-crush/teams/` -- one per
 * test that builds a Team. At the moment it was found that directory held
 * 3,133 entries with an mtime minutes old, which is roughly a thousand suite
 * runs of accumulated residue. Fixing the sandbox took the per-run delta from
 * 3 to 0.
 *
 * AND IT IS NOT ONLY LITTER, which is the part that makes this a harness
 * integrity guard rather than a tidiness one. `Agents/TeamTest` asserts in its
 * `tearDown()` that the real `~/.sugar-crush` is unchanged across each of its
 * tests. Three lanes run this suite concurrently against ONE home directory, so
 * a leak in any lane reds a test in another that did nothing wrong, at a moment
 * nobody can reproduce afterwards. That is a cross-lane flake with a mechanism.
 *
 * THE ROSTER BELOW IS A MIGRATION BACKLOG, NOT AN EXEMPTION LIST, and the
 * difference is that it is checked in BOTH directions. A new one-sided file
 * reds. A rostered file that gets fixed ALSO reds, so the row cannot outlive
 * the thing it describes and the list can only shrink. No row carries a reason,
 * deliberately: there is no good reason to sandbox half of `HOME`, and a column
 * for one would invite filling it in.
 *
 * THE EXEMPTION IS A TRAIT USE, NOT A MENTION OF ONE, and that distinction was
 * bought the hard way. WHAT THIS SAID BEFORE: the census skipped any file whose
 * source contained the string `HomeSandboxTrait`, on the reasoning that such a
 * file is sandboxed by the trait and has nothing to answer for. WHAT IS TRUE
 * NOW: that skip was satisfied by a doc-comment. `MultiAgentRefactorTest` -- the
 * file this guard was BUILT for -- acquired an explanatory comment naming the
 * trait in the very commit that fixed it, and reverting the fix left this guard
 * green; the exemption had been bought with a sentence. Measured across `tests/`
 * on PHP 8.3.6, the substring appeared in seven files that do not use the trait
 * at all, two of which matter: that one, and THIS one. WHY THIS STILL EARNS ITS
 * PLACE: the exemption itself is sound -- a file that really does `use
 * HomeSandboxTrait` really is sandboxed on both spellings -- so the fix is to
 * ask the question precisely. {@see usesHomeSandboxTrait()} reads the token
 * stream and accepts only a `use` INSIDE a class body naming that trait, which
 * a comment, a top-level `use` import and a closure's `use (...)` all fail.
 * Tightening it cost nothing: the roster is the same nine files either way,
 * because every prose-mentioning file that touches `HOME` was already sandboxing
 * both spellings correctly.
 */
final class OneSidedHomeSandboxTest extends TestCase
{
    use TestFileWalkTrait;

    /**
     * Test files that still redirect exactly one spelling of `HOME`.
     *
     * Paths are relative to `tests/`. To retire a row: move BOTH spellings in
     * that file -- or give it {@see HomeSandboxTrait} -- and delete the line.
     *
     * @var list<string>
     */
    private const NOT_YET_MIGRATED = [
        'App/AppBuilderTest.php',
        'Backend/EngineBackendParallelConfigTest.php',
        'Cli/SessionRetentionWiringTest.php',
        'Context/ImportResolverTest.php',
        'Context/InstructionFileLoaderTest.php',
        'Diagnostics/RuntimeNoticeSinkDeliveryTest.php',
        'Integration/MemoryPromptWiringTest.php',
        'Integration/WorkflowExecutionTest.php',
        'SessionTest.php',
    ];

    /**
     * THE SCANNER IS ALIVE, run before anything trusts it.
     *
     * The assertion this file rests on is an ABSENCE -- "no file outside the
     * roster is one-sided" -- and a scanner that matches nothing satisfies it
     * perfectly. So known positives and known negatives go through the very
     * same {@see classify()} first.
     *
     * THE FIXTURES ARE BUILT BY CONCATENATION rather than spelled out, because
     * this file is the one that DESCRIBES the pattern and would otherwise land
     * in the population it measures. The previous attempt at that got it half
     * right and is worth recording. Splitting the superglobal's subscript did
     * defeat the superglobal regex -- but the environment regex scans from the
     * call's opening parenthesis to the next CLOSING one, and a concatenated
     * argument contains no closing parenthesis, so it matched straight across
     * the split. This file therefore read as `{server:false, env:true}`: a
     * one-sided file, by its own scanner, kept off its own roster purely by the
     * trait-substring skip that F1 removed. The fixture below consequently
     * splits the CALL as well as the argument, and this doc-block describes
     * both regexes rather than quoting them, because quoting one here is enough
     * to match it. {@see testThisGuardDoesNotAppearInItsOwnCensus()} pins that
     * and will red if anyone spells either shape out again.
     */
    public function testTheScannerSeesBothPolarities(): void
    {
        $server = '$_SERVER[' . "'HOME'" . '] = $dir;';
        // The call is split as well as its argument, so that the function
        // name is never immediately followed by an opening parenthesis
        // anywhere in this file -- including in this comment. See the
        // doc-block: the environment regex would otherwise scan from here
        // straight into the fixture below and match.
        $env = 'putenv' . '(' . "'HOME='" . ' . $dir);';

        $none = ['server' => false, 'env' => false, 'envSuperglobal' => false];

        $this->assertSame(
            ['server' => true, 'env' => false, 'envSuperglobal' => false],
            self::classify('<?php ' . $server),
            'a file that moves only the superglobal was not seen as one-sided - which is '
                . 'exactly the shape that wrote 3 directories per run into a real home',
        );
        $this->assertSame(
            ['server' => false, 'env' => true, 'envSuperglobal' => false],
            self::classify('<?php ' . $env),
            'a file that moves only the environment was not seen as one-sided',
        );
        $this->assertSame(
            ['server' => true, 'env' => true, 'envSuperglobal' => false],
            self::classify('<?php ' . $server . ' ' . $env),
            'a file that moves BOTH spellings was reported as one-sided, which would put every '
                . 'correctly sandboxed test on the hook and be answered with roster rows',
        );
        $this->assertSame(
            $none,
            self::classify('<?php $x = 1;'),
            'a file that does not touch HOME at all was reported as touching it',
        );
        $this->assertSame(
            ['server' => true, 'env' => false, 'envSuperglobal' => false],
            self::classify('<?php unset($_SERVER[' . "'HOME'" . ']);'),
            'unsetting the superglobal is a write to it and must count as one',
        );

        // The three shapes the previous alphabet could not express. A census
        // reporting a clean tree is only as wide as the spellings it knows.
        $this->assertSame(
            ['server' => true, 'env' => false, 'envSuperglobal' => false],
            self::classify('<?php $_SERVER[' . "'HOME'" . '] ??= $dir;'),
            'a null-coalescing assignment is a write to the superglobal, and reading it as '
                . 'anything else hides a one-sided sandbox behind two extra characters',
        );
        $this->assertSame(
            ['server' => true, 'env' => false, 'envSuperglobal' => false],
            self::classify('<?php $_SERVER[' . "'HOME'" . '] .= $suffix;'),
            'an appending assignment is a write to the superglobal',
        );
        $this->assertSame(
            ['server' => false, 'env' => false, 'envSuperglobal' => true],
            self::classify('<?php $_ENV[' . "'HOME'" . '] = $dir;'),
            'a file that moves only $_ENV was reported as touching nothing. That shape is '
                . 'WORSE than one-sided: measured on this tree, nothing under src/ reads '
                . '$_ENV at all, so such a sandbox is entirely inert and every reader gets '
                . 'the real home',
        );

        // A comparison is a READ. Counting it as a write would put files that
        // merely assert about HOME onto a migration roster they cannot leave.
        $this->assertSame(
            $none,
            self::classify('<?php if ($_SERVER[' . "'HOME'" . '] === $dir) { return; }'),
            'a comparison against the superglobal was counted as a write to it',
        );
        $this->assertSame(
            $none,
            self::classify('<?php if ($_SERVER[' . "'HOME'" . '] != $dir) { return; }'),
            'an inequality test against the superglobal was counted as a write to it',
        );
    }

    /**
     * THE TRAIT DETECTOR SEES A USE, AND NOTHING THAT MERELY SAYS THE NAME.
     *
     * This is the guard on the exemption, and it is the assertion F1 was
     * missing: every polarity that a substring test gets wrong is here.
     */
    public function testTheTraitDetectorSeesUseAndNotMention(): void
    {
        $trait = 'Home' . 'SandboxTrait';

        $this->assertTrue(
            self::usesHomeSandboxTrait('<?php class A { use ' . $trait . '; }'),
            'a plain trait use was not recognised, which would put every correctly '
                . 'sandboxed file onto the migration roster',
        );
        $this->assertTrue(
            self::usesHomeSandboxTrait(
                '<?php class A { use \\SugarCraft\\Crush\\Tests\\Support\\' . $trait . '; }',
            ),
            'a fully qualified trait use was not recognised - one file in this tree '
                . 'spells it exactly that way',
        );
        $this->assertTrue(
            self::usesHomeSandboxTrait('<?php class A { use OtherTrait, ' . $trait . '; }'),
            'a trait use naming more than one trait was not recognised',
        );

        $this->assertFalse(
            self::usesHomeSandboxTrait('<?php class A { /* ' . $trait . ' explains why */ }'),
            'A COMMENT NAMING THE TRAIT BOUGHT AN EXEMPTION. That is F1 exactly: the file '
                . 'this guard was built for acquired such a comment in the commit that fixed '
                . 'it, and reverting the fix then left this guard green',
        );
        $this->assertFalse(
            self::usesHomeSandboxTrait('<?php /** @see ' . $trait . ' */ class A {}'),
            'a doc-block naming the trait bought an exemption',
        );
        $this->assertFalse(
            self::usesHomeSandboxTrait('<?php use SugarCraft\\Crush\\Tests\\Support\\' . $trait . ';'),
            'a top-level import was read as a trait use. Importing the NAME does not apply '
                . 'the trait to anything',
        );
        $this->assertFalse(
            self::usesHomeSandboxTrait('<?php class A { function f() { return function () use ($x) { return $x; }; } }'),
            "a closure's use (...) clause was read as a trait use",
        );
        $this->assertFalse(
            self::usesHomeSandboxTrait('<?php class A { use SomeOtherTrait; }'),
            'a use of an unrelated trait was read as a use of this one',
        );
    }

    /**
     * THE DISTINCTION IS LIVE, not a hypothetical the fixtures invented.
     *
     * Rule 25: an exemption test whose two answers never diverge on the real
     * tree is decoration. This asserts that files mentioning the trait without
     * using it EXIST, and that not one of them is skipped by the census -- so
     * the day someone re-widens the skip to a substring, this reds and names
     * the files that would silently leave the population.
     *
     * No cardinality is asserted. The set is derived here rather than written
     * down, because a count taken over `tests/` is invalidated by the next lane
     * to merge.
     */
    public function testMentioningTheTraitDoesNotBuyAnExemption(): void
    {
        $mentionOnly = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $source = (string) file_get_contents($path);
            if (!str_contains($source, 'Home' . 'SandboxTrait')) {
                continue;
            }
            if (self::usesHomeSandboxTrait($source)) {
                continue;
            }
            $mentionOnly[] = $relative;
        }
        sort($mentionOnly);

        $this->assertNotSame(
            [],
            $mentionOnly,
            'no file in this tree mentions HomeSandboxTrait without using it, so the '
                . 'difference between the two tests above is currently untested against real '
                . 'source. That may simply be true - but check the detector before believing '
                . 'it, because one that always answers "uses it" reports exactly this',
        );

        foreach ($mentionOnly as $relative) {
            $this->assertNotContains(
                $relative,
                self::NOT_YET_MIGRATED,
                $relative . ' mentions HomeSandboxTrait without using it AND carries a roster '
                    . 'row. Those are two different exemptions for one file; give it the trait '
                    . 'or move both spellings, and delete the row',
            );
        }
    }

    /**
     * THIS FILE MUST NOT LAND IN ITS OWN CENSUS.
     *
     * It is the file that describes the pattern, so its fixtures are built by
     * concatenation. That is easy to undo by accident and impossible to notice:
     * before F1 this guard classified as `{server:false, env:true}` -- a
     * one-sided file, hidden from its own roster only by the substring skip.
     * With that skip gone the concatenation is load-bearing, so it is pinned.
     */
    public function testThisGuardDoesNotAppearInItsOwnCensus(): void
    {
        $self = (string) file_get_contents(__FILE__);

        $this->assertSame(
            ['server' => false, 'env' => false, 'envSuperglobal' => false],
            self::classify($self),
            'this guard now matches its own scanner, which puts it in the population it '
                . 'measures. A fixture was almost certainly spelled out literally instead of '
                . 'being concatenated - note that the putenv regex spans a concatenated '
                . 'argument, so the CALL has to be split too',
        );
    }

    /**
     * THE ROSTER MATCHES THE TREE EXACTLY, in both directions.
     */
    public function testNoTestFileSandboxesOnlyHalfOfHome(): void
    {
        $oneSided = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $source = (string) file_get_contents($path);
            if ($source === '') {
                $this->fail($relative . ' could not be read, so this census is void');
            }
            if (self::usesHomeSandboxTrait($source)) {
                continue;
            }
            $found = self::classify($source);
            $touches = $found['server'] || $found['env'] || $found['envSuperglobal'];
            if ($touches && !($found['server'] && $found['env'])) {
                $oneSided[] = $relative;
            }
        }
        sort($oneSided);

        // Rule 15's positive component. The roster is non-empty today, so a
        // scanner that has stopped matching produces an EMPTY list - which
        // would read as "everything has been migrated" and pass the diff below
        // in one direction while quietly failing to guard anything.
        $this->assertNotSame(
            [],
            $oneSided,
            'the census found no one-sided file anywhere. If every row below really has been '
                . 'migrated that is good news and the roster should be emptied in the same '
                . 'change - but check the scanner first, because a dead scanner reports exactly '
                . 'this',
        );

        $roster = self::NOT_YET_MIGRATED;
        sort($roster);

        $this->assertSame(
            $roster,
            $oneSided,
            'the set of test files sandboxing HOME incompletely has changed. '
                . 'A file that appears here writes into the DEVELOPER\'S real home directory '
                . 'for every reader that consults a spelling it did not move: $_SERVER[\'HOME\'] '
                . 'does not follow putenv(), getenv(\'HOME\') does not follow an assignment to '
                . 'the superglobal, and $_ENV is read by NOTHING in src/ so moving only that '
                . 'sandboxes nothing at all. Measured once already - one such file created three '
                . 'directories per run under the real ~/.sugar-crush/teams/, and reds '
                . 'Agents/TeamTest in whichever OTHER lane happens to be mid-test. '
                . 'IF A FILE WAS ADDED: move both spellings, or use HomeSandboxTrait, rather '
                . 'than adding a row - and note that a COMMENT naming the trait is not a use of '
                . 'it and will not excuse the file. IF A FILE DISAPPEARED: it was fixed - delete '
                . 'its row, because this list is a migration backlog and may only shrink.',
        );
    }

    /**
     * Which spellings of `HOME` does $source WRITE?
     *
     * Writes only -- a comparison is a read, and counting it would strand files
     * that merely assert about `HOME` on a roster they cannot leave.
     * `envSuperglobal` is tracked separately from `env` because `$_ENV` is not
     * an alternative spelling of the same sandbox: measured on this tree,
     * nothing under `src/` reads `$_ENV`, so a file that moves only that has
     * sandboxed nothing while looking like it tried.
     *
     * @return array{server:bool, env:bool, envSuperglobal:bool}
     */
    private static function classify(string $source): array
    {
        $writes = static function (string $superglobal) use ($source): bool {
            // `(?:\?\?|\.)?=(?!=)` accepts `=`, `??=` and `.=` while rejecting
            // `==`/`===`; the negative lookahead is what keeps comparisons out.
            $assign = '/\$' . $superglobal . '\[[\'"]HOME[\'"]\]\s*(?:\?\?|\.)?=(?!=)/';
            $unset = '/unset\(\s*\$' . $superglobal . '\[[\'"]HOME[\'"]\]/';

            return preg_match($assign, $source) === 1 || preg_match($unset, $source) === 1;
        };

        return [
            'server' => $writes('_SERVER'),
            'env' => preg_match('/putenv\([^)]*HOME/', $source) === 1,
            'envSuperglobal' => $writes('_ENV'),
        ];
    }

    /**
     * Does $source APPLY {@see HomeSandboxTrait} to a class?
     *
     * Token-based rather than textual, because the textual answer is the F1
     * defect: `str_contains($source, 'HomeSandboxTrait')` is true of a
     * doc-block, and a doc-block is not a sandbox. A trait use is a `use`
     * INSIDE a class body followed by a name; the three things that look like
     * one and are not -- a top-level import (`use` at depth 0), a closure's
     * capture list (`use` followed by `(`), and any mention inside a comment
     * (never a `T_USE` token at all) -- are each excluded here and each pinned
     * by {@see testTheTraitDetectorSeesUseAndNotMention()}.
     */
    private static function usesHomeSandboxTrait(string $source): bool
    {
        $tokens = @token_get_all($source);
        $depth = 0;
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }

                continue;
            }

            // Interpolation openers are closed by a plain `}`, so they have to
            // be counted or the depth drifts negative and every later `use`
            // reads as top-level.
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }

            if ($token[0] !== T_USE || $depth < 1) {
                continue;
            }

            // `use A, B;` names two traits, so a comma SEPARATES names rather
            // than ending the list -- reading it as a terminator would see only
            // the first and miss the sandbox on `use OtherTrait, HomeSandboxTrait;`.
            $names = [];
            $current = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (\is_string($next)) {
                    if ($next === ',') {
                        $names[] = $current;
                        $current = '';

                        continue;
                    }

                    // `;` ends the use, `{` opens a conflict-resolution block,
                    // `(` means this was a closure's capture list all along.
                    break;
                }
                if (\in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (!\in_array($next[0], [
                    T_STRING,
                    T_NS_SEPARATOR,
                    T_NAME_QUALIFIED,
                    T_NAME_FULLY_QUALIFIED,
                    T_NAME_RELATIVE,
                ], true)) {
                    break;
                }

                $current .= $next[1];
            }
            $names[] = $current;

            foreach ($names as $name) {
                if (str_contains($name, 'Home' . 'SandboxTrait')) {
                    return true;
                }
            }
        }

        return false;
    }
}
