<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The token-stream scanners in this suite all walk braces, and PHP opens some
 * braces with an ARRAY token whose closer is a plain `}`. Every one of those
 * openers has to be in every scanner's list, and the list is DERIVED FROM THE
 * INTERPRETER rather than written down.
 *
 * WHY THIS FILE EXISTS. Miss an opener and a brace walk loses a level, which
 * is not a crash: it is a scanner that silently stops seeing things. Both
 * failures of exactly this shape are already recorded in the tree -
 * {@see ChildStderrCaptureScanner::classifyProcOpen()} reported a correctly
 * capturing `proc_open("php {$script}", ...)` as `unclassified`, and
 * {@see ReaperAdoptionScanner} stopped recognising a class-body `use` after
 * an interpolated string. Both were found by accident, one at a time, and
 * both were fixed by adding `T_DOLLAR_OPEN_CURLY_BRACES` beside
 * `T_CURLY_OPEN` in one more place.
 *
 * WHY IT IS DERIVED AND NOT A LITERAL PAIR. `${...}` interpolation is
 * DEPRECATED as of PHP 8.2 and slated for removal, so
 * `T_DOLLAR_OPEN_CURLY_BRACES` is a constant a future PHP may stop defining -
 * at which point every list naming it becomes an `Error: Undefined constant`
 * rather than a scanner bug, and every list NOT naming its replacement
 * becomes a silent hole. This box has only PHP 8.3.6; CI also runs 8.4, and
 * neither of those can be made to answer for PHP 9. A guard that asks the
 * running interpreter answers on whatever version is running it.
 *
 * WHAT AN EARLIER ENTRY SAID: that the constant is 8.2-deprecated, that it is
 * referenced from TWO files, and that "a deprecation notice on 8.4 is a real
 * risk you cannot test".
 *
 * WHAT IS TRUE NOW, re-measured on PHP 8.3.6 with an error handler recording
 * every diagnostic rather than by reading output. The constant is defined
 * (395). REFERENCING it emits nothing at all - zero diagnostics - because the
 * deprecation is on the `"${a}"` SYNTAX and not on the token. LEXING a source
 * that does use that syntax emits nothing either, and still produces the
 * token, because `token_get_all()` lexes rather than compiles. COMPILING the
 * same source DOES emit one `E_DEPRECATED` - which is the control that keeps
 * the two facts above from being a statement about a measurement that reports
 * nothing whatever it is handed. And no file in `src/` or `tests/` uses the
 * syntax at all, which is pinned by
 * {@see testNoFileUsesTheDeprecatedInterpolationSyntax()} rather than
 * asserted here. So "a deprecation notice on 8.4" is not the risk. The risk
 * is REMOVAL, it is further out than 8.4, and it is a hard error rather than
 * a notice - which is what this file is pointed at.
 *
 * AND THE FILE COUNT WAS THE REASON THE PROPOSED FIX WAS THE WRONG SHAPE. It
 * is not two. The count itself is not written here - a cardinality over
 * `tests/` is wrong by the next merge - but the SPREAD is, and it is checked:
 * {@see testTheConstantIsNamedAcrossDirectoriesNoOneLaneOwns()} derives the
 * naming set from the tree and requires it to span several directories, which
 * is the property that makes "edit the literal pair" an edit no single lane
 * can make. Note which file is NOT in that set: this one. It reaches the
 * constant only through `defined()` and string literals, deliberately, so the
 * guard cannot be the thing that fatals on the PHP it exists to warn about.
 *
 * WHAT THE FIRST VERSION OF THIS FILE GOT WRONG, kept rather than quietly
 * corrected because each mistake names a hole a reader could reopen:
 *
 *   * ITS FILE ALPHABET COULD NOT EXPRESS A LIVE OFFENDER. It globbed
 *     `tests/Support/*.php`, non-recursively, and reported zero gaps. The
 *     walk now covers `tests/` and `src/` entire, and the very first thing
 *     it found was {@see KNOWN_GAPS}' one row - a real brace walker, in the
 *     tree the whole time, one directory up from where the guard was
 *     looking. A census's alphabet is its coverage, and this one had been
 *     written to match the cases already known.
 *   * ITS SELECTION PREDICATE HAD NO KNOWN-POSITIVE. It asserted `[]` and
 *     nothing in the same test proved the scanner could still produce a
 *     non-empty answer; measured, a `constantsNamedInCode()` mutated to a
 *     hard-coded over-broad list left the whole file green.
 *     {@see assertTheScannerIsAlive()} is the answer.
 *   * ITS "STILL DEFINED" GUARD WAS INVERTED. It iterated the openers the
 *     LEXER produces, so on the PHP that removes `${}` the roster would
 *     simply shrink and the constant nobody can evaluate any more would
 *     never be checked - while every file that names it hard-errored. It
 *     now iterates what the SCANNERS NAME, which is the set that can
 *     fatal.
 */
final class InterpolationOpenerTokenTest extends TestCase
{
    /**
     * Sources whose braces are opened by an array token and closed by a plain
     * `}`. Each is a real spelling of string interpolation, so the set below
     * is whatever the running PHP actually produces for them.
     *
     * @var list<string>
     */
    private const INTERPOLATIONS = [
        '<?php $a = 1; $s = "x{$a}y";',
        '<?php $o = new stdClass(); $s = "x{$o->p}y";',
        '<?php $a = [1]; $s = "x{$a[0]}y";',
        '<?php $a = 1; $s = <<<T
            x{$a}y
            T;',

        // THE DEPRECATED SPELLING, and the reason this array holds SOURCE
        // STRINGS rather than being written as real code. `${a}` inside a
        // single-quoted PHP string is data: this file never compiles it, so
        // it cannot emit the 8.2 deprecation on 8.4 or anywhere else, and a
        // token census of `tests/` still finds zero uses of the syntax. But
        // `token_get_all()` lexes rather than compiles, so it still reports
        // the opener the scanners have to handle for as long as PHP emits one.
        // The day it stops, this row contributes nothing and the roster
        // shrinks on its own instead of this test having to be edited.
        '<?php $a = 1; $s = "x${a}y";',
    ];

    /**
     * Brace walkers that do NOT name every opener, with the reason each is
     * still outstanding.
     *
     * NOT AN EXEMPTION LIST AND NOT A COMMENT. Every row is checked against
     * the tree by {@see testEveryBraceWalkingScannerNamesEveryOpener()},
     * which fails both when a listed file has stopped being a gap (delete the
     * row) and when a gap appears that has no row. Same shape, and for the
     * same reason, as {@see ForkedChildReaperAdoptionTest::OUT_OF_SCOPE}: a
     * deferral is a claim about the tree, so the tree is asked.
     *
     * WHY A ROW RATHER THAN A FIX. The walk that produced the one row this
     * constant has ever held was widened from `tests/Support/` to the whole
     * of `tests/` and `src/` by a round whose file list was `tests/Support/`
     * and `tests/Backend/`. The fix is one token per counter and belongs to
     * whoever owns the file; recording it here made the obligation visible
     * and counted, which an invisible one was not for as long as the guard
     * could not see the directory it lived in.
     *
     * WHAT THIS SAID while it had one row: `tests/VhsTapeContractTest.php`'s
     * two depth counters never incremented on the deprecated opener. WHAT IS
     * TRUE NOW: both counters name it, that row is gone - and widening the
     * SELECTION found three more walkers the guard had never been able to
     * see, which is why the map is not empty. WHY THE MECHANISM STILL EARNS
     * ITS PLACE whatever the map holds: a deferral recorded here is checked
     * against the tree in every direction it can be wrong - the gap with no
     * row, the row with no gap, and the row that no longer describes its gap -
     * and an empty map would still be the only place the next one can go.
     *
     * ALL THREE ROWS BELOW CAME FROM ONE WIDENING, and that is the entry
     * worth reading twice. Selection used to be "names `T_CURLY_OPEN` as
     * code", which is a walker that already knows the problem exists. A
     * walker that counts depth on the BARE one-byte strings knows nothing
     * about it, misses the everyday `{$x}` spelling as well as the deprecated
     * one, and was invisible to this guard by construction - the alphabet had
     * been written to match the cases already known.
     *
     * A ROW RECORDS WHICH OPENERS THE FILE IS MISSING, NOT JUST THAT IT IS
     * MISSING SOME. Keying a deferral on the FILENAME alone was the weaker
     * shape this file shipped with, and it fails in the direction a deferral
     * always fails: a PARTIAL fix. A walker that gained `T_CURLY_OPEN` and not
     * the deprecated opener still has A gap, so a file-keyed row still matched
     * it, and nothing reported that the row's own prose - which says this one
     * misses the everyday spelling too - had become false. With the set
     * recorded, that fix reds here and says which half was closed. The set is
     * intersected with the LIVE roster before comparing, so a PHP that removes
     * the deprecated opener shrinks both sides together instead of reddening
     * every row at once.
     *
     * @var array<string,array{openers:list<string>,reason:string}>
     */
    private const KNOWN_GAPS = [
        'tests/Cli/BootstrapLaunchFormatConstantsTest.php' => [
            'openers' => ['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'],
            'reason' =>
            'methodBody() counts depth on the BARE string `{` over a token_get_all() stream, '
            . 'where T_CURLY_OPEN comes back as an ARRAY token and its closer comes back as a '
            . 'bare `}` - so it misses the everyday spelling, not just the deprecated one, and '
            . 'the count goes one closer over. IT FAILS OPEN, which is why this row is first: '
            . 'measured on PHP 8.3.6 through the shipped private methods by reflection, one '
            . '`"{$x}"` inserted into a scanned method cut the body from 16 significant tokens '
            . 'to 6 and made a format literal that '
            . 'testNoMethodThatOwnsANamedFormatAlsoHoldsALiteralOne() exists to reject '
            . 'invisible - [] where the offender should have been. Latent only because no '
            . 'Bootstrap method it reads carries an interpolation today: measured through the '
            . 'SHIPPED methodBody() against a corrected walk, over the method set derived from '
            . 'the shipped obligations(), which agree on every one. A COUNT WAS RETIRED FROM '
            . 'THIS ROW: an earlier draft said "the eight Bootstrap methods it reads", which '
            . 'was both wrong - the derived set is smaller - and not the claim, since the set '
            . 'is derived and a cardinality in prose is wrong by the next merge. tests/Cli/ '
            . "was in no lane's file list for the round that found this.",
        ],
        'tests/Commands/SlashDispatchTest.php' => [
            'openers' => ['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'],
            'reason' =>
            'dispatchArmNames() has the same bare-string shape over a token_get_all() stream, '
            . 'walking the `match` arms of Chat::dispatchCommand(). Latent: measured on PHP '
            . '8.3.6, that method contains zero T_CURLY_OPEN tokens today, so nothing '
            . 'truncates. The failure mode if one appears is the same as the row above.',
        ],
        'tests/Config/ReadmeJsonErrorContractDriftTest.php' => [
            // Both, because the SELECTION cannot tell a text-keyed walker from
            // a bare-string one - see the reason. This row is the one place
            // that distinction is written down, which is why deleting it in
            // favour of "it is fine really" would lose the only record of it.
            'openers' => ['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'],
            'reason' =>
            'Two depth counters keyed on `$token->text === \'{\'`. This one handles '
            . 'T_CURLY_OPEN BY ACCIDENT and not by design - a PhpToken\'s text for that token '
            . 'IS `{`, measured on PHP 8.3.6 - while T_DOLLAR_OPEN_CURLY_BRACES\' text is `${` '
            . 'and is missed. So it is the deprecated spelling alone here, as in '
            . 'VhsTapeContractTest before it was fixed, and it is latent for the same reason: '
            . 'the syntax occurs nowhere in the tree. THE RECORDED OPENER SET IS WIDER THAN THE '
            . 'TRUTH HERE, deliberately and visibly: a PhpToken\'s text for T_CURLY_OPEN IS '
            . '`{`, so this walker really does handle that one, but the selection reads a '
            . 'comparison against a brace and cannot see which stream it is over. The set '
            . 'records what the SCANNER reports so the row and the scanner cannot drift apart; '
            . 'this sentence records what is actually true.',
        ],
    ];

    /**
     * The array-token ids the running PHP uses to OPEN a brace that a plain
     * `}` closes.
     *
     * Derived by tokenising each spelling above and keeping any array token
     * whose text ends in `{`. That is the property the brace walkers care
     * about - not the token's name - so a renamed or newly-added opener is
     * picked up without this test knowing about it.
     *
     * @return array<string,int> constant name => id
     */
    private static function openers(): array
    {
        $found = [];

        foreach (self::INTERPOLATIONS as $source) {
            foreach (\token_get_all($source) as $token) {
                if (!\is_array($token) || !str_ends_with($token[1], '{')) {
                    continue;
                }
                $found[\token_name($token[0])] = $token[0];
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * THE DERIVATION IS ALIVE, on an input whose answer is known, before any
     * absence is asserted from it. An empty roster would make every
     * assertion below vacuously true.
     */
    public function testTheOpenerRosterIsDerivedFromTheRunningInterpreter(): void
    {
        $openers = self::openers();

        $this->assertNotSame([], $openers, 'no interpolation opener token was derived at all - the '
            . 'roster is empty, so every "every scanner names them" assertion below is vacuous');

        // T_CURLY_OPEN is `{$` and is the one spelling that is NOT deprecated,
        // so it must be present on every PHP this suite can run on. Named
        // explicitly because it is the anchor: if even this is missing the
        // derivation is broken rather than the language having moved.
        $this->assertContains(
            'T_CURLY_OPEN',
            array_keys($openers),
            'the "{$a}" spelling stopped producing T_CURLY_OPEN, which means this derivation no '
                . 'longer measures what it claims to',
        );

        // THE ALPHABET IS THE COVERAGE, and a derivation is only as wide as
        // the spellings it was handed. Deleting a row from
        // {@see INTERPOLATIONS} shrinks the roster, and a shrunk roster makes
        // the "every scanner names every opener" assertion weaker without
        // making it fail - measured: removing the `${a}` row left that test
        // green with a roster of one. Graceful shrinking is the DESIGNED
        // behaviour for the day PHP removes the syntax, so it cannot simply
        // be forbidden; what can be checked is that the shrink was the
        // language's doing and not an edit's.
        //
        // The interpreter is asked directly. If it still defines the
        // deprecated opener, this file must still be handing it a spelling
        // that produces one. On a PHP that has removed the syntax the
        // constant goes too, and the roster is then allowed to be smaller
        // with nothing here reddening. THE TRADE-OFF, stated because it is a
        // real one: a PHP that kept the constant while removing the syntax
        // would red here spuriously. That is the safe polarity - it stops on
        // a human rather than quietly narrowing the guard - and this box has
        // only PHP 8.3.6, so no version beyond it has been exercised.
        if (\defined('T_DOLLAR_OPEN_CURLY_BRACES')) {
            $this->assertContains(
                'T_DOLLAR_OPEN_CURLY_BRACES',
                array_keys($openers),
                'this PHP still defines the deprecated `${a}` opener, but no spelling in '
                    . 'INTERPOLATIONS produces one any more. Either a row was deleted - put it '
                    . 'back, the scanners still have to handle the token - or the lexer changed '
                    . 'and the roster needs a new spelling to keep finding it.',
            );
        }

        // A source with NO interpolation must produce none of them - the
        // other polarity, without which a derivation that returned every
        // array token would look correct above.
        $plain = [];
        foreach (\token_get_all('<?php function f(): void { $a = "x"; }') as $token) {
            if (\is_array($token) && str_ends_with($token[1], '{')) {
                $plain[] = \token_name($token[0]);
            }
        }
        $this->assertSame([], $plain, 'a source with no interpolation produced an opener token');
    }

    /**
     * A KNOWN-POSITIVE THROUGH THE SAME PREDICATE, on sources whose answer is
     * known, in the same test that uses it to assert an absence.
     *
     * WHY THIS EXISTS. Every real-tree assertion in this file is an ABSENCE -
     * "no walker is missing an opener", "no gap is unrecorded" - and an
     * absence is satisfied perfectly by a dead instrument. Measured, before
     * this method was written: {@see constantsNamedInCode()} replaced by a
     * hard-coded `['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES']` - a
     * scanner that reads nothing at all and answers the same for every file -
     * left this file green, three tests and twelve assertions, rc 0.
     *
     * BOTH POLARITIES AND THE SELECTION. Three fixtures, because the
     * predicate makes two decisions and either can rot independently:
     * whether a file is a walker at all, and whether a walker is complete.
     * A scanner stuck at "everything is a walker" would put every doc-block
     * that merely EXPLAINS the problem on the hook for a token it has no use
     * for, which is a guard reddening correct code, which is answered with an
     * exemption, which is where the next real gap hides.
     */
    private function assertTheScannerIsAlive(): void
    {
        $openers = array_keys(self::openers());

        // PROSE ONLY. Names both openers, in a doc-block and in a string, and
        // walks nothing. Must not be selected.
        $prose = <<<'PHP'
            <?php
            /** Explains T_CURLY_OPEN and T_DOLLAR_OPEN_CURLY_BRACES at length. */
            final class Explains
            {
                public const NOTE = 'T_CURLY_OPEN opens an interpolation';
            }
            PHP;
        $this->assertNull(
            self::missingOpenersIn($prose, $openers),
            'a file that names the opener tokens only in prose and in strings was selected as a '
                . 'brace walker. Selecting on a substring puts every explanation of this problem '
                . 'on the hook for a token it has no use for.',
        );

        // A CLASS CONSTANT IS NOT A GLOBAL CONSTANT. `self::T_COMPOSED` is a
        // live shape in this suite - tests/Config/Support/EnvReadScanner.php
        // defines `const T_COMPOSED = -1` and refers to it seven times - and
        // a matcher that counted it would hand
        // {@see testEveryTokenConstantTheScannersNameIsStillDefined()} a name
        // no PHP has ever defined.
        $member = <<<'PHP'
            <?php
            final class Member
            {
                public const T_CURLY_OPEN = -1;

                public function f(array $t): bool { return $t[0] === self::T_CURLY_OPEN; }
            }
            PHP;
        $this->assertNull(
            self::missingOpenersIn($member, $openers),
            'a class constant that happens to be spelled like a token constant was read as a '
                . 'reference to the global one',
        );

        // A REAL WALKER, MISSING THE DEPRECATED OPENER: the exact shape of
        // every gap this file has ever found.
        $gap = <<<'PHP'
            <?php
            final class Walker
            {
                public function depth(array $tokens): int
                {
                    $d = 0;
                    foreach ($tokens as $t) {
                        if (is_array($t) && $t[0] === \T_CURLY_OPEN) { $d++; }
                    }
                    return $d;
                }
            }
            PHP;
        $missing = self::missingOpenersIn($gap, $openers);
        $this->assertIsArray($missing, 'a file that names \T_CURLY_OPEN in code is a brace walker');
        if (\defined('T_DOLLAR_OPEN_CURLY_BRACES')) {
            $this->assertContains(
                'T_DOLLAR_OPEN_CURLY_BRACES',
                $missing,
                'the walker fixture plainly handles one opener and not the other, and the '
                    . 'predicate reported nothing missing. Until this passes, "no walker is '
                    . 'missing an opener" is a statement about a dead instrument.',
            );
        }

        // A BARE-BRACE WALKER, naming no token constant at all: the shape the
        // selection could not express until it was widened, and the shape
        // that is wrong for BOTH openers rather than one.
        $bare = <<<'PHP'
            <?php
            final class Bare
            {
                public function depth(array $tokens): int
                {
                    $d = 0;
                    foreach ($tokens as $t) {
                        if ($t === '{') { $d++; }
                        if ($t === '}') { $d--; }
                    }
                    return $d;
                }
            }
            PHP;
        $bareMissing = self::missingOpenersIn($bare, $openers);
        $this->assertIsArray(
            $bareMissing,
            'a depth counter keyed on the bare one-byte braces was not selected as a brace '
                . 'walker. It names no token constant, so the original predicate could not see '
                . 'it - and it is wrong for every opener the lexer produces, not just the '
                . 'deprecated one.',
        );
        $this->assertSame(
            $openers,
            $bareMissing,
            'a bare-brace walker names none of the openers, so all of them are missing',
        );

        // ...AND A FILE THAT MERELY HANDLES BRACE CHARACTERS IS NOT ONE. This
        // is the negative the widened predicate needs: selection is on a
        // COMPARISON, so building or stripping a brace is not a depth count.
        $handles = <<<'PHP'
            <?php
            final class Handles
            {
                public function wrap(string $s): string
                {
                    return str_replace('{', '', '{' . $s . '}');
                }
            }
            PHP;
        $this->assertNull(
            self::missingOpenersIn($handles, $openers),
            'a file that merely builds and strips brace characters was selected as a depth '
                . 'counter. Selecting on the presence of the character rather than on a '
                . 'comparison puts half the tree on the hook for a token it has no use for.',
        );

        // ...AND ONE BRACE IS NOT A DEPTH COUNT. Measured: without this
        // fixture, dropping the `&& comparesAgainstBrace($tokens, '}')` half
        // of {@see countsBareBraces()} SURVIVED - no file in the tree happens
        // to compare against `{` alone, so the tree could not tell the two
        // predicates apart and the conjunct was decoration. A scanner that
        // looks for an opening brace and never a closing one is looking for a
        // block, not counting one.
        $openerOnly = <<<'PHP'
            <?php
            final class OpenerOnly
            {
                public function startsBlock(array $tokens): bool
                {
                    foreach ($tokens as $t) {
                        if ($t === '{') { return true; }
                    }
                    return false;
                }
            }
            PHP;
        $this->assertNull(
            self::missingOpenersIn($openerOnly, $openers),
            'a file that compares against the opening brace and never the closing one was '
                . 'selected as a depth counter. It cannot be counting depth: there is nothing '
                . 'to decrement.',
        );

        // ...and the same walker with the whole roster is clean, so the
        // predicate is not simply stuck at "everything is a gap".
        $complete = str_replace(
            '$t[0] === \T_CURLY_OPEN',
            '\in_array($t[0], [' . implode(', ', array_map(
                static fn (string $n): string => '\\' . $n,
                $openers,
            )) . '], true)',
            $gap,
        );
        $this->assertSame(
            [],
            self::missingOpenersIn($complete, $openers),
            'a walker that names every opener the running PHP produces was still reported as '
                . 'incomplete',
        );

        // A `switch`-BASED DEPTH COUNTER IS A BRACE WALKER, and it is the most
        // ordinary spelling of one. It puts no comparison operator anywhere
        // near the literal, so the predicate could not select it: a walker
        // spelled this way could be missing every opener and never appear as a
        // gap, because it was never asked. MEASURED, PHP 8.3.6: no file under
        // `tests/` or `src/` writes one today, which is precisely why the
        // fixture is synthetic and why it is worth having - the population this
        // arm covers is currently empty, and an empty population is what a
        // sentence in a doc-block guards until the first file joins it.
        $switched = <<<'PHP'
            <?php
            final class Switched
            {
                public function depth(array $tokens): int
                {
                    $d = 0;
                    foreach ($tokens as $t) {
                        switch ($t) {
                            case '{':
                                $d++;
                                break;
                            case '}':
                                $d--;
                                break;
                        }
                    }
                    return $d;
                }
            }
            PHP;
        $this->assertSame(
            $openers,
            self::missingOpenersIn($switched, $openers),
            'a depth counter written as a `switch` on the bare braces was not selected as a '
                . 'brace walker, so it can be missing every opener and never be reported. That '
                . 'is the same hole the bare-comparison walker used to sit in, one spelling '
                . 'over.',
        );

        // ...AND ITS MODERN SIBLING.
        $matched = <<<'PHP'
            <?php
            final class Matched
            {
                public function depth(array $tokens): int
                {
                    $d = 0;
                    foreach ($tokens as $t) {
                        $d += match ($t) {
                            '{' => 1,
                            '}' => -1,
                            default => 0,
                        };
                    }
                    return $d;
                }
            }
            PHP;
        $this->assertSame(
            $openers,
            self::missingOpenersIn($matched, $openers),
            'a depth counter written as a `match` on the bare braces was not selected as a '
                . 'brace walker',
        );

        // ...AND AN ARRAY KEYED BY A BRACE IS NOT A DEPTH COUNTER. This is the
        // negative that makes the arrow arm safe rather than merely wider, and
        // the fixture had to be MEASURED into its final shape: the first
        // version put the braces in VALUE position - `['name' => '{']`, which
        // is what this tree's three real arrow-adjacent braces are - and the
        // arm looks FORWARD, so it never fired and un-gating it SURVIVED. A
        // fixture that does not reach the code it is written for is a green
        // that means nothing (rule 2: suspect the assertion's window before the
        // mutation's relevance). Both braces are keys here, which is the only
        // shape the arm can see.
        $keyed = <<<'PHP'
            <?php
            final class Keyed
            {
                public function weights(): array
                {
                    return ['{' => 1, '}' => -1];
                }
            }
            PHP;
        $this->assertNull(
            self::missingOpenersIn($keyed, $openers),
            'an array literal keyed by brace characters was selected as a depth counter. The '
                . '`match`-arm arm of comparesAgainstBrace() must be gated on really sitting '
                . 'inside a `match` body, or every table in the tree that maps a brace to '
                . 'something joins the roster.',
        );
    }

    /**
     * Every brace-walking scanner in `tests/` and `src/` names every opener.
     *
     * The file list is DERIVED, not written: a literal list would go stale on
     * the commit that adds the next scanner, and a count would go stale on
     * the next merge.
     *
     * WHAT THE SELECTION SAID: any file that mentions `T_CURLY_OPEN` AS CODE
     * is walking braces and owes the rest of the roster. WHAT IS TRUE NOW:
     * that is one of two ways to walk braces, and it is the way taken by a
     * scanner whose author already knew the problem existed. The other counts
     * depth on the BARE one-byte strings `{` and `}` - which is wrong for
     * BOTH openers, since each opens with an array token and closes with a
     * bare one - and names no token constant at all, so the old predicate
     * could not express it. Measured on PHP 8.3.6: three such walkers in
     * `tests/`, one of them failing OPEN, all three invisible to this guard
     * for as long as its alphabet was the set of files that already agreed
     * with it. WHY THE ORIGINAL HALF STILL EARNS ITS PLACE: it is still the
     * only route that can say WHICH opener a walker is missing, because it
     * reads what the walker names. The bare-brace route can only say "all of
     * them".
     */
    public function testEveryBraceWalkingScannerNamesEveryOpener(): void
    {
        $this->assertTheScannerIsAlive();

        $openers = array_keys(self::openers());
        $this->assertNotSame([], $openers);

        $walkers = 0;
        $gaps = [];

        foreach (self::everyPhpFile() as $relative => $path) {
            if ($path === __FILE__) {
                continue;
            }

            $missing = self::missingOpenersIn((string) file_get_contents($path), $openers);
            if ($missing === null) {
                continue;
            }
            $walkers++;

            foreach ($missing as $name) {
                $gaps[$relative][] = $name;
            }
        }

        // Not a head-count: only a statement that the loop found brace
        // walkers at all, so a walk that matched nothing cannot pass as an
        // absence.
        $this->assertGreaterThan(
            0,
            $walkers,
            'no brace-walking scanner was found under tests/ or src/ - either they have all moved '
                . 'or this test is looking in the wrong place',
        );

        [$unrecorded, $overtaken, $mismatched] = self::reconcile(
            $gaps,
            self::KNOWN_GAPS,
            $openers,
        );

        $this->assertSame(
            [],
            $unrecorded,
            'this scanner walks braces but does not handle every token the running PHP uses to '
                . 'OPEN one. A missed opener does not crash: the walk loses a level and the '
                . 'scanner silently stops matching after the first interpolated string, which is '
                . 'how two separate guards in tests/Support/ came to report correct code as '
                . 'broken. Add the token beside T_CURLY_OPEN wherever the depth is counted.',
        );

        // THE DEFERRAL IS CHECKED AGAINST THE TREE, in every direction it can
        // be wrong. A row whose file has been fixed is a note about something
        // already done, and the next reader widens the guard by deleting rows
        // rather than by measuring. A row whose file is still a gap but a
        // DIFFERENT one is the third direction, added after a review found that
        // a file-keyed row was silent on a partial fix.
        foreach (self::KNOWN_GAPS as $relative => $row) {
            $this->assertNotSame(
                '',
                trim($row['reason']),
                $relative . ' is deferred without a reason',
            );
            $this->assertNotSame(
                [],
                $row['openers'],
                $relative . ' is deferred without naming which openers it is missing, so a '
                    . 'partial fix cannot be told from no fix at all',
            );
        }

        $this->assertSame(
            [],
            $overtaken,
            'this file is recorded in KNOWN_GAPS as a brace walker that misses an opener, and it '
                . 'no longer is. Delete its row - a deferral that has been overtaken is how a '
                . 'file silently stops being guarded.',
        );

        // AND THE THIRD DIRECTION: the row still matches the file, but not the
        // gap. This is what a PARTIAL fix looks like, and it is the direction a
        // file-keyed deferral cannot express at all.
        $this->assertSame(
            [],
            $mismatched,
            'this file is still a gap, so its KNOWN_GAPS row still matches - but the row records '
                . 'a different set of missing openers than the one the scanner now finds. '
                . 'Somebody closed part of the gap without finishing it, or the selection '
                . 'changed what it can see. Update the row\'s `openers` to the set in the '
                . 'message and re-read its `reason`, which was written about the wider gap and '
                . 'is now describing something that is no longer true.',
        );
    }

    /**
     * THE RECONCILIATION ITSELF, on input whose answer is known.
     *
     * WHAT THIS SAID: "{@see KNOWN_GAPS} is empty at this commit". WHAT IS
     * TRUE NOW: it is not, and was not when that sentence was committed - the
     * same change-set that emptied it of its one row widened the SELECTION and
     * refilled it with bare-brace walkers the old predicate could not express.
     * The number is deliberately not repeated here; it is derived by
     * {@see testEveryBraceWalkingScannerNamesEveryOpener()} and a cardinality
     * written into prose is wrong by the next merge. WHY THE REASONING STILL
     * EARNS ITS PLACE, and why it is not simply corrected to "not empty": the
     * rows exist to be DELETED as their files are fixed, so an empty map is
     * one commit away in the direction this file is pushing. On the day the
     * last row goes, every direction of the real check loops zero times, and
     * "nothing is unrecorded", "no row is overtaken" and "no row has drifted"
     * become true of a reconciliation deleted outright - the same hole as a
     * fixture whose expected value is what a dead instrument returns. The tree
     * cannot supply a positive here without re-breaking a file on purpose, so
     * the positive is synthetic and goes through the SAME {@see reconcile()}
     * the real check calls.
     *
     * ALL THREE DIRECTIONS, because they fail for different reasons and any one
     * of them can be deleted without the other two noticing.
     */
    public function testTheKnownGapReconciliationFailsInEveryDirection(): void
    {
        $openers = ['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'];
        $gaps = ['a/Walker.php' => ['T_DOLLAR_OPEN_CURLY_BRACES']];
        $row = static fn (array $openers): array
            => ['openers' => $openers, 'reason' => 'deferred'];

        // A gap with no row: refused, and the message has to carry the file
        // and the token or the next reader cannot act on it.
        [$unrecorded, $overtaken, $mismatched] = self::reconcile($gaps, [], $openers);
        $this->assertSame(
            ['a/Walker.php does not name T_DOLLAR_OPEN_CURLY_BRACES'],
            $unrecorded,
            'a brace walker missing an opener, with no row recording it, was not reported',
        );
        $this->assertSame([], $overtaken, 'an empty KNOWN_GAPS cannot have an overtaken row');
        $this->assertSame([], $mismatched, 'an empty KNOWN_GAPS cannot have a mismatched row');

        // A row whose file has been fixed: refused from the other side.
        [$unrecorded, $overtaken, $mismatched] = self::reconcile(
            [],
            ['a/Walker.php' => $row(['T_DOLLAR_OPEN_CURLY_BRACES'])],
            $openers,
        );
        $this->assertSame([], $unrecorded, 'a clean tree cannot produce an unrecorded gap');
        $this->assertSame(
            ['a/Walker.php'],
            $overtaken,
            'a KNOWN_GAPS row whose file no longer has the gap was not reported, so a deferral '
                . 'can outlive the thing it deferred',
        );
        $this->assertSame([], $mismatched, 'an overtaken row is not also a mismatched one');

        // THE THIRD DIRECTION: the file is still a gap, and the row describes a
        // WIDER one than it has. That is a partial fix, and a file-keyed
        // deferral is silent on it - which is what this file shipped with.
        [$unrecorded, $overtaken, $mismatched] = self::reconcile(
            $gaps,
            ['a/Walker.php' => $row(['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'])],
            $openers,
        );
        $this->assertSame([], $unrecorded, 'a recorded file is not an unrecorded gap');
        $this->assertSame([], $overtaken, 'a file that is still a gap has not been overtaken');
        $this->assertSame(
            ['a/Walker.php is recorded as missing [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES] '
                . 'but is actually missing [T_DOLLAR_OPEN_CURLY_BRACES]'],
            $mismatched,
            'half the gap was closed and the row still claims the whole of it, and nothing '
                . 'reported the difference - so the row\'s reason now describes a gap that is '
                . 'no longer there and the remaining half is deferred by accident',
        );

        // ...AND THE OTHER WAY ROUND, which is not the same statement: a row
        // NARROWER than the gap means somebody widened the selection, or a new
        // opener appeared, and the deferral is now covering more than it argued
        // for.
        [, , $mismatched] = self::reconcile(
            ['a/Walker.php' => ['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES']],
            ['a/Walker.php' => $row(['T_DOLLAR_OPEN_CURLY_BRACES'])],
            $openers,
        );
        $this->assertSame(
            ['a/Walker.php is recorded as missing [T_DOLLAR_OPEN_CURLY_BRACES] but is actually '
                . 'missing [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]'],
            $mismatched,
            'a row narrower than the gap it defers was not reported, so a deferral can quietly '
                . 'grow to cover openers nobody argued about',
        );

        // Matched: nothing fires. Without this the assertions above are
        // satisfied by a reconciliation that reports everything.
        [$unrecorded, $overtaken, $mismatched] = self::reconcile(
            $gaps,
            ['a/Walker.php' => $row(['T_DOLLAR_OPEN_CURLY_BRACES'])],
            $openers,
        );
        $this->assertSame([], $unrecorded);
        $this->assertSame([], $overtaken);
        $this->assertSame([], $mismatched);

        // AND THE ROSTER SHRINKS BOTH SIDES TOGETHER. This is the day PHP
        // removes `${...}`: the lexer stops producing that opener, so the
        // actual gap loses it - and a row still naming it must NOT red, or
        // every row in the map reds at once for a reason that has nothing to
        // do with the tree.
        [, , $mismatched] = self::reconcile(
            ['a/Walker.php' => ['T_CURLY_OPEN']],
            ['a/Walker.php' => $row(['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'])],
            ['T_CURLY_OPEN'],
        );
        $this->assertSame(
            [],
            $mismatched,
            'a row naming an opener the running PHP no longer produces was reported as a '
                . 'mismatch. The comparison has to be against the LIVE roster, or the map reds '
                . 'wholesale on the language change it was written to survive.',
        );
    }

    /**
     * The three failure directions of the KNOWN_GAPS map, as data.
     *
     * THE THIRD ONE IS THE ONE THIS FILE SHIPPED WITHOUT. A deferral keyed on
     * the FILENAME alone matches whatever gap the file still has, so a PARTIAL
     * fix - one opener added, the other not - kept the row matching and left
     * its prose describing a gap that was half closed, with nothing to say so.
     * The recorded set is compared against the actual one; a row that no longer
     * describes its file is reported separately from one whose file is clean,
     * because the two ask for opposite edits.
     *
     * THE COMPARISON IS AGAINST THE LIVE ROSTER, not against the row as
     * written. On a PHP that removes `${...}` the lexer stops producing that
     * opener, every actual gap set loses it, and a row still naming it would
     * red - all three at once, for a reason that has nothing to do with the
     * tree. Intersecting the row with the roster shrinks both sides together.
     *
     * @param array<string,list<string>>                              $gaps    file => openers it does not name
     * @param array<string,array{openers:list<string>,reason:string}> $known   file => deferral
     * @param list<string>                                            $openers the live roster
     *
     * @return array{list<string>,list<string>,list<string>} unrecorded gaps,
     *         overtaken rows, rows whose recorded set no longer matches
     */
    private static function reconcile(array $gaps, array $known, array $openers): array
    {
        $normalise = static function (array $names) use ($openers): array {
            $kept = array_values(array_intersect($names, $openers));
            sort($kept);

            return $kept;
        };

        $unrecorded = [];
        foreach ($gaps as $relative => $names) {
            if (isset($known[$relative])) {
                continue;
            }
            $unrecorded[] = $relative . ' does not name ' . implode(', ', $names);
        }

        $overtaken = [];
        $mismatched = [];
        foreach ($known as $relative => $row) {
            if (!isset($gaps[$relative])) {
                $overtaken[] = $relative;

                continue;
            }

            $recorded = $normalise($row['openers']);
            $actual = $normalise($gaps[$relative]);
            if ($recorded !== $actual) {
                $mismatched[] = $relative . ' is recorded as missing ['
                    . implode(', ', $recorded) . '] but is actually missing ['
                    . implode(', ', $actual) . ']';
            }
        }

        return [$unrecorded, $overtaken, $mismatched];
    }

    /**
     * Every token constant the brace walkers NAME is still defined.
     *
     * WHAT THIS SAID, and it was inverted: "the day an opener the scanners
     * name stops being defined, this fails with the name of the token instead
     * of the scanners failing with `Error: Undefined constant`". WHAT IS TRUE
     * NOW: it iterated {@see openers()} - what the LEXER PRODUCES - and so
     * checked precisely the set that CANNOT fatal. On a PHP that removes
     * `${...}`, the lexer stops producing the deprecated opener, the roster
     * shrinks to `T_CURLY_OPEN`, and every file naming the removed constant
     * dies on an undefined constant with nothing here to say so. Measured, by
     * replaying a simulated removal against the two candidate checked sets
     * side by side: the LEXER's set no longer contains the removed name, so a
     * guard built on it is SILENT; the set the SCANNERS NAME still contains
     * it, so a guard built on that one fires - and every file that names it
     * would fatal. WHY THE GUARD STILL EARNS ITS PLACE: the
     * hazard was real and only the direction of the lookup was wrong. The set
     * that can fatal is the set the SCANNERS NAME, so that is what is
     * iterated - which also covers a scanner naming a constant that was never
     * an opener at all.
     *
     * WHAT THE SELECTION SAID: any file naming `T_CURLY_OPEN` as code. WHAT IS
     * TRUE NOW: that missed the walkers the widened predicate exists to see. A
     * BARE-BRACE walker names no token constant at all, so it was excluded from
     * this census - and the paragraph above claims the census "also covers a
     * scanner naming a constant that was never an opener at all", which was
     * true only of walkers that already named the anchor. It now selects on
     * {@see missingOpenersIn()}, the same predicate the roster check uses, so
     * the two guards cover exactly the same population. WHY THE ORIGINAL
     * REASONING STILL EARNS ITS PLACE: the anchor gate was never about which
     * files matter, it was a cheap way to say "this file walks braces", and
     * that is still the question - only now there are two ways to answer it.
     *
     * The trade-off, stated: a scanner that deliberately names a constant it
     * has guarded with `defined()` would red here. Nothing in the tree does
     * that today, and the polarity is the safe one - it stops on a human
     * rather than skipping the check.
     */
    public function testEveryTokenConstantTheScannersNameIsStillDefined(): void
    {
        $this->assertTheScannerIsAlive();

        $openers = array_keys(self::openers());
        $named = [];

        foreach (self::everyPhpFile() as $relative => $path) {
            if ($path === __FILE__) {
                continue;
            }

            $source = (string) file_get_contents($path);
            if (self::missingOpenersIn($source, $openers) === null) {
                continue;
            }

            foreach (self::constantsNamedInCode($source) as $name) {
                $named[$name][] = $relative;
            }
        }

        ksort($named);

        $this->assertNotSame(
            [],
            $named,
            'no brace-walking scanner was found, so this guard checked nothing at all',
        );

        foreach ($named as $name => $files) {
            $this->assertTrue(
                \defined($name),
                $name . ' is named as a global constant by ' . implode(', ', $files)
                    . ' but this PHP does not define it, so every one of those files is one call '
                    . 'away from an undefined-constant Error. If the language removed it, remove '
                    . 'the references and make sure whatever replaced it is handled.',
            );
        }

        // The openers the lexer produces are a SUBSET of what must still be
        // defined, not the whole of it - asserted so that a future edit
        // narrowing the loop above back to openers() reddens here.
        foreach ($openers as $name) {
            $this->assertTrue(\defined($name), $name . ' is produced by the lexer but is not defined');
            $this->assertSame(
                self::openers()[$name],
                \constant($name),
                $name . ' does not evaluate to the id the lexer emitted',
            );
        }
    }

    /**
     * NO FILE IN `tests/` OR `src/` USES THE 8.2-DEPRECATED SPELLING.
     *
     * This is the claim an earlier entry made in prose and nothing checked. It
     * matters in the direction prose cannot hold: the day a `"${a}"` is
     * written into a file that a brace walker scans, the walk loses a level
     * silently - and on a PHP that has removed the syntax outright the file
     * stops parsing. Every spelling this suite needs is supplied as a SOURCE
     * STRING instead, which is why the fixtures below are nowdocs: a nowdoc's
     * body is one `T_ENCAPSED_AND_WHITESPACE` token in the FILE that holds it,
     * so writing one costs the tree no occurrence at all.
     *
     * THE SCANNER IS RUN AGAINST A KNOWN POSITIVE IN THE SAME TEST, because
     * this asserts an absence and an absence is satisfied perfectly by a dead
     * instrument - and by a fixture whose expected value is what a dead
     * instrument returns. A fixture asserting `false` on a clean source would
     * pass with {@see usesDeprecatedInterpolation()} deleted, so the positive
     * fixture is the load-bearing one and the negative is the control that
     * stops the predicate being stuck at "everything".
     */
    public function testNoFileUsesTheDeprecatedInterpolationSyntax(): void
    {
        $deprecated = <<<'PHP'
            <?php $a = 1; $s = "x${a}y";
            PHP;
        $modern = <<<'PHP'
            <?php $a = 1; $s = "x{$a}y";
            PHP;

        $this->assertTrue(
            self::usesDeprecatedInterpolation($deprecated),
            'the scanner did not see the deprecated spelling in a source that plainly uses it, '
                . 'so every "no file uses it" answer below is a statement about a dead '
                . 'instrument',
        );
        $this->assertFalse(
            self::usesDeprecatedInterpolation($modern),
            'the scanner called the NON-deprecated spelling deprecated, so it is stuck at yes '
                . 'and the positive above proves nothing',
        );

        $offenders = [];
        $scanned = 0;
        foreach (self::everyPhpFile() as $relative => $path) {
            $scanned++;
            if (self::usesDeprecatedInterpolation((string) file_get_contents($path))) {
                $offenders[] = $relative;
            }
        }

        $this->assertGreaterThan(
            0,
            $scanned,
            'the walk over tests/ and src/ found no PHP file at all, so it checked nothing',
        );

        $this->assertSame(
            [],
            $offenders,
            'this file uses the `"${a}"` interpolation spelling PHP 8.2 deprecated. It emits '
                . 'an E_DEPRECATED wherever it is COMPILED (measured: one, on PHP 8.3.6), and '
                . 'on the PHP that removes it the file stops parsing. Write `"{$a}"`. If the '
                . 'spelling is needed as DATA - to hand a scanner something to lex - put it in '
                . 'a nowdoc or a single-quoted string, as INTERPOLATIONS does: the body of one '
                . 'is a single token in the file that holds it, so it costs no occurrence.',
        );
    }

    /**
     * THE NAMING SET SPANS DIRECTORIES NO ONE LANE OWNS.
     *
     * WHY A SPREAD AND NOT A COUNT. The retired claim was "two files", and the
     * fix it implied - edit the literal pair - was wrong for a reason a
     * corrected COUNT would not carry either: a count measured in one lane's
     * worktree is wrong by the next merge, and the number was never the point.
     * The point is that the constant is named from several directories at
     * once, so no single lane can make that edit and any change to how these
     * scanners spell the opener is a cross-lane change. That is a LOWER BOUND,
     * which is the direction that survives a merge adding files.
     */
    public function testTheConstantIsNamedAcrossDirectoriesNoOneLaneOwns(): void
    {
        $this->assertTheScannerIsAlive();

        $directories = [];
        foreach (self::phpFilesToScan() as $relative => $path) {
            $named = self::constantsNamedInCode((string) file_get_contents($path));
            if (!\in_array('T_DOLLAR_OPEN_CURLY_BRACES', $named, true)) {
                continue;
            }
            $directories[\dirname($relative)] = true;
        }

        ksort($directories);

        $this->assertGreaterThanOrEqual(
            3,
            \count($directories),
            'the deprecated opener is named as code from ' . implode(', ', array_keys($directories))
                . ' - fewer directories than the claim this test exists to hold. Either the '
                . 'scanners have been consolidated, in which case say so and retire this test, '
                . 'or the derivation has stopped seeing files it used to see.',
        );

        // THIS FILE MUST NOT BE IN THAT SET. It is the guard that has to keep
        // working on the PHP that removes the constant, so it may only reach
        // the name through defined() and string literals. Measured by asking
        // the same predicate the rest of the suite is judged by.
        $this->assertNotContains(
            'T_DOLLAR_OPEN_CURLY_BRACES',
            self::constantsNamedInCode((string) file_get_contents(__FILE__)),
            'this file now names the deprecated constant AS CODE, so it is one of the files '
                . 'that would hard-error on the PHP that removes it - and it is the file whose '
                . 'job is to report that, not to die of it.',
        );
    }

    /**
     * Whether a source's OWN token stream contains the deprecated opener.
     *
     * File level, deliberately: a nowdoc or single-quoted string whose TEXT
     * spells `${a}` is one token here and contributes nothing, which is what
     * makes it safe for a fixture to carry the spelling this refuses.
     *
     * On a PHP that has removed the syntax the lexer stops producing the
     * token, `defined()` goes false, and this answers false for everything -
     * which is correct: there is then nothing to refuse.
     */
    private static function usesDeprecatedInterpolation(string $source): bool
    {
        if (!\defined('T_DOLLAR_OPEN_CURLY_BRACES')) {
            return false;
        }

        $id = \constant('T_DOLLAR_OPEN_CURLY_BRACES');
        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && $token[0] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `.php` file under `tests/` and `src/`, keyed by its path relative
     * to the library root.
     *
     * NO SUBSTRING PRE-FILTER, unlike {@see phpFilesToScan()}. That one gates
     * on the anchor token because a file that cannot name it cannot be a brace
     * walker; here the question is what the file's own lexer output contains,
     * and a pre-filter would be the alphabet deciding the answer.
     *
     * @return array<string,string> relative path => absolute path
     */
    private static function everyPhpFile(): array
    {
        $library = \dirname(__DIR__, 2);
        $found = [];

        foreach (['tests', 'src'] as $directory) {
            $root = $library . '/' . $directory;
            if (!is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $found[substr($file->getPathname(), \strlen($library) + 1)] = $file->getPathname();
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Which openers a source is missing, or null when it is not a brace
     * walker at all.
     *
     * @param list<string> $openers
     * @return list<string>|null
     */
    private static function missingOpenersIn(string $source, array $openers): ?array
    {
        $named = self::constantsNamedInCode($source);
        if (!\in_array('T_CURLY_OPEN', $named, true) && !self::countsBareBraces($source)) {
            return null;
        }

        $missing = [];
        foreach ($openers as $name) {
            if (!\in_array($name, $named, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Whether a source counts brace depth on the BARE one-byte strings.
     *
     * THE SHAPE, and why it is a brace walker even though it names no token
     * constant: a depth counter that increments on `$token === '{'` and
     * decrements on `$token === '}'`. Over a `token_get_all()` stream that is
     * wrong in one direction only, which is what makes it dangerous - the
     * OPENER of an interpolation is an array token and never `===` the bare
     * string, while its CLOSER is the bare string and always matches. The
     * count therefore goes one closer over and the walk ends early. Over a
     * `PhpToken` stream the same spelling handles `T_CURLY_OPEN` by accident,
     * because that token's text IS `{`, and still misses `${`.
     *
     * SELECTION IS ON A COMPARISON, not on the presence of the character.
     * `str_replace('{', '', $s)` and `$open = '{';` are not depth counts, and
     * a predicate that took every `'{'` would put half the tree on the hook
     * for a token it has no use for - which is answered with an exemption,
     * which is where the next real gap hides. BOTH braces are required for
     * the same reason: a file that only ever compares against `{` is not
     * counting anything.
     *
     * THE TRADE-OFF, stated: a walker that counts depth some third way - on a
     * token id it computed, or by regex over text - is outside this predicate
     * and outside the other one. Nothing in the tree does that today,
     * measured, and the honest statement is that this alphabet is wider than
     * it was rather than complete.
     *
     * WHAT THAT SENTENCE MISSED, and it is the most ordinary spelling of a
     * depth counter there is: `switch ($text) { case '{': $depth++; ... }`, and
     * its modern sibling `match`. Neither puts a comparison operator next to
     * the literal, so both were invisible to this predicate AND to the
     * `T_CURLY_OPEN`-naming half beside it - a walker spelled that way was not
     * SELECTED at all, so it could be missing every opener and never appear as
     * a gap. {@see comparesAgainstBrace()} now takes `case` and a `match` arm
     * too. MEASURED, PHP 8.3.6, over every `.php` under `tests/` and `src/`:
     * the population both arms bring in is EMPTY - no brace literal in the tree
     * neighbours a `T_CASE`, and the three that neighbour a `T_DOUBLE_ARROW`
     * all carry the arrow BEFORE them, so all three sit in VALUE position and
     * none is an arm subject.
     *
     * WHAT THIS PARAGRAPH SAID: "every one that neighbours a `T_DOUBLE_ARROW`
     * is an array key rather than a match arm, which is exactly why the arrow
     * arm is gated on sitting inside a `match` body". WHAT IS TRUE NOW, and was
     * true when that was written: the measurement above, which inverts it. The
     * consequence inverts with it - because those three are VALUES the arrow
     * arm never looks at them at all, so the `match` gate is not what spares
     * them and cannot be justified by them. WHY THE GATE STILL EARNS ITS PLACE:
     * it guards a shape the tree does not have YET. An ordinary lookup table
     * `['{' => 1, '}' => -1]` puts a brace in KEY position and is
     * indistinguishable from a `match` walker on the arrow alone; ungated, the
     * arm would select it and report it missing every opener. That is argued in
     * full on {@see comparesAgainstBrace()}, whose twin of this paragraph was
     * corrected first - the same inversion survived here one doc-block away,
     * which is what a correction applied to the sentence you were looking at
     * rather than to the claim costs.
     *
     * So this widening changes no answer today and is pinned by synthetic
     * fixtures instead; the point is that the first `switch`-based walker
     * anyone writes now arrives selected rather than unguarded.
     */
    private static function countsBareBraces(string $source): bool
    {
        $tokens = \token_get_all($source);

        return self::comparesAgainstBrace($tokens, '{')
            && self::comparesAgainstBrace($tokens, '}');
    }

    /**
     * Whether any one-byte string literal $brace is DISPATCHED ON - compared
     * against, or used as a `switch` case or a `match` arm.
     *
     * THE THREE ARMS ARE THREE SPELLINGS OF ONE THING and not three rules. A
     * depth counter asks "is this token a brace"; PHP lets it ask with `===`,
     * with `case`, or with a `match` arm, and a predicate that knew only the
     * first covered the shape its author happened to have written.
     *
     * THE ARROW ARM LOOKS FORWARD ONLY, because a `match` arm's subject is the
     * KEY: `'{' => 1`. A brace in VALUE position - `['T_CURLY_OPEN' => '{']` -
     * has its arrow BEFORE it and is not a dispatch on the brace at all.
     * Measured, PHP 8.3.6, over every `.php` under `tests/` and `src/`: three
     * brace literals neighbour a `T_DOUBLE_ARROW` and all three are values,
     * NONE is a key. So the arrow arm selects nothing in the tree today.
     *
     * THE `match` GATE IS THEREFORE GUARDING A SHAPE THAT IS NOT HERE YET, and
     * saying so is the point rather than a caveat: an array literal `['{' => 1,
     * '}' => -1]` - a perfectly ordinary table mapping a brace to something -
     * is indistinguishable from a `match` walker on the arrow alone, and an
     * ungated arm would select it and report it missing every opener. That the
     * tree has no such table today is a fact about today. The gate is pinned by
     * a fixture rather than by this paragraph.
     *
     * `case` needs no gate: `T_CASE` appears in `switch` and in enum
     * declarations, and an enum case spells its value after an `=`, never
     * adjacent to the name.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function comparesAgainstBrace(array $tokens, string $brace): bool
    {
        $comparisons = [
            \T_IS_IDENTICAL, \T_IS_NOT_IDENTICAL, \T_IS_EQUAL, \T_IS_NOT_EQUAL,
        ];
        $skippable = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (\strlen($token[1]) !== 3 || trim($token[1], '\'"') !== $brace) {
                continue;
            }

            foreach ([-1, 1] as $direction) {
                for ($j = $i + $direction; isset($tokens[$j]); $j += $direction) {
                    $neighbour = $tokens[$j];
                    if (\is_array($neighbour) && \in_array($neighbour[0], $skippable, true)) {
                        continue;
                    }
                    if (\is_array($neighbour) && \in_array($neighbour[0], $comparisons, true)) {
                        return true;
                    }
                    if ($direction === -1 && \is_array($neighbour) && $neighbour[0] === \T_CASE) {
                        return true;
                    }
                    if ($direction === 1 && \is_array($neighbour) && $neighbour[0] === \T_DOUBLE_ARROW
                        && self::sitsInsideAMatch($tokens, $i)) {
                        return true;
                    }

                    break;
                }
            }
        }

        return false;
    }

    /**
     * Whether the token at $at sits inside the body of a `match`.
     *
     * The walk is backwards over unmatched openers: every `{` or `(` that has
     * not been closed by the time it reaches $at is an enclosing construct, and
     * the question is whether any of them is a `match`'s. That is cheaper and
     * more honest than a forward parse - it answers "which constructs am I
     * inside", which is exactly the question - and it costs nothing on the
     * common case, because it only runs for a brace literal that already has an
     * arrow beside it.
     *
     * @param list<array{int,string,int}|string> $tokens
     */
    private static function sitsInsideAMatch(array $tokens, int $at): bool
    {
        $skippable = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];
        $closed = 0;

        for ($j = $at - 1; $j >= 0; $j--) {
            $text = \is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($text === '}' || $text === ')') {
                $closed++;

                continue;
            }
            if ($text !== '{' && $text !== '(') {
                continue;
            }
            if ($closed > 0) {
                $closed--;

                continue;
            }

            // An unmatched opener: is it a `match`'s? `match` is followed by
            // `(subject)` and then the body brace, so the keyword sits two
            // significant tokens back from the body's `{` and immediately
            // before the subject's `(`.
            for ($k = $j - 1; $k >= 0; $k--) {
                if (\is_array($tokens[$k]) && \in_array($tokens[$k][0], $skippable, true)) {
                    continue;
                }
                if (\is_array($tokens[$k]) && $tokens[$k][0] === \T_MATCH) {
                    return true;
                }
                if ($text === '{' && \is_string($tokens[$k]) && $tokens[$k] === ')') {
                    // Step over the subject to reach the keyword.
                    $subjectDepth = 0;
                    for ($m = $k; $m >= 0; $m--) {
                        $subject = \is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
                        if ($subject === ')') {
                            $subjectDepth++;
                        } elseif ($subject === '(') {
                            $subjectDepth--;
                            if ($subjectDepth === 0) {
                                $k = $m;

                                break;
                            }
                        }
                    }
                    $text = '';

                    continue;
                }

                break;
            }
        }

        return false;
    }

    /**
     * Every `.php` file under `tests/` and `src/` that mentions the anchor
     * token at all, keyed by its path relative to the library root.
     *
     * The substring pre-filter is a speed gate and nothing else: a file that
     * does not contain the string `T_CURLY_OPEN` cannot name the constant, and
     * tokenising several hundred files to learn that is wasted. Selection is
     * still done on TOKENS, by {@see constantsNamedInCode()}.
     *
     * @return array<string,string> relative path => absolute path
     */
    private static function phpFilesToScan(): array
    {
        $library = \dirname(__DIR__, 2);
        $found = [];

        foreach (['tests', 'src'] as $directory) {
            $root = $library . '/' . $directory;
            if (!is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                if (!str_contains((string) file_get_contents($file->getPathname()), 'T_CURLY_OPEN')) {
                    continue;
                }
                $found[substr($file->getPathname(), \strlen($library) + 1)] = $file->getPathname();
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * The `T_*` GLOBAL constant names a source refers to as code.
     *
     * A NAME AFTER `::`, `->`, `?->`, `function`, `const`, `class`, `trait`,
     * `interface`, `enum`, `use` or `namespace` IS NOT ONE. That is not
     * fastidiousness: `tests/Config/Support/EnvReadScanner.php` declares
     * `public const T_COMPOSED = -1` and refers to it as `self::T_COMPOSED`,
     * and a matcher that took the bare `T_STRING` would report a constant no
     * PHP has ever defined - measured, it was the single undefined name in
     * the whole census.
     *
     * `case` is deliberately NOT in that list, because `case \T_CURLY_OPEN:`
     * in a switch is a genuine reference and is the shape a brace walker is
     * most likely to be written in. The cost is an enum case spelled `T_*`,
     * of which the tree has none.
     *
     * @return list<string>
     */
    private static function constantsNamedInCode(string $source): array
    {
        $blocked = [
            \T_DOUBLE_COLON, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_FUNCTION,
            \T_CONST, \T_CLASS, \T_ENUM, \T_TRAIT, \T_INTERFACE, \T_USE, \T_NAMESPACE,
        ];

        $names = [];
        $previous = null;

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token)
                && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if (\is_array($token)
                && \in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($token[1], '\\');
                if (str_starts_with($name, 'T_')
                    && !(\is_array($previous) && \in_array($previous[0], $blocked, true))) {
                    $names[] = $name;
                }
            }

            $previous = $token;
        }

        return array_values(array_unique($names));
    }
}
