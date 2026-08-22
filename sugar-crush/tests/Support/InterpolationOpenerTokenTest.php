<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The token-stream scanners under `tests/Support/` all walk braces, and PHP
 * opens some braces with an ARRAY token whose closer is a plain `}`. Every
 * one of those openers has to be in every scanner's list, and the list is
 * DERIVED FROM THE INTERPRETER rather than written down.
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
     * Every brace-walking scanner in `tests/Support/` names every opener.
     *
     * The file list is DERIVED, not written: any file here that mentions
     * `T_CURLY_OPEN` is walking braces and owes the rest of the roster. A
     * literal list would go stale on the commit that adds the next scanner,
     * and a count would go stale on the next merge.
     */
    public function testEveryBraceWalkingScannerNamesEveryOpener(): void
    {
        $openers = array_keys(self::openers());
        $this->assertNotSame([], $openers);

        $walkers = 0;
        $missing = [];

        foreach (glob(__DIR__ . '/*.php') ?: [] as $path) {
            if ($path === __FILE__) {
                continue;
            }

            // MENTIONED IN CODE, not in prose. A doc-block that explains the
            // interpolation problem names `T_CURLY_OPEN` without walking a
            // single brace, and selecting files with a substring match would
            // put every such explanation on the hook for a token it has no
            // use for - a guard reddening correct code, which is answered
            // with an exemption. Comments are source text; that lesson is
            // already written into two scanners in this directory.
            $named = self::constantsNamedInCode((string) file_get_contents($path));
            if (!\in_array('T_CURLY_OPEN', $named, true)) {
                continue;
            }
            $walkers++;

            foreach ($openers as $name) {
                if (!\in_array($name, $named, true)) {
                    $missing[] = basename($path) . ' does not name ' . $name;
                }
            }
        }

        // Not a head-count: only a statement that the loop found brace
        // walkers at all, so a glob that matched nothing cannot pass as an
        // absence.
        $this->assertGreaterThan(
            0,
            $walkers,
            'no brace-walking scanner was found under tests/Support/ - either they have all moved '
                . 'or this test is looking in the wrong place',
        );

        $this->assertSame(
            [],
            $missing,
            'this scanner walks braces but does not handle every token the running PHP uses to '
                . 'OPEN one. A missed opener does not crash: the walk loses a level and the '
                . 'scanner silently stops matching after the first interpolated string, which is '
                . 'how two separate guards in this directory came to report correct code as '
                . 'broken. Add the token beside T_CURLY_OPEN wherever the depth is counted.',
        );
    }

    /**
     * A roster entry that no longer exists is a hard error waiting to happen,
     * so it is asserted rather than assumed.
     *
     * This is the guard that answers the PHP-9 question this suite cannot
     * otherwise ask: the day an opener the scanners name stops being defined,
     * this fails with the name of the token instead of the scanners failing
     * with `Error: Undefined constant` somewhere in a brace walk.
     */
    public function testEveryOpenerTheScannersNameIsStillDefined(): void
    {
        $defined = 0;

        foreach (self::openers() as $name => $id) {
            $this->assertTrue(
                \defined($name),
                $name . ' is produced by the lexer but is not a defined constant, so every scanner '
                    . 'that names it is one call away from an undefined-constant Error',
            );
            $this->assertSame($id, \constant($name), $name . ' does not evaluate to the id the lexer emitted');
            $defined++;
        }

        $this->assertGreaterThan(0, $defined, 'nothing was checked - the roster is empty');
    }

    /**
     * The `T_*` constant names a source refers to AS CODE.
     *
     * @return list<string>
     */
    private static function constantsNamedInCode(string $source): array
    {
        $names = [];

        foreach (\token_get_all($source) as $token) {
            if (!\is_array($token)
                || !\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            if (str_starts_with($name, 'T_')) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
