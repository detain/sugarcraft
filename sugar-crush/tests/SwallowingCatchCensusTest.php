<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * A CATCH CLAUSE WIDE ENOUGH TO EAT PHPUNIT'S OWN FAILURE, WRAPPED AROUND A TRY
 * BODY THAT ASSERTS, IS A TEST THAT PASSES WHILE ASSERTING NOTHING.
 *
 * MEASURED on PHPUnit 10.5.64 / PHP 8.3.6, `class_parents()` reports:
 *
 *   ExpectationFailedException -> AssertionFailedError
 *                              -> PHPUnit\Framework\Exception
 *                              -> RuntimeException
 *                              -> Exception
 *
 * So `catch (\RuntimeException $e)` catches every failing assertion inside its
 * own try, including the `$this->fail(...)` written on the line above it as the
 * "this should have thrown" guard. The canonical shape was:
 *
 *     try {
 *         $subject->doTheThing();
 *         $this->fail('doTheThing() should have thrown');
 *     } catch (\RuntimeException $e) {
 *         $this->assertNotEmpty($e->getMessage());
 *     }
 *
 * which is green whether `doTheThing()` throws, does nothing, or is deleted
 * outright — the fail() lands in the catch and its own message satisfies the
 * assertion. That is not a hypothesis. MEASURED in round 58 by restoring this
 * exact shape in AgentPresetRegistryTest::testLoadThrowsOnInvalidYaml with the
 * subject call `$registry->load('bad-yaml')` DELETED: the file ran green, rc 0.
 * With the repaired shape and the same deletion it goes red.
 *
 * THE STRUCTURAL RULE THIS GUARD ENFORCES, and why it is structural rather than
 * textual (a prose-keyed exemption is bought with a sentence, and the comment
 * explaining a fix is enough to buy it):
 *
 *   - A catch type that WOULD catch an ExpectationFailedException, but is not
 *     itself an assertion-failure type, is FORBIDDEN around an asserting try.
 *     That is Throwable, Exception, RuntimeException, PHPUnit\Framework\Exception,
 *     and anything else in the tree that happens to sit on that chain.
 *   - A catch type that IS an assertion-failure type — AssertionFailedError or
 *     a subclass such as ExpectationFailedException — is ALLOWED, because
 *     naming it is an explicit statement that the failure is the subject under
 *     test. Four such sites exist and every one of them is correct; see
 *     {@see \SugarCraft\Crush\Tests\Cli\BootstrapSkillSkipsTest} for the
 *     sharpest of them, which catches the NARROWER AssertionFailedError
 *     precisely so that its own `fail()` still escapes.
 *
 * The classification is taken from the live class hierarchy via `is_a()`, not
 * from a hand-written list of names, so a type this file has never heard of —
 * Symfony's `ParseException`, say, which is-a `\RuntimeException` and therefore
 * swallows — is classified correctly the first time it appears.
 *
 * WHAT THIS GUARD SEES NOW, AND WHAT IT STILL CANNOT (E572, round 67). Both
 * blind spots this file carried since round 57 are closed for the shapes that
 * exist in the tree today; a guard that hides its limits is the defect it is
 * named for, so the residue is written out too:
 *
 *   1. AN ASSERTION REACHED INDIRECTLY is now followed through SAME-FILE
 *      helper calls (`$this->h()`, `self::h()`, `static::h()`, transitively,
 *      depth-bounded with a visited set), the walk `census_indirect_assert.php`
 *      measured at round 59. E613 measured ZERO offenders then; the tree at
 *      this commit holds TEN — all `catch (\RuntimeException)` in
 *      Agents/AgentManagerTest.php, code written after that measurement, each
 *      one this census's own record-for-later fix shape whose SUBJECT is a
 *      helper that asserts internally. That population is pinned as a
 *      SHRINK-ONLY ROSTER by {@see self::indirectSwallowRoster()}, asserted
 *      inside {@see self::testNoTestSwallowsTheFailureStandingNextToIt()} — a
 *      new indirect swallow reddens the census immediately, and a roster row
 *      may only be deleted, never added to, because an exemption row for
 *      correct code is a licence (rule 33) and these rows are not exempt:
 *      they are queued repair work for the owning lane.
 *      STILL INVISIBLE: a helper declared in a PARENT class or a TRAIT — the
 *      walk is same-file (E613's own caveat); cross-file expansion was not
 *      built and is not claimed.
 *   2. SWALLOWING THAT IS NOT A CATCH AT ALL is guarded by the handler census
 *      in this file — its scanner alive on fixtures inside
 *      {@see self::testTheScannerIsAliveInBothPolarities()}, its tree roster
 *      asserted inside {@see self::testNoTestSwallowsTheFailureStandingNextToIt()}.
 *      E614 measured that the wording here overstated the mechanism: a
 *      PHPUnit assertion failure is a THROWN exception and no error handler
 *      intercepts it. What an installed handler suppresses is PHPUnit's
 *      conversion of PHP warnings/notices into failures, and a handler not
 *      restored on every path out LEAKS INTO EVERY LATER TEST IN THE PROCESS,
 *      silencing that conversion suite-wide. The checkable property is
 *      therefore PAIRING — an install whose enclosing function does not
 *      restore it inside a `finally` — and both token spellings (bare
 *      `set_error_handler` and `\set_error_handler`, which arrives as one
 *      T_NAME_FULLY_QUALIFIED) are pinned by their own fixture, because the
 *      round-59 scanner that missed the leading-`\` form is E614's warning
 *      made concrete. The one unrestored site in the tree
 *      (Skills/SkillLoaderTest.php, `suppressErrorLog()`) is a dormant seam
 *      with zero callers and is pinned as the whole roster, not exempted.
 *
 * A third limit is deliberate rather than accidental: a catch type this file
 * cannot RESOLVE to a real class is reported as unclassified and turns the
 * census red. A guard that quietly ignores what it cannot parse has a hole
 * shaped exactly like the next defect — and clause ordering (E612) does NOT
 * silence an unclassifiable type: an earlier clause can make a later one
 * UNREACHABLE for an assertion failure, it cannot make an unreadable name
 * readable.
 */
final class SwallowingCatchCensusTest extends TestCase
{
    /**
     * Every known-positive, known-negative, and unresolvable case, pushed
     * through the SAME scanners the tree scan uses — the catch classifier with
     * its resolution shapes, the permissive fallback pinned in both directions
     * (E578/E615), the clause-order model in both polarities (E612), the
     * same-file helper walk with E613's control set (E572.1), and the handler
     * pairing scanner over BOTH token kinds (E572.2, each spelling with its
     * own fixture per E614's near-miss).
     *
     * An assertion of "no occurrences" is not evidence unless something in the
     * same test proves the scanner still works: mutate `scanSource()` to return
     * `[]` and the tree scan below stays green on its own.
     *
     * The fixture sources are BUILT BY CONCATENATION and never spelled as a
     * literal offender, so a future textual sweep for the pattern cannot eat
     * the file that documents it.
     */
    public function testTheScannerIsAliveInBothPolarities(): void
    {
        $wide = '\\' . 'RuntimeException';
        $narrow = '\\' . 'InvalidArgumentException';
        $deliberate = '\\PHPUnit\\Framework\\' . 'AssertionFailedError';

        $positive = self::fixture('$this->assertSame(1, 1);', $wide);
        $hits = self::scanSource($positive, 'FIXTURE_POSITIVE.php');
        self::assertCount(
            1,
            $hits,
            'the scanner no longer sees a wide catch around an asserting try — the tree scan '
            . 'below is therefore worthless and its empty result means nothing',
        );
        self::assertSame('offender', $hits[0]['verdict']);

        // NEGATIVE 1: the catch type cannot catch an assertion failure at all.
        self::assertSame(
            [],
            self::scanSource(self::fixture('$this->assertSame(1, 1);', $narrow), 'FIXTURE_NEG1.php'),
            'a catch type that cannot catch an ExpectationFailedException was reported anyway',
        );

        // NEGATIVE 2: wide catch, but the try body asserts nothing, so there is
        // no failure standing next to it to be eaten.
        self::assertSame(
            [],
            self::scanSource(self::fixture('doSomething();', $wide), 'FIXTURE_NEG2.php'),
            'a wide catch around a non-asserting try body was reported as an offender',
        );

        // NEGATIVE 3: the deliberate shape. Naming the assertion-failure type is
        // the statement of intent that makes it legitimate.
        self::assertSame(
            [],
            self::scanSource(self::fixture('$this->assertSame(1, 1);', $deliberate), 'FIXTURE_NEG3.php'),
            'catching the assertion-failure type BY NAME is how a test says the failure is its '
            . 'subject; reporting it would make the guard unusable and invite a prose exemption',
        );

        // AND AN INTERPOLATED BRACE OPENER IN THE TRY BODY, which is a token
        // whose TEXT is not `{`. Before every opener was named, the `${`
        // spelling decremented a level it never incremented, the body walk
        // ended early and the catch clause after it was never reached — so a
        // real offender read as a clean file. The row after this one is the
        // control: the SAME body with no interpolation must give the same
        // answer, or this fixture is pinning the wrong thing.
        $interpolated = self::fixture('$this->assertSame(1, "$' . '{y}");', $wide);
        self::assertCount(
            1,
            self::scanSource($interpolated, 'FIXTURE_INTERPOLATED.php'),
            'an interpolated brace opener ends the try-body walk early, so every catch clause '
            . 'after the first such string in a file is invisible to this census',
        );

        // AND THE MIRROR OF IT: A TOKEN WHOSE TEXT IS A BRACE BUT WHICH IS NOT
        // ONE. A double-quoted string holding a simple variable next to a brace
        // arrives as a single T_ENCAPSED_AND_WHITESPACE token whose text is
        // EXACTLY that brace. A walk deciding on extracted text counts it, the
        // depth goes wrong, and the catch clause after it is never reached —
        // the same failure as the row above, arrived at from the opposite side.
        // Both spellings are pinned, because the close brace unbalances the
        // walk downwards and the open brace unbalances it upwards, and only one
        // of the two is fixed by gating either comparison alone.
        //
        // The bodies are assembled by concatenation so this file never spells a
        // whole offender literally; see the doc-block on this method.
        $closeBraceInString = self::fixture('$this->assertSame(1, "$' . 'x}");', $wide);
        self::assertCount(
            1,
            self::scanSource($closeBraceInString, 'FIXTURE_CLOSE_BRACE_IN_STRING.php'),
            'a close brace inside a string literal is being counted as a real brace, so the '
            . 'try-body walk under-counts its depth, ends early, and every catch clause after '
            . 'the first such string in a file is invisible to this census',
        );

        $openBraceInString = self::fixture('$this->assertSame(1, "$' . 'x{");', $wide);
        self::assertCount(
            1,
            self::scanSource($openBraceInString, 'FIXTURE_OPEN_BRACE_IN_STRING.php'),
            'an open brace inside a string literal is being counted as a real brace, so the '
            . 'try-body walk over-counts its depth and never returns to level zero at the end '
            . 'of the try body',
        );

        // THE TWO RESOLUTION SHAPES THAT MAKE THE CLASSIFIER, NOT THE CODE, THE
        // DEFECT. Both report a file that named its catch type CORRECTLY, so
        // the failure they produce is a false alarm, and a false alarm on an
        // emptiness guard is answered with an exemption row — which is a
        // licence, and exactly where the next real offender hides.
        //
        // MEASURED at this commit: neither shape occurs in sugar-crush/tests
        // (463 files: zero group-use catch types, zero bare-unimported ones).
        // Nor is either shape prevented. `.php-cs-fixer.dist.php` enables
        // `@PSR12`, which DOES carry `single_import_per_statement` -- but
        // configured `['group_to_single_imports' => false]` (php-cs-fixer
        // v3.95.21), i.e. the fixer deliberately leaves a group import alone.
        // So these are latent rather than live, and they are pinned on fixtures
        // rather than on the tree for that reason.
        $groupPrelude = "namespace Demo;\n"
            . 'use PHPUnit\\Framework\\' . '{' . 'AssertionFailedError, Exception as WideOne};' . "\n";

        // A group import of the DELIBERATE type. Before the group form was
        // parsed, neither name in the braces was mapped at all, so this correct
        // file was reported as [unclassified] and reddened the census.
        self::assertSame(
            [],
            self::scanSource(
                self::fixture('$this->assertSame(1, 1);', 'AssertionFailedError', $groupPrelude),
                'FIXTURE_GROUP_USE_SAFE.php',
            ),
            'a catch type imported through a group `use` is not being resolved, so a file that '
            . 'names the assertion-failure type correctly is reported as unclassified',
        );

        // And the positive half of the same prelude: an aliased WIDE type from
        // inside the braces must still be caught as an offender, which is what
        // separates "the group form is parsed" from "the group form is ignored
        // and both names happen to fall through to something harmless".
        $grouped = self::scanSource(
            self::fixture('$this->assertSame(1, 1);', 'WideOne', $groupPrelude),
            'FIXTURE_GROUP_USE_OFFENDER.php',
        );
        self::assertCount(1, $grouped, 'an aliased wide type inside a group import was not reported');
        self::assertSame('offender', $grouped[0]['verdict']);

        // An unqualified, UNIMPORTED type declared in the file's own namespace.
        // PHP resolves this against the current namespace and does not fall
        // back to global; resolving it as global made a real class look like a
        // class that does not exist.
        self::assertSame(
            [],
            self::scanSource(
                self::fixture(
                    '$this->assertSame(1, 1);',
                    'AssertionFailedError',
                    "namespace PHPUnit\\Framework;\n",
                ),
                'FIXTURE_SAME_NAMESPACE.php',
            ),
            'an unqualified catch type declared in the file\'s own namespace is being resolved '
            . 'as a global name, so correct code is reported as unclassified',
        );

        // THE UNPARSEABLE CASE, which must go red rather than be skipped.
        $unknown = self::scanSource(
            self::fixture('$this->assertSame(1, 1);', '\\No\\Such\\Namespace\\NopeException'),
            'FIXTURE_UNRESOLVABLE.php',
        );
        self::assertCount(1, $unknown, 'a catch type that resolves to nothing was silently dropped');
        self::assertSame('unclassified', $unknown[0]['verdict']);

        // THE PERMISSIVE FALLBACK, PINNED IN THE DIRECTION THAT BITES (E578
        // as corrected by E615). A bare unimported name in a namespaced file
        // is a catch PHP WILL NEVER ENTER — the name does not exist in that
        // namespace and PHP does not fall back to global — while resolve()'s
        // deliberate fallback answers for the GLOBAL class. E578 prescribed
        // pinning "the fallback can never yield `safe`"; E615 measured that
        // prescription backwards — the fallback yields `safe` routinely
        // (InvalidArgumentException row, the harmless direction), and what it
        // really turns an [unclassified] into is an OFFENDER (RuntimeException
        // row: the census reports a swallow for a clause the language never
        // dispatches to, and a false alarm on an emptiness guard is answered
        // with an exemption row). Both rows are pinned AS MEASURED. MEASURED
        // at this commit again: zero bare-unimported catch types exist in
        // `sugar-crush/tests`, so this is latent — which is exactly why it is
        // pinned on fixtures rather than trusted to the tree scan.
        self::assertSame(
            'safe',
            self::classify('InvalidArgumentException', [], 'Demo578'),
            'the fallback no longer answers for the GLOBAL class here. The name does not exist in '
            . 'Demo578, PHP would never match this catch, and the verdict describes the global '
            . 'class — that permissiveness is the measured contract, not an accident to regress',
        );
        self::assertSame(
            [],
            self::scanSource(
                self::fixture('$this->assertSame(1, 1);', 'InvalidArgumentException', "namespace Demo578;\n"),
                'FIXTURE_PERMISSIVE_GLOBAL_SAFE.php',
            ),
            'the permissive fallback on a PHP-unmatchable catch no longer ends verdict-safe',
        );
        $permissive = self::scanSource(
            self::fixture('$this->assertSame(1, 1);', 'RuntimeException', "namespace Demo578;\n"),
            'FIXTURE_PERMISSIVE_UNMATCHABLE_OFFENDER.php',
        );
        self::assertCount(
            1,
            $permissive,
            'catch(RuntimeException) inside a namespace with no such class is no longer reported — '
            . 'it is either the intended [unclassified]-to-[offender] direction or the classifier died',
        );
        self::assertSame('offender', $permissive[0]['verdict']);
        self::assertSame('direct', $permissive[0]['via']);

        // CATCH-CLAUSE ORDER IS DISPATCH SEMANTICS (E612). PHP hands the
        // failure to the FIRST clause that can receive it, and a catch body
        // that rethrows exits the whole statement — a clause standing after
        // one that already catches an assertion failure can never see it.
        // Round 58's prescribed repair IS that shape, and before the ordering
        // rule the census reported the four repaired Backend sites as
        // offenders (MEASURED both ways at this commit: without the rule they
        // redden, with it they vanish).
        $afeFirst = [
            '\\PHPUnit\\Framework\\' . 'AssertionFailedError $e' => 'throw $e;',
            '\\' . 'Throwable' => '',
        ];

        // NARROW FIRST — the repaired shape — reports NOTHING.
        self::assertSame(
            [],
            self::scanSource(self::clausesFixture('$this->assertSame(1, 1);', $afeFirst), 'FIXTURE_ORDER_NARROW_FIRST.php'),
            'a wide catch standing after a clause that already receives the assertion failure is '
            . 'being reported; PHP dispatches to the first match, so the reported clause can never '
            . 'eat the failure — the classifier, not the code, is the defect (rule 33)',
        );

        // WIDE FIRST — the unrepaired shape — is the SAME pair of clause
        // types, and reports the first one. The two fixtures differ only in
        // ORDER; without both, the census cannot tell a repair from an evasion.
        $wideFirst = array_reverse($afeFirst, preserve_keys: true);
        $reported = self::scanSource(
            self::clausesFixture('$this->assertSame(1, 1);', $wideFirst),
            'FIXTURE_ORDER_WIDE_FIRST.php',
        );
        self::assertCount(1, $reported, 'the wide clause FIRST is the unrepaired shape and must report');
        self::assertSame('offender', $reported[0]['verdict']);
        self::assertSame('\\' . 'Throwable', $reported[0]['type']);

        // A NON-RECEIVING FIRST CLAUSE CONSUMES NOTHING: the skip is keyed on
        // receivability, not position. TypeError cannot receive the failure,
        // so the wide clause behind it is still live.
        $unsafeFirst = self::scanSource(
            self::clausesFixture(
                '$this->assertSame(1, 1);',
                ['\\' . 'TypeError' => '', '\\' . 'RuntimeException' => ''],
            ),
            'FIXTURE_ORDER_INERT_FIRST.php',
        );
        self::assertCount(1, $unsafeFirst, 'the ordering rule suppressed a clause an earlier TypeError cannot shield');
        self::assertSame('\\' . 'RuntimeException', $unsafeFirst[0]['type']);
        self::assertSame('offender', $unsafeFirst[0]['verdict']);

        // NO DOUBLE BOOKKEEPING: two wide clauses, the first already swallows
        // — one row, not two.
        $twoWide = self::scanSource(
            self::clausesFixture(
                '$this->assertSame(1, 1);',
                ['\\' . 'RuntimeException' => '', '\\' . 'Throwable' => ''],
            ),
            'FIXTURE_ORDER_TWO_WIDE.php',
        );
        self::assertCount(1, $twoWide, 'a second wide clause behind the first is a different bug but not a second swallow of the same failure');
        self::assertSame('\\' . 'RuntimeException', $twoWide[0]['type']);

        // ORDERING DOES NOT SILENCE THE UNREADABLE. An earlier clause can make
        // a later one unreachable for the failure; it cannot make an
        // unresolvable name readable — the fail-closed third limit stands.
        $unreadableAfter = self::scanSource(
            self::clausesFixture(
                '$this->assertSame(1, 1);',
                ['\\PHPUnit\\Framework\\' . 'AssertionFailedError' => '', '\\No\\Such\\Namespace\\' . 'NopeException' => ''],
            ),
            'FIXTURE_ORDER_UNREADABLE_AFTER.php',
        );
        self::assertCount(1, $unreadableAfter, 'clause ordering silenced an unclassifiable type — unreadable must go red wherever it stands');
        self::assertSame('unclassified', $unreadableAfter[0]['verdict']);

        // THE HELPER ARM (E572.1): an assertion reached through a same-file
        // helper is an assertion the wide catch can eat. The controls follow
        // the E613 generator's set — one hop, two hops, a clean negative, and
        // mutual recursion that must answer no and TERMINATE (an unbounded
        // walk hangs the suite instead of failing it).
        $oneHop = self::scanSource(
            self::helpersFixture(
                'try { $this->h(); } catch (' . '\\' . 'RuntimeException) { }',
                ['h' => '$this->assertSame(1, 1);'],
            ),
            'FIXTURE_INDIRECT_ONE_HOP.php',
        );
        self::assertCount(
            1,
            $oneHop,
            'an assertion reached through a same-file helper is invisible again — that is exactly '
            . 'the blind spot E572 named, and the StdioMcpServerHandshakeTest::timeStartOf() class '
            . 'of silent pass this census exists to stop',
        );
        self::assertSame('offender', $oneHop[0]['verdict']);
        self::assertSame('helper', $oneHop[0]['via']);

        self::assertCount(
            1,
            self::scanSource(
                self::helpersFixture(
                    'try { $this->h1(); } catch (' . '\\' . 'RuntimeException) { }',
                    ['h1' => '$this->h2();', 'h2' => '$this->fail("two hops away");'],
                ),
                'FIXTURE_INDIRECT_TWO_HOPS.php',
            ),
            'the helper walk is not transitive — a swallow one frame deeper is invisible',
        );

        self::assertSame(
            [],
            self::scanSource(
                self::helpersFixture(
                    'try { $this->h(); } catch (' . '\\' . 'Throwable) { }',
                    ['h' => '$subject->run();'],
                ),
                'FIXTURE_INDIRECT_CLEAN.php',
            ),
            'a helper that asserts NOTHING makes the wide catch reported anyway — the indirect '
            . 'arm went from a blind spot to a false alarm, which is the worse direction',
        );

        self::assertSame(
            [],
            self::scanSource(
                self::helpersFixture(
                    'try { $this->a(); } catch (' . '\\' . 'Throwable) { }',
                    ['a' => '$this->b();', 'b' => '$this->a();'],
                ),
                'FIXTURE_INDIRECT_MUTUAL_RECURSION.php',
            ),
            'mutually recursive helpers either read as asserting (a false alarm) or the walk is '
            . 'unbounded and the suite hangs — the visited set and the depth cap are the fix',
        );

        $directProvenance = self::scanSource(
            self::fixture('$this->assertSame(1, 1);', '\\' . 'RuntimeException'),
            'FIXTURE_VIA_DIRECT.php',
        );
        self::assertSame(
            'direct',
            $directProvenance[0]['via'],
            'a body that asserts outright is no longer attributed to the direct arm, so the tree '
            . 'scan can no longer keep its hard-zero direct contract apart from the rostered '
            . 'helper population',
        );

        // THE HANDLER PAIRING SCANNER (E572.2), both polarities and BOTH TOKEN
        // KINDS each pinned by its own fixture — E614's near-miss was a
        // round-59 scanner that matched only T_STRING and silently dropped
        // every leading-`\` install, because `\set_error_handler(` arrives as
        // ONE T_NAME_FULLY_QUALIFIED token whose text carries the slash.
        $install = 'set_error_handler(static function (): bool { return false; });';
        $bareLeak = self::unrestoredHandlersIn(self::handlerFixture($install, '', ''));
        self::assertCount(1, $bareLeak, 'an unrestored set_error_handler reads clean — the leak '
            . 'silences PHPUnit warning-to-failure conversion for every LATER test in the process');
        self::assertFalse($bareLeak[0]['qualified'], 'the bare install was mislabelled its token kind');

        $qualifiedLeak = self::unrestoredHandlersIn(
            self::handlerFixture('\\' . 'set_error_handler(static function (): bool { return false; });', '', ''),
        );
        self::assertCount(
            1,
            $qualifiedLeak,
            'the leading-`\` spelling arrived as one T_NAME_FULLY_QUALIFIED token and was skipped — '
            . 'this is the exact miss E614 recorded, with a whole file silently dropped over it',
        );
        self::assertTrue($qualifiedLeak[0]['qualified']);

        self::assertSame(
            [],
            self::unrestoredHandlersIn(self::handlerFixture($install, 'restore_error_handler();', '')),
            'a handler restored inside the `finally` is reported anyway — the tree\'s own shape '
            . 'would then read as thirteen leaks and the roster would be noise',
        );

        self::assertSame(
            [],
            self::unrestoredHandlersIn(
                self::handlerFixture(
                    '$previous = \\' . 'set_error_handler(static function (): bool { return false; });',
                    '\\' . 'set_error_handler($previous);',
                    '',
                ),
            ),
            'the save/restore re-install shape (`\set_error_handler($previous)` in the `finally`) is '
            . 'reported anyway — that is how the tree\'s own trait restores, and E614 counted it as restored',
        );

        self::assertCount(
            1,
            self::unrestoredHandlersIn(self::handlerFixture($install, '', 'restore_error_handler();')),
            'a STRAIGHT-LINE restore after the try is counted as pairing — any throw between the '
            . 'install and that line skips it, and the handler then leaks; the `finally` is the '
            . 'whole of the property',
        );
    }

    /**
     * The tree itself. Backed by the fixtures above, which is the only reason
     * an empty result here is worth anything.
     *
     * TWO populations, two contracts. A DIRECT offender is a hard zero — the
     * shape this census has always refused. An INDIRECT one (the assertion
     * reached through a same-file helper, E572.1) is a queued repair list
     * pinned by {@see self::indirectSwallowRoster()}: the ten AgentManagerTest
     * sites post-date E613's zero measurement, and the detector is worth
     * shipping on the day it first bites — a new indirect swallow reddens here
     * immediately, and the only legitimate edits to the roster are deletions.
     *
     * The third list is not a catch at all: the handler pairing census
     * (E572.2) pins every unrestored `set_error_handler` install, of which the
     * tree holds exactly one, dormant (E614, re-verified at this commit).
     */
    public function testNoTestSwallowsTheFailureStandingNextToIt(): void
    {
        $offenders = [];
        $queued = [];
        $handlerLeaks = [];
        $sites = 0;
        $files = 0;

        // NO SELF-EXCLUSION. This file used to skip itself as "the file that
        // documents the pattern", and MEASURED by mutation the skip bought
        // nothing: deleting it leaves this file green, because the fixtures are
        // assembled by concatenation and no whole offender is ever spelled here.
        // An exemption that buys nothing is not free — it is the one path by
        // which a real offender in this very file would go unreported, and
        // nothing would have pinned that the file stayed clean.
        foreach (self::testFiles() as $rel => $src) {
            $files++;
            $methods = self::classMethodsOf(token_get_all($src));
            foreach (self::scanSource($src, $rel) as $hit) {
                $sites++;
                if ($hit['via'] === 'helper' && $hit['verdict'] === 'offender') {
                    $queued[] = $rel . '::' . self::enclosingMethod($methods, $hit['line'])
                        . ' catch(' . $hit['type'] . ')';
                    continue;
                }
                $offenders[] = $rel . ':' . $hit['line'] . '  catch(' . $hit['type']
                    . ')  [' . $hit['verdict'] . ']';
            }
            foreach (self::unrestoredHandlersIn($src) as $leak) {
                $handlerLeaks[] = $rel . '::' . self::enclosingMethod($methods, $leak['line'])
                    . ' ' . ($leak['qualified'] ? 'qualified' : 'bare') . ' install';
            }
        }

        self::assertGreaterThan(
            0,
            $files,
            'the walk found no test files at all, so the empty offender list below is an artefact '
            . 'of a broken directory walk rather than a fact about the tree',
        );

        sort($queued);
        self::assertSame(
            self::indirectSwallowRoster(),
            $queued,
            "The indirect population moved. A NEW row means a test now wraps a call to a same-file\n"
            . "helper that asserts in a catch wide enough to eat that helper's own failure — the\n"
            . "shape E572 named and this census no longer ignores. The owning lane repairs it: the\n"
            . "helper returns its evidence and the CALLER asserts, or the catch names the\n"
            . "assertion-failure type first (behind which this census models a wide clause as safe,\n"
            . "E612). A VANISHED row means repair: delete it from\n"
            . "SugarCraft\\Crush\\Tests\\SwallowingCatchCensusTest::indirectSwallowRoster(). The\n"
            . "roster never grows as a way of making this test pass — an exemption row is a\n"
            . "licence (rule 33). Measured rows:\n",
        );

        sort($handlerLeaks);
        self::assertSame(
            self::unrestoredHandlerRoster(),
            $handlerLeaks,
            "The handler population moved. A NEW row is an error handler installed without a\n"
            . "restore inside the enclosing function's `finally` — PHP's warning-to-failure\n"
            . "conversion is then suppressed for every later test in the process, and no assert\n"
            . "can tell the suite. Restore it in a `finally` (or via the\n"
            . "`\\set_error_handler(\$previous)` re-install the trait file uses). A VANISHED row\n"
            . "means the dormant seam was wired or deleted: it is pinned as a whole roster entry,\n"
            . "so remove it from unrestoredHandlerRoster() and say which in the commit message.\n"
            . "Measured rows:\n",
        );

        sort($offenders);
        self::assertSame(
            [],
            $offenders,
            "A try body that ASSERTS is wrapped in a catch clause wide enough to catch PHPUnit's\n"
            . "own ExpectationFailedException. The test above passes whether its subject throws,\n"
            . "returns normally, or is deleted outright.\n\n"
            . "THE FIX IS NOT AN EXEMPTION. Move the `fail()` out of the try and assert after it:\n\n"
            . "    \$caught = null;\n"
            . "    try {\n"
            . "        \$subject->doTheThing();\n"
            . "    } catch (\\RuntimeException \$e) {\n"
            . "        \$caught = \$e;\n"
            . "    }\n"
            . "    \$this->assertNotNull(\$caught, 'doTheThing() should have thrown');\n"
            . "    \$this->assertSame('...', \$caught->getMessage());\n\n"
            . "If the assertion failure genuinely IS the subject under test, catch the\n"
            . "assertion-failure type BY NAME — AssertionFailedError, or the narrower\n"
            . "ExpectationFailedException if your own fail() must still escape — and this census\n"
            . "will accept it on the structure alone. The named clause must come FIRST: a wide\n"
            . "clause standing after one that already receives the failure is never reached (PHP\n"
            . "dispatches to the first match) and is not reported (E612).\n\n"
            . "A verdict of [unclassified] means something else: this census could not resolve\n"
            . "that catch type to a real class and refuses to guess. THAT IS NOT AUTOMATICALLY\n"
            . "YOUR CODE'S FAULT, and if the catch type is spelled correctly then THIS CENSUS is\n"
            . "the thing to fix, not your file -- do NOT answer it with an exemption. Check, in\n"
            . "this order: (1) is the type genuinely misspelled or genuinely missing an import?\n"
            . "Then fix the file. (2) Is it imported or declared in a way resolve()/useMap()\n"
            . "cannot follow -- a conditional class_alias, a name built at runtime? Then teach\n"
            . "the classifier and pin BOTH polarities with a fixture, the way the group-import\n"
            . "and same-namespace shapes are pinned in the test above.\n\nOffenders:",
        );
    }

    // -------------------------------------------------------------------------
    // the scanner
    // -------------------------------------------------------------------------

    /**
     * Build a fixture source without ever spelling a whole offender literally.
     *
     * `$prelude` carries whatever has to sit between the open tag and the class
     * — a `namespace` line, an import — so the RESOLUTION cases can be driven
     * through the same builder as the classification ones.
     */
    private static function fixture(string $body, string $catchType, string $prelude = ''): string
    {
        return self::clausesFixture($body, [$catchType . ' $e' => '$x = 1;'], $prelude);
    }

    /**
     * Multi-clause fixture source for the E612 ordering arms: every entry of
     * `$clauses` is one `catch`, in the order written, type (with its variable
     * if the arm needs one) => body. The single-clause {@see self::fixture()}
     * is the one-entry case, so every resolution and classification fixture in
     * this file drives the same clause machinery the tree scan does.
     *
     * @param array<string, string> $clauses
     */
    private static function clausesFixture(string $body, array $clauses, string $prelude = ''): string
    {
        $chain = '';
        foreach ($clauses as $type => $clauseBody) {
            $chain .= "    } catch (" . $type . ") {\n      " . $clauseBody . "\n";
        }

        return "<?php\n" . $prelude . "class F {\n  public function t(): void {\n    try {\n      "
            . $body . "\n" . $chain . "    }\n  }\n}\n";
    }

    /**
     * Fixture source carrying helper methods, for the E572.1 arms: one test
     * method whose statement is `$tryCatch` verbatim, plus the named helpers
     * the same-file walk must follow (or fail to follow, which is the point of
     * the clean and recursive negatives).
     *
     * @param array<string, string> $helpers method name => body
     */
    private static function helpersFixture(string $tryCatch, array $helpers, string $prelude = ''): string
    {
        $members = "  public function t(): void {\n    " . $tryCatch . "\n  }\n";
        foreach ($helpers as $name => $helperBody) {
            $members .= '  public function ' . $name . "(): void {\n    " . $helperBody . "\n  }\n";
        }

        return "<?php\n" . $prelude . "class H {\n" . $members . "}\n";
    }

    /**
     * Fixture source for the handler pairing census: one method that installs
     * `$install`, runs a non-asserting risky region inside try/catch, and
     * carries `$finallyBody` in the `finally` (when non-empty) with
     * `$straightLine` after the whole statement (when non-empty). The two
     * restore positions are the polarity E614 measured: the tree's paired
     * sites restore in `finally`, and a straight-line restore is skipped by any
     * throw between install and restore, which is precisely the leak.
     */
    private static function handlerFixture(string $install, string $finallyBody, string $straightLine): string
    {
        $finally = $finallyBody === '' ? '' : "     finally {\n      " . $finallyBody . "\n    }\n";

        return "<?php\nclass G {\n  public function g(): void {\n    " . $install . "\n"
            . "    try {\n      doSomething();\n    } catch (\\Exception \$e) {\n      \$x = 1;\n    }\n"
            . $finally
            . ($straightLine === '' ? '' : "    " . $straightLine . "\n")
            . "  }\n}\n";
    }

    /**
     * The queued indirect swallows (E572.1), as measured at 61cde19c5.
     *
     * TEN sites, all `catch (\RuntimeException)` in Agents/AgentManagerTest.php
     * — record-for-later shapes whose SUBJECT is `captureSubAgentRequest()`, a
     * helper that asserts internally (`assertInstanceOf(CompleteRequest::class,
     * $captured, 'the provider was never called')`). They post-date E613's
     * zero measurement; the census caught them the first run after the
     * indirect arm shipped, which is what a detector is for.
     *
     * SHRINK-ONLY. A row leaves when the owning lane repairs the site (the
     * helper returns its evidence and the caller asserts, or the catch names
     * the assertion-failure type first — behind which this census models a
     * wide clause as safe, E612). A row never arrives as a way of making the
     * test pass (rule 33).
     *
     * @return list<string> sorted
     */
    private static function indirectSwallowRoster(): array
    {
        $roster = [
            'Agents/AgentManagerTest.php::testAMalformedDeclarationIsRefused catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testAMalformedDenylistEntryIsRefusedAndNamesTheField catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testANonStringDeclarationIsRefused catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testANonToolRegistryEntryIsRefused catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testANonToolUniverseEntryIsRefused catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testAPolicyNarrowedRegistryIsIndistinguishableFromATypo catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testAPolicyNarrowedRegistryIsIndistinguishableFromATypo catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testAPolicyNarrowedRegistryIsIndistinguishableFromATypo catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testAnEmptyRegistryRefusesEveryDeclaration catch(\\RuntimeException)',
            'Agents/AgentManagerTest.php::testAnUnresolvableGrantIsRefusedRatherThanDropped catch(\\RuntimeException)',
        ];
        sort($roster);

        return $roster;
    }

    /**
     * The unrestored `set_error_handler` installs in the tree, as measured at
     * this commit: ONE, dormant.
     *
     * `Skills/SkillLoaderTest.php::suppressErrorLog()` installs and never
     * restores (its sibling `restoreErrorHandler()` exists but has ZERO
     * callers — grep re-verified at 61cde19c5, matching E614's measurement).
     * A dormant seam is pinned, not deleted, per rule 6; wiring or removing
     * it is the owning lane's call, and the first caller it gains turns this
     * census red with reason — the leak would then be live.
     *
     * @return list<string> sorted
     */
    private static function unrestoredHandlerRoster(): array
    {
        $roster = ['Skills/SkillLoaderTest.php::suppressErrorLog bare install'];
        sort($roster);

        return $roster;
    }

    /**
     * @return array<string, string> repo-relative path => source
     */
    private static function testFiles(): array
    {
        $root = \dirname(__DIR__) . '/tests';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $out[substr($f->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($f->getPathname());
        }

        return $out;
    }

    /**
     * @return list<array{line:int,type:string,verdict:string,via:string}>
     *
     *  `via` is 'direct' when the try body itself asserted and 'helper' when
     *  the assertion was reached through a SAME-FILE helper (E572.1); the tree
     *  scan separates the two because the helper population is a queued repair
     *  roster while the direct population is a hard zero.
     */
    private static function scanSource(string $src, string $rel): array
    {
        $tokens = token_get_all($src);
        $n = \count($tokens);
        $uses = self::useMap($tokens);
        $namespace = self::namespaceOf($tokens);
        $out = [];
        // Built once per file, on the first try whose body does not assert
        // directly — the common case for wide catches around non-asserting
        // code must not pay for a method walk it will never use.
        $methods = null;

        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_TRY) {
                continue;
            }

            $j = $i;
            while ($j < $n && $tokens[$j] !== '{') {
                $j++;
            }
            if ($j >= $n) {
                continue;
            }

            [$body, $j] = self::block($tokens, $j, $n);
            $via = self::bodyAsserts($body) ? 'direct' : '';
            if ($via === '') {
                $methods ??= self::classMethodsOf($tokens);
                if (self::assertsViaSameFileHelpers($body, $methods)) {
                    $via = 'helper';
                }
            }
            $asserts = $via !== '';

            // E612. A failing assertion is dispatched to the FIRST catch
            // clause that can receive it, and a catch body that rethrows
            // escapes the whole statement — a later clause can NEVER see the
            // failure once an earlier one has caught it. Without this the
            // census reports round 58's own prescribed repair
            // (`catch (AssertionFailedError) { throw; } catch (Throwable) {}`)
            // as an offender, and a false alarm on an emptiness guard is
            // answered with an exemption row (rule 33).
            $consumed = false;

            $k = $j + 1;
            while ($k < $n) {
                while ($k < $n && \is_array($tokens[$k])
                    && \in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $k++;
                }
                if ($k >= $n || !\is_array($tokens[$k]) || $tokens[$k][0] !== T_CATCH) {
                    break;
                }

                $catchLine = $tokens[$k][2];
                while ($k < $n && $tokens[$k] !== '(') {
                    $k++;
                }
                $type = '';
                $paren = 0;
                for (; $k < $n; $k++) {
                    $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                    if ($txt === '(') {
                        $paren++;
                        if ($paren === 1) {
                            continue;
                        }
                    }
                    if ($txt === ')') {
                        $paren--;
                        if ($paren === 0) {
                            break;
                        }
                    }
                    $type .= $txt;
                }

                if ($asserts) {
                    // multi-catch: `catch (A|B $e)`. The variable is optional in PHP 8.
                    $clauseReceivesFailure = false;
                    foreach (explode('|', (string) preg_replace('/\$\w+/', '', $type)) as $one) {
                        $one = trim($one);
                        if ($one === '') {
                            continue;
                        }
                        if (self::catchesAssertionFailure($one, $uses, $namespace)) {
                            $clauseReceivesFailure = true;
                        }
                        $verdict = self::classify($one, $uses, $namespace);
                        if ($verdict === 'safe') {
                            continue;
                        }
                        // An unclassifiable type is reported wherever it
                        // stands: ordering can make a clause unreachable for
                        // the failure, it cannot make an unreadable name
                        // readable (the fail-closed third limit).
                        if ($verdict === 'offender' && $consumed) {
                            continue;
                        }
                        $out[] = ['line' => $catchLine, 'type' => $one, 'verdict' => $verdict, 'via' => $via];
                    }
                    $consumed = $consumed || $clauseReceivesFailure;
                }

                while ($k < $n && $tokens[$k] !== '{') {
                    $k++;
                }
                [, $k] = self::block($tokens, $k, $n);
                $k++;
            }
        }

        unset($rel);

        return $out;
    }

    /**
     * BRACE DEPTH IS A FACT ABOUT TOKENS, NOT ABOUT TEXT — IN BOTH DIRECTIONS.
     *
     * WHAT THIS SAID BEFORE: "every token the running PHP uses to open a brace,
     * not just the character" — a completeness claim about OPENERS only.
     *
     * WHAT IS TRUE NOW: that heading named half the family. A walk over
     * `token_get_all()` that decides on extracted TEXT can be wrong two ways,
     * and this method was wrong both:
     *
     *   1. A TOKEN THAT OPENS A BRACE WITHOUT SPELLING `{`. `"${y}"` arrives as
     *      `T_DOLLAR_OPEN_CURLY_BRACES`, spelled `${`, while its closing `}` is
     *      an ordinary character token. Counting only the character decremented
     *      a level that was never incremented, the walk left this method early,
     *      and the scan silently stopped matching after the first such string —
     *      a clean bill of health for a file the scanner had stopped reading.
     *      `"{$x}"` arrives as `T_CURLY_OPEN`, whose text IS `{`, so it happened
     *      to work; it is named anyway, because relying on a token's text
     *      matching its role is what produced the bug.
     *   2. A TOKEN WHOSE TEXT IS A BRACE BUT WHICH IS NOT A BRACE. MEASURED on
     *      PHP 8.3.6: `token_get_all()` on a double-quoted string holding a
     *      simple variable followed by a close brace yields a
     *      `T_ENCAPSED_AND_WHITESPACE` whose text is EXACTLY `}`; the open-brace
     *      spelling yields one whose text is exactly `{`. Extracting text first
     *      therefore counted braces that live inside a string literal. MEASURED
     *      through this scanner before the gate below existed: an offender whose
     *      try body carried either spelling was reported ZERO times — the same
     *      defect class as (1), one token short of the family.
     *
     * So both comparisons are now gated on the token being a bare character
     * token, and the two interpolation openers are named explicitly. Every other
     * brace walk in this tree compares the RAW token rather than its text and is
     * immune to (2) already; this method was the only one extracting text first,
     * which is why it was the only one wrong in both directions.
     *
     * WHY THIS STILL EARNS ITS PLACE: at this commit, on PHP 8.3.6, `tests/`
     * holds no token whose text is a lone brace and which is not the brace
     * character, so neither defect changed any census answer — both removed a
     * hole that opens the first time somebody writes one inside a try body.
     * That reachability is prose and is deliberately asserted nowhere; the
     * MECHANISM is pinned by the fixtures in
     * {@see self::testTheScannerIsAliveInBothPolarities()}, which do not depend
     * on what the tree happens to contain this week.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:string,1:int} the brace-balanced text, and the index of its closing brace
     */
    private static function block(array $tokens, int $open, int $n): array
    {
        $depth = 0;
        $text = '';
        for ($k = $open; $k < $n; $k++) {
            $token = $tokens[$k];
            $txt = \is_array($token) ? $token[1] : $token;
            // `is_string()` is the whole point: a bare character token IS the
            // brace, while an array token whose text merely reads `{` or `}` is
            // a fragment of a string literal.
            $isPunctuation = \is_string($token);
            $opensABrace = ($isPunctuation && $txt === '{')
                || (\is_array($token) && \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true));
            if ($opensABrace) {
                $depth++;
            }
            if ($depth > 0) {
                $text .= $txt;
            }
            if ($isPunctuation && $txt === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$text, $k];
                }
            }
        }

        return [$text, $n - 1];
    }

    /**
     * The alphabet is part of the coverage, so it is widened past the shape the
     * known offenders happened to use: a method call on $this/self/static, a
     * static call on ANY class (`Assert::assertSame(...)`), and the function
     * form PHPUnit 10 exports (`use function PHPUnit\Framework\assertSame`).
     */
    private static function bodyAsserts(string $body): bool
    {
        return (bool) preg_match(
            '/(?:(?:\$this|self|static|[A-Z]\w*)\s*(?:->|::)\s*)?\b(?:assert[A-Z_]\w*|fail|expectException\w*)\s*\(/',
            $body,
        );
    }

    /**
     * Whether a clause naming THIS type can receive a failing assertion at
     * all — the question E612's ordering rule asks of every clause, whatever
     * verdict the clause itself earns.
     *
     * Only a type that RESOLVES to a real class can consume the failure. An
     * unresolvable name never marks later clauses unreachable: its own row is
     * fail-closed reported anyway, and assuming a match from a name this file
     * cannot read is exactly the guessing the third limit refuses.
     *
     * @param array<string, string> $uses
     */
    private static function catchesAssertionFailure(string $written, array $uses, string $namespace): bool
    {
        $fqn = self::resolve($written, $uses, $namespace);

        return $fqn !== null
            && (class_exists($fqn) || interface_exists($fqn))
            && is_a(\PHPUnit\Framework\ExpectationFailedException::class, $fqn, true);
    }

    /**
     * The same-file method declarations: name => body text, line span, and
     * token span.
     *
     * Three consumers, one walk: the indirect arm of E572.1 needs the bodies;
     * the tree scan needs the line spans (a roster row is keyed to its
     * enclosing method, because line numbers rot the moment the owning lane
     * repairs a neighbouring test); the handler census needs the token spans
     * to collect `finally` blocks. Closures are skipped (T_FUNCTION not
     * followed by a name is never the target of a `$this->` call); abstract
     * and interface declarations have no body to walk. A file declaring the
     * same method name twice — class plus trait in one file — keeps the LAST
     * declaration; no test file does this, and the walk answers for method
     * BODIES, which is all the arms read.
     *
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @return array<string, array{body:string, firstLine:int, lastLine:int, firstIndex:int, lastIndex:int}>
     */
    private static function classMethodsOf(array $tokens): array
    {
        $n = \count($tokens);
        $index = [];

        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $k = $i + 1;
            while ($k < $n && \is_array($tokens[$k])
                && \in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $k++;
            }
            if ($k >= $n || !\is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) {
                continue; // a closure — anonymous, never named by a call
            }
            $name = $tokens[$k][1];

            // past the parameter list: match the parentheses, then the body
            // opener; a `;` before the `{` is an abstract/interface signature.
            while ($k < $n && $tokens[$k] !== '(') {
                $k++;
            }
            $depth = 0;
            for (; $k < $n; $k++) {
                $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($txt === '(') {
                    $depth++;
                } elseif ($txt === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $k++;
                        break;
                    }
                }
            }
            while ($k < $n) {
                $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($txt === ';') {
                    continue 2;
                }
                if ($txt === '{') {
                    break;
                }
                $k++;
            }
            [$body, $end] = self::block($tokens, $k, $n);

            // The closing brace is a bare character token with no line of its
            // own; the nearest array token before it is a few lines away at
            // worst, and every lookup asks about strictly inner lines.
            $last = $end;
            while ($last > 0 && !\is_array($tokens[$last])) {
                $last--;
            }

            $index[$name] = [
                'body' => $body,
                'firstLine' => $tokens[$i][2],
                'lastLine' => \is_array($tokens[$last]) ? $tokens[$last][2] : $tokens[$end - 1][2],
                'firstIndex' => $k,
                'lastIndex' => $end,
            ];
            $i = $end;
        }

        return $index;
    }

    /**
     * Whether $body calls a same-file helper that asserts, transitively.
     *
     * The E572.1 walk, with the E613 generator's discipline kept whole: the
     * call alphabet is `$this->h(`, `self::h(`, `static::h(`; each name is
     * looked up in {@see self::classMethodsOf()} and its body judged by the
     * SAME alphabet {@see self::bodyAsserts()} uses, so the indirect arm
     * reproduces the direct one exactly one frame deeper. Visited-set,
     * depth-bounded at 8: mutual recursion must answer no and TERMINATE — an
     * unbounded walk hangs the suite instead of failing it.
     *
     * STILL INVISIBLE, stated so no reader inherits an overclaim: a helper
     * declared in a PARENT class or a TRAIT — this walk is same-file, as
     * bounded by E613's caveat. Cross-file expansion was not built.
     *
     * @param array<string, array{body:string, firstLine:int, lastLine:int, firstIndex:int, lastIndex:int}> $methods
     * @param array<string, true> $visited
     */
    private static function assertsViaSameFileHelpers(string $body, array $methods, array $visited = [], int $depth = 8): bool
    {
        if ($depth <= 0) {
            return false;
        }
        if (!preg_match_all('/(?:\$this|self|static)\s*(?:->|::)\s*([A-Za-z_]\w*)\s*\(/', $body, $calls)) {
            return false;
        }

        foreach ($calls[1] as $name) {
            if (isset($visited[$name]) || !isset($methods[$name])) {
                continue;
            }
            $visited[$name] = true;
            $helper = $methods[$name]['body'];
            if (self::bodyAsserts($helper)
                || self::assertsViaSameFileHelpers($helper, $methods, $visited, $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The method a census row stands in, for roster keys. Line numbers rot
     * when the owning lane repairs a neighbouring test; method names do not.
     *
     * @param array<string, array{body:string, firstLine:int, lastLine:int, firstIndex:int, lastIndex:int}> $methods
     */
    private static function enclosingMethod(array $methods, int $line): string
    {
        foreach ($methods as $name => $span) {
            if ($span['firstLine'] <= $line && $line <= $span['lastLine']) {
                return $name;
            }
        }

        return '(file scope)';
    }

    /**
     * The `set_error_handler` installs this source never restores inside a
     * `finally` (E572.2; the property and its measured population are E614's).
     *
     * BOTH TOKEN KINDS count as installs — `set_error_handler(` is a T_STRING,
     * while `\set_error_handler(` arrives as ONE T_NAME_FULLY_QUALIFIED token
     * whose text carries the leading slash, the exact miss that silently
     * dropped a whole file from the round-59 scanner. A bare-name install
     * reached through `->` or `::` or preceded by `function` is a method, not
     * the PHP function, and is skipped; the fully qualified form cannot be a
     * method call by construction.
     *
     * PAIRING is per enclosing function and demands the restore sit in a
     * `finally`: a straight-line restore is skipped by any throw between
     * install and restore, and the leaked handler then silences PHPUnit's
     * warning-to-failure conversion for EVERY LATER TEST IN THE PROCESS. The
     * restore side accepts `restore_error_handler(` or the
     * `set_error_handler($previous)` re-install the tree's own save/restore
     * shape uses — E614 counted those sites as restored, and a scanner that
     * reds on the tree's legitimate shape is answered with exemption rows.
     *
     * @return list<array{line:int,qualified:bool}>
     */
    private static function unrestoredHandlersIn(string $src): array
    {
        $tokens = token_get_all($src);
        $n = \count($tokens);
        $methods = self::classMethodsOf($tokens);

        $installs = [];
        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($tokens[$i])) {
                continue;
            }
            $qualified = $tokens[$i][0] === T_NAME_FULLY_QUALIFIED && $tokens[$i][1] === '\\set_error_handler';
            $bare = $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'set_error_handler';
            if (!$qualified && !$bare) {
                continue;
            }
            if ($bare && self::nameIsMethodCallOrDeclaration($tokens, $i)) {
                continue;
            }
            $installs[] = ['line' => $tokens[$i][2], 'qualified' => $qualified, 'index' => $i];
        }

        $unrestored = [];
        foreach ($installs as $install) {
            $finallyText = '';
            foreach ($methods as $span) {
                if ($span['firstIndex'] <= $install['index'] && $install['index'] <= $span['lastIndex']) {
                    $finallyText = self::finallyTextBetween($tokens, $span['firstIndex'], $span['lastIndex'], $n);
                    break;
                }
            }
            if (!preg_match('/(?:restore_error_handler|set_error_handler)\s*\(/', $finallyText)) {
                $unrestored[] = ['line' => $install['line'], 'qualified' => $install['qualified']];
            }
        }

        return $unrestored;
    }

    /**
     * Whether the T_STRING at $i is a method call or a declaration rather
     * than the PHP function: the previous significant token is `->`, `::`, or
     * `function`. (The fully qualified spelling needs none of this — a leading
     * `\` is never a member access.)
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nameIsMethodCallOrDeclaration(array $tokens, int $i): bool
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (\is_array($tokens[$k])
                && \in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return \is_array($tokens[$k])
                && \in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true);
        }

        return false;
    }

    /**
     * The concatenated text of every `finally { … }` inside the token range.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function finallyTextBetween(array $tokens, int $from, int $to, int $n): string
    {
        $text = '';
        for ($i = $from; $i <= $to && $i < $n; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_FINALLY) {
                continue;
            }
            $k = $i;
            while ($k < $n && $tokens[$k] !== '{') {
                $k++;
            }
            if ($k >= $n) {
                continue;
            }
            [$block, ] = self::block($tokens, $k, $n);
            $text .= $block . "\n";
        }

        return $text;
    }

    /**
     * @param array<string, string> $uses
     * @return 'safe'|'offender'|'unclassified'
     */
    private static function classify(string $written, array $uses, string $namespace = ''): string
    {
        $fqn = self::resolve($written, $uses, $namespace);

        if ($fqn === null || !(class_exists($fqn) || interface_exists($fqn))) {
            return 'unclassified';
        }

        // Would this catch clause receive a failing assertion?
        if (!is_a(\PHPUnit\Framework\ExpectationFailedException::class, $fqn, true)) {
            return 'safe';
        }

        // It would — but naming an assertion-failure type is the deliberate,
        // legitimate shape: the failure IS the subject under test.
        if (is_a($fqn, AssertionFailedError::class, true)) {
            return 'safe';
        }

        return 'offender';
    }

    /**
     * WHAT THIS SAID BEFORE: "every catch type in this tree that is not
     * imported is a global one, so try global and let `classify()` report
     * anything else as unclassified rather than guessing."
     *
     * WHAT IS TRUE NOW: the first half is still MEASURED true — a walk over
     * `sugar-crush/tests` at this commit finds no catch type that is neither
     * fully qualified nor imported — but the conclusion did not follow. PHP
     * resolves an unqualified class name against the CURRENT namespace and does
     * NOT fall back to global, so a file that catches a type declared beside it
     * was resolved to a global name that does not exist, and the census reported
     * correct code as `[unclassified]` and went red on it. That is the shape
     * where the classifier, not the code, is the defect.
     *
     * WHY THIS STILL EARNS ITS PLACE: the ordering below is deliberately more
     * permissive than PHP. The current namespace is tried FIRST, because that is
     * what the language does; global is kept as a fallback because this tree's
     * convention is to spell a global type with a leading `\` and a file that
     * forgets the slash means the global one. Being permissive here can only
     * turn an `[unclassified]` into a real verdict — it can never turn an
     * offender into `safe`, because `classify()` still asks the live hierarchy
     * about whatever class it ends up with.
     *
     * THE DIRECTION THAT BITES, PINNED by the Demo578 fixtures (E615,
     * correcting what E578 prescribed): on a bare unimported name the fallback
     * ANSWERS FOR A CLASS PHP WOULD NEVER HAVE LOADED — the sentence above is
     * literally true, but the flip it enables is `[unclassified]` →
     * `offender`, not the harmless `[unclassified]` → `safe` one. A
     * `catch (RuntimeException)` inside a namespace with no such class never
     * matches at all (MEASURED: the exception propagates straight past it),
     * and this resolver still reports it as a swallow. That is a false alarm
     * on a clause the language cannot reach — which is why the cost is stated
     * rather than absorbed: the tree holds ZERO bare-unimported catch types
     * (re-measured at this commit), so the permissiveness buys convention
     * forgiveness at no present cost, and any reader of a `safe`/`offender`
     * verdict on a name PHP would not have loaded must know it describes the
     * GLOBAL class, NOT the clause's real behaviour.
     *
     * @param array<string, string> $uses
     */
    private static function resolve(string $written, array $uses, string $namespace = ''): ?string
    {
        $t = trim($written);
        if ($t === '') {
            return null;
        }
        if (str_starts_with($t, '\\')) {
            return ltrim($t, '\\');
        }

        $head = explode('\\', $t)[0];
        if (isset($uses[$head])) {
            $rest = substr($t, \strlen($head));

            return $uses[$head] . $rest;
        }

        if ($namespace !== '' && (class_exists($namespace . '\\' . $t) || interface_exists($namespace . '\\' . $t))) {
            return $namespace . '\\' . $t;
        }

        return $t;
    }

    /**
     * The file's own namespace, which is where PHP resolves an unqualified
     * catch type that carries no import.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function namespaceOf(array $tokens): string
    {
        $n = \count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_NAMESPACE) {
                continue;
            }
            $name = '';
            for ($k = $i + 1; $k < $n; $k++) {
                $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($txt === ';' || $txt === '{') {
                    break;
                }
                $name .= $txt;
            }

            return trim($name, " \t\n\\");
        }

        return '';
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string> $tokens
     * @return array<string, string> local alias => fully qualified name
     */
    private static function useMap(array $tokens): array
    {
        $n = \count($tokens);
        $map = [];

        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }
            // A `use` inside a class body (trait import) or a closure `use (...)`
            // is not an import; both are followed by something other than a
            // plain qualified name at statement level, and the `;` scan below
            // simply yields a name we then fail to resolve rather than a wrong one.
            // A group import — `use A\\B\\{C, D as E};` — is a `{` at statement
            // level, and stopping there used to leave every name in the group
            // unmapped while recording a bogus empty-string alias for the
            // prefix. It is picked up here by carrying the prefix into the
            // braces. A closure's `use (...)` still stops at the `(`, and a
            // trait import inside a class body reaches a `{` with no `\` in the
            // prefix, which the guard below rejects.
            $stmt = '';
            $prefix = '';
            for ($k = $i + 1; $k < $n; $k++) {
                $txt = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($txt === '(') {
                    break;
                }
                if ($txt === '{') {
                    $candidate = trim($stmt);
                    if (!str_contains($candidate, '\\')) {
                        break; // a trait import, not a group import
                    }
                    $prefix = ltrim($candidate, '\\');
                    $stmt = '';

                    continue;
                }
                if ($txt === ';' || $txt === '}') {
                    break;
                }
                $stmt .= $txt;
            }
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, 'function ') || str_starts_with($stmt, 'const ')) {
                continue;
            }
            foreach (explode(',', $stmt) as $one) {
                $one = trim($one);
                if ($one === '') {
                    continue;
                }
                if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $one, $m)) {
                    $map[$m[2]] = $prefix . ltrim(trim($m[1]), '\\');
                } else {
                    $fq = $prefix . ltrim($one, '\\');
                    $map[substr($fq, (int) strrpos('\\' . $fq, '\\'))] = $fq;
                }
            }
        }

        return $map;
    }
}
