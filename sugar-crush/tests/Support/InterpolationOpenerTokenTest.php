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
 * WHAT WAS MEASURED, because E208 asserted a hazard that does not reproduce.
 * On PHP 8.3.6: the constant is defined (395); REFERENCING it emits nothing
 * at all, because the deprecation is on the `"${a}"` SYNTAX and not on the
 * token; `token_get_all()` on a source that does use that syntax still
 * produces the token and still emits nothing, since it lexes rather than
 * compiles; and the syntax occurs ZERO times in `src/` and `tests/`
 * combined. So "a deprecation notice on 8.4" is not the risk. The risk is
 * REMOVAL, it is further out than 8.4, and it is a hard error rather than a
 * notice - which is what this file is pointed at.
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
     * WHY A ROW RATHER THAN A FIX. The walk that produced this row was
     * widened from `tests/Support/` to the whole of `tests/` and `src/` by a
     * round whose file list was `tests/Support/` and `tests/Backend/`. The
     * fix is two tokens and belongs to whoever owns the file; recording it
     * here makes the obligation visible and counted, which an invisible one
     * was not for as long as the guard could not see the directory it lived
     * in.
     *
     * @var array<string,string>
     */
    private const KNOWN_GAPS = [
        'tests/VhsTapeContractTest.php' =>
            'Two depth counters - VhsTapeContractTest::statements() and ::callArgument() - '
            . 'increment on `\T_CURLY_OPEN || \T_ATTRIBUTE` and never on the deprecated opener, '
            . 'so both walks would lose a level inside a `"${a}"`. Latent rather than live: the '
            . 'syntax occurs zero times in this repository, measured at the commit that added '
            . 'this row. The file is at the root of tests/ and was in no lane\'s file list when '
            . 'the guard was widened to reach it.',
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
    }

    /**
     * Every brace-walking scanner in `tests/` and `src/` names every opener.
     *
     * The file list is DERIVED, not written: any file that mentions
     * `T_CURLY_OPEN` AS CODE is walking braces and owes the rest of the
     * roster. A literal list would go stale on the commit that adds the next
     * scanner, and a count would go stale on the next merge.
     */
    public function testEveryBraceWalkingScannerNamesEveryOpener(): void
    {
        $this->assertTheScannerIsAlive();

        $openers = array_keys(self::openers());
        $this->assertNotSame([], $openers);

        $walkers = 0;
        $gaps = [];

        foreach (self::phpFilesToScan() as $relative => $path) {
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

        $unrecorded = [];
        foreach ($gaps as $relative => $names) {
            if (isset(self::KNOWN_GAPS[$relative])) {
                continue;
            }
            $unrecorded[] = $relative . ' does not name ' . implode(', ', $names);
        }

        $this->assertSame(
            [],
            $unrecorded,
            'this scanner walks braces but does not handle every token the running PHP uses to '
                . 'OPEN one. A missed opener does not crash: the walk loses a level and the '
                . 'scanner silently stops matching after the first interpolated string, which is '
                . 'how two separate guards in tests/Support/ came to report correct code as '
                . 'broken. Add the token beside T_CURLY_OPEN wherever the depth is counted.',
        );

        // THE DEFERRAL IS CHECKED AGAINST THE TREE, in both directions. A row
        // whose file has been fixed is a note about something already done,
        // and the next reader widens the guard by deleting rows rather than
        // by measuring.
        foreach (self::KNOWN_GAPS as $relative => $reason) {
            $this->assertNotSame('', trim($reason), $relative . ' is deferred without a reason');
            $this->assertArrayHasKey(
                $relative,
                $gaps,
                $relative . ' is recorded in KNOWN_GAPS as a brace walker that misses an opener, '
                    . 'and it no longer is. Delete its row - a deferral that has been overtaken '
                    . 'is how a file silently stops being guarded.',
            );
        }
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

        foreach (self::phpFilesToScan() as $relative => $path) {
            if ($path === __FILE__) {
                continue;
            }

            $constants = self::constantsNamedInCode((string) file_get_contents($path));
            if (!\in_array('T_CURLY_OPEN', $constants, true)) {
                continue;
            }

            foreach ($constants as $name) {
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
     * Which openers a source is missing, or null when it is not a brace
     * walker at all.
     *
     * @param list<string> $openers
     * @return list<string>|null
     */
    private static function missingOpenersIn(string $source, array $openers): ?array
    {
        $named = self::constantsNamedInCode($source);
        if (!\in_array('T_CURLY_OPEN', $named, true)) {
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
