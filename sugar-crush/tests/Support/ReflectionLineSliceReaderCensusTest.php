<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A READER THAT SLICES A LINE ARRAY WITH REFLECTION'S LINE NUMBERS MUST BE
 * ADDRESSING THE FILE THOSE NUMBERS CAME FROM.
 *
 * WHERE THIS CAME FROM. `VhsTapeContractTest::modelMethodTokens()` took
 * `getStartLine()`/`getEndLine()` off a `ReflectionMethod` and sliced
 * `file(__FILE__)` with them. Nothing checked that the two addressed the same
 * text. For a method imported from a TRAIT, `getFileName()` is the trait's file
 * and the line numbers are the trait's lines — measured on PHP 8.3.6, slicing
 * the using class's file with them returns unrelated source with no error of
 * any kind — and extracting a duplicated test helper into a trait is a thing
 * this tree is actively doing. That one reader was fixed.
 *
 * THE FIX WENT INTO ONE READER AND THE TREE HAS MORE THAN ONE. That is the only
 * reason this file exists: the defect is a SHAPE, the shape recurs, and nothing
 * in the tree could say how often. It is not a count written down here — a
 * cardinality taken in a lane worktree is void at the next merge — it is
 * derived by {@see readers()} on every run.
 *
 * WHAT COUNTS AS SAFE HERE, and the bound is stated rather than implied. A
 * reader is safe when its COVERAGE SET names `getFileName` at all, which covers
 * both honest spellings: slicing `file($reflection->getFileName())` directly,
 * which cannot be wrong, and slicing `__FILE__` after asserting the two agree.
 * IT DOES NOT CHECK WHAT THE FUNCTION DOES WITH THE NAME — one that fetched it
 * and ignored it would pass. That is a coarser question than the real one and
 * it is the one that can be answered from tokens; the alternative is a dataflow
 * analysis whose own correctness nobody could check. The bound is here so the
 * next reader knows what the green means.
 *
 * THE SLICE DOES NOT HAVE TO SIT IN THE SAME FUNCTION AS THE LINE NUMBERS, and
 * assuming it did is how the first cut of this census came to be blind to the
 * one reader it was written about. {@see VhsTapeContractTest} reads the line
 * numbers in `modelMethodTokens()` and hands them to `declaredSlice()` to do
 * the indexing. A scanner keyed on "`getStartLine` AND `array_slice` in one
 * function" drops BOTH halves of that pair — the caller for want of a slice,
 * the callee for want of a reflection call — so the exemplar this file was
 * built from was the one file it could not see, and re-opening the exemplar's
 * original defect in it left this census green. Selection is therefore on the
 * reflection read ALONE, and the slice is looked for one call hop deep among
 * the functions declared in the SAME FILE. A reader's COVERAGE SET is its own
 * body plus the bodies of the same-file functions it delegates the slice to,
 * because those two are jointly responsible for the pairing.
 *
 * AND A REFLECTION READ WHOSE SLICE IS OUT OF REACH IS REPORTED, NOT DROPPED.
 * One hop within one file is a bound, and a bound has an outside: a slice
 * lifted into a shared helper class is past it. Rather than reading such a site
 * as clean — which is what silently skipping it would say — the scanner returns
 * it separately and {@see SLICE_UNREACHABLE} must carry a row for it. That
 * roster is EMPTY today and both directions are checked, so it costs nothing
 * until the day it is the only thing standing between a moved helper and a
 * census that has quietly stopped covering anything.
 *
 * THE SECOND HALF OF THE ORIGINAL DEFECT IS NOT COVERED BY THIS FILE, and the
 * omission is deliberate and recorded. Reflection's line numbers are fixed when
 * the class loads while `file()` is read on every call, so an edit to the file
 * in between shifts every slice WHILE THE FILE NAME STILL MATCHES. That half
 * was the one actually OBSERVED. Only `VhsTapeContractTest` guards it, through
 * a `declaredSlice()` that refuses unless the slice's first line spells
 * `function <name>`. Every other reader here is open to it. Rostering every
 * reader by `<file>::<method>` across five concurrently-merging lanes would red
 * on every rename, so it is a backlog entry rather than a guard — see the
 * round-49 lane c report.
 */
final class ReflectionLineSliceReaderCensusTest extends TestCase
{
    /**
     * Readers that slice by reflection line numbers WITHOUT naming the
     * declaring file, each with the reason it is left that way.
     *
     * NOT AN EXEMPTION LIST. Both directions are checked against the tree: a
     * row whose reader has been fixed fails and must be deleted, and a reader
     * with no row fails and must be argued or fixed.
     *
     * @var array<string,string>
     */
    private const DECLARING_FILE_UNCHECKED = [
        'Cli/HelpTest.php::backendSelectionVariables' =>
            'It reflects `Bootstrap`\'s methods and slices a `$lines` read from Bootstrap.php by '
            . 'path, so the file is right today by construction rather than by check — and the '
            . 'day one of those methods arrives through a trait, the slice silently addresses '
            . 'the wrong text and the census built on it reports whichever variables happen to '
            . 'live at those offsets. The fix is one `assertSame()` against '
            . '`$reflected->getFileName()`. It is NOT DONE HERE because `tests/Cli/HelpTest.php` '
            . 'is outside the lane that added this census (round 49, lane c, tests/Support plus '
            . 'the two stderr censuses), and a silent cross-lane edit is worse than a recorded '
            . 'one. Delete this row with the fix.',
    ];

    /**
     * Functions that read reflection line numbers whose SLICE this scanner
     * cannot reach — the indexing is more than one call hop away, or lives in
     * another file — each with the reason it is left that way.
     *
     * EMPTY IS THE CORRECT STATE AND IT IS NOT THE SAME AS UNCHECKED. Every
     * such read is currently either indexing in its own body or delegating one
     * hop inside its own file, so nothing needs a row. The roster exists
     * because the alternative to it is a `continue`, and a `continue` here
     * would read a reader the scanner has lost track of as a reader that is
     * fine. Both directions are checked, so a row that outlives its site fails
     * exactly like a site that arrives without a row.
     *
     * @var array<string,string>
     */
    private const SLICE_UNREACHABLE = [];

    public function testEveryReflectionLineSliceReaderNamesTheDeclaringFile(): void
    {
        $this->assertTheScannerIsAlive();

        [$readers, $problems, $unreachable] = self::readers(self::everyTestFile());

        // RULE: GO RED ON WHAT YOU CANNOT PARSE. A slice site this scanner
        // cannot attribute to a function is not "clean" — it is a reader that
        // has silently stopped being covered.
        self::assertSame([], $problems, \sprintf(
            "%d reflection line-slice site(s) this scanner could not place.\n  %s",
            \count($problems),
            \implode("\n  ", $problems),
        ));

        self::assertNotSame([], $readers, 'no reader was found anywhere under tests/, so this '
            . 'census is a statement about a walk that is not running rather than about the tree');

        // RULE: GO RED ON WHAT YOU CANNOT PARSE, the second arm. A reflection
        // read whose slice this scanner cannot reach is not a clean function;
        // it is a reader it has lost, and losing one silently is the shape of
        // the defect that made this file necessary in the first place.
        $unrostered = array_diff_key($unreachable, self::SLICE_UNREACHABLE);
        self::assertSame([], $unrostered, \sprintf(
            "%d function(s) read reflection line numbers whose slice is out of this scanner's "
            . "reach — not in the function, and not in a same-file function it calls. That is a "
            . "reader this census can no longer say anything about, which is what happens when "
            . "the indexing is lifted into a shared helper. Route the read and the slice back "
            . "into reach of one another, or add a row to SLICE_UNREACHABLE with the reason.\n  %s",
            \count($unrostered),
            \implode("\n  ", array_map(
                static fn (string $key, string $at): string => $key . ' (' . $at . ')',
                array_keys($unrostered),
                $unrostered,
            )),
        ));

        $staleRows = array_diff_key(self::SLICE_UNREACHABLE, $unreachable);
        self::assertSame([], $staleRows, 'a SLICE_UNREACHABLE row describes a site that is no '
            . 'longer out of reach (or no longer exists). Delete it — a row that outlives its '
            . 'site is a standing permission nobody re-argued.');

        $unrecorded = [];
        foreach ($readers as $key => $namesTheFile) {
            if ($namesTheFile || isset(self::DECLARING_FILE_UNCHECKED[$key])) {
                continue;
            }
            $unrecorded[] = $key;
        }

        self::assertSame([], $unrecorded, self::unrecordedMessage($unrecorded));

        $overtaken = [];
        foreach (self::DECLARING_FILE_UNCHECKED as $key => $reason) {
            self::assertNotSame('', trim($reason), $key . ' is recorded without a reason');

            if (!\array_key_exists($key, $readers)) {
                $overtaken[] = $key . ' (no such reader any more)';
            } elseif ($readers[$key]) {
                $overtaken[] = $key . ' (it names the declaring file now)';
            }
        }

        self::assertSame([], $overtaken, 'a recorded reader has been fixed, renamed or deleted. '
            . 'Delete the row — a row that outlives the reader it describes is how this census '
            . 'quietly stops covering anything. If the reader was merely RENAMED, re-key the row: '
            . 'the fix has not happened.');
    }

    /**
     * The failure text for {@see testEveryReflectionLineSliceReaderNamesTheDeclaringFile()},
     * EXTRACTED SO SOMETHING CAN RUN IT WITH A NON-EMPTY POPULATION.
     *
     * A FAILURE MESSAGE'S GENERATOR IS THE ONE PART OF A GREEN SUITE THAT
     * NEVER REALLY RUNS (E270). PHP evaluates an assertion's message argument
     * eagerly, so an inline `sprintf()` here would be executed on every green
     * run — but only ever over the EMPTY list, which exercises none of the
     * formatting a reader will actually be handed. The one moment this text
     * matters is the moment nobody has read it yet.
     */
    private static function unrecordedMessage(array $unrecorded): string
    {
        return \sprintf(
            "%d reader(s) slice a line array with reflection's line numbers and never name the "
            . "file those numbers came from. For a method reached through a TRAIT, reflection "
            . "answers with the trait's file and the trait's lines, and slicing some other file "
            . "with them returns unrelated source with no error of any kind — every figure "
            . "measured from it is then a figure about the wrong method, reported under the "
            . "right method's name. Slice `file(\$reflection->getFileName())`, or assert "
            . "`__FILE__` against it, or add the reader to DECLARING_FILE_UNCHECKED with the "
            . "reason.\n  %s",
            \count($unrecorded),
            \implode("\n  ", $unrecorded),
        );
    }

    /**
     * THE FAILURE TEXT IS RUN OVER A REAL POPULATION, which a green suite never
     * does for it (E270).
     *
     * What this catches is small and real: a message that names none of the
     * offenders, or names the count and not the rows, or interpolates the wrong
     * variable. All three read as a perfectly green test until the day the
     * guard fires, and on that day the reader is handed a paragraph with the
     * evidence missing from it.
     */
    public function testTheFailureTextNamesEveryReaderItWasHandedAndCountsThem(): void
    {
        $message = self::unrecordedMessage(['a/A.php::one', 'b/B.php::two']);

        self::assertStringContainsString('2 reader(s)', $message, 'the count is not the population\'s');
        self::assertStringContainsString('a/A.php::one', $message, 'the first offender is not named');
        self::assertStringContainsString('b/B.php::two', $message, 'the second offender is not named');
        self::assertStringContainsString('DECLARING_FILE_UNCHECKED', $message, 'the message does not '
            . 'say what to do, which is the half a reader acts on');

        // AND THE EMPTY CASE STILL READS AS PROSE RATHER THAN AS A CRASH. This
        // is the population the green suite really does hand it, every run.
        self::assertStringContainsString('0 reader(s)', self::unrecordedMessage([]));
    }

    /**
     * KNOWN-ANSWER CONTROL, in the same test that asserts an absence.
     *
     * Rule 15: the assertion above is "nothing unrecorded", which a scanner
     * that finds nothing satisfies perfectly. The positive is the load-bearing
     * half — a source with an unchecked reader must come back as one.
     */
    private function assertTheScannerIsAlive(): void
    {
        $unchecked = <<<'PHP'
            <?php
            final class A
            {
                private function body(string $method): string
                {
                    $r = new \ReflectionMethod(self::class, $method);

                    return implode('', array_slice(
                        file(__FILE__) ?: [],
                        $r->getStartLine() - 1,
                        $r->getEndLine() - $r->getStartLine() + 1,
                    ));
                }
            }
            PHP;

        [$readers, $problems, $unreachable] = self::readers(['a/A.php' => $unchecked]);
        self::assertSame(['a/A.php::body' => false], $readers, 'an unchecked reader was not '
            . 'reported as one. Until this passes, every answer this census gives is a '
            . 'statement about a dead walk.');
        self::assertSame([], $problems);
        self::assertSame([], $unreachable);

        // ...AND THE CHECKED FORM IS SPARED, or the predicate is stuck at yes
        // and every reader in the tree is on the hook.
        $checked = str_replace('file(__FILE__)', 'file((string) $r->getFileName())', $unchecked);
        [$readers] = self::readers(['a/A.php' => $checked]);
        self::assertSame(['a/A.php::body' => true], $readers, 'a reader that slices the file '
            . 'reflection named was reported as unchecked');

        // ...AND A READER THAT DELEGATES THE INDEXING IS STILL A READER. THIS
        // IS THE FIXTURE THE FIRST CUT OF THIS CENSUS DID NOT HAVE, and its
        // absence is why the census could not see the very reader it was
        // written about: `VhsTapeContractTest::modelMethodTokens()` takes the
        // line numbers and hands them to `declaredSlice()`. Keyed on
        // both-in-one-function, the caller fell out for want of `array_slice`
        // and the callee fell out for want of a reflection call, so re-opening
        // the caller's original defect left this file green. Both halves of the
        // delegating pair are exercised here, in both polarities.
        $delegatedUnchecked = <<<'PHP'
            <?php
            final class A
            {
                private function body(string $method): string
                {
                    $r = new \ReflectionMethod(self::class, $method);

                    return self::cut(file(__FILE__) ?: [], $r->getStartLine(), $r->getEndLine());
                }

                private static function cut(array $lines, int $from, int $to): string
                {
                    return implode('', array_slice($lines, $from - 1, $to - $from + 1));
                }
            }
            PHP;

        [$readers, $problems, $unreachable] = self::readers(['a/A.php' => $delegatedUnchecked]);
        self::assertSame(['a/A.php::body' => false], $readers, 'a reader that hands the line '
            . 'numbers to a same-file helper to do the indexing was not selected at all. That '
            . 'is the exact blindness this census shipped with: the caller has no array_slice '
            . 'and the callee has no reflection call, so keying on both-in-one-function drops '
            . 'the pair entirely and the census reports on a tree it cannot see.');
        self::assertSame([], $problems);
        self::assertSame([], $unreachable, 'a delegating reader was filed as out-of-reach rather '
            . 'than followed one hop, which is the hop this scanner claims to make');

        $delegatedChecked = str_replace(
            'file(__FILE__)',
            'file((string) $r->getFileName())',
            $delegatedUnchecked,
        );
        [$readers] = self::readers(['a/A.php' => $delegatedChecked]);
        self::assertSame(['a/A.php::body' => true], $readers, 'a delegating reader that names '
            . 'the declaring file was reported as unchecked');

        // ...AND THE CHECK COUNTS FROM EITHER HALF OF THE PAIR. The reader and
        // the helper it delegates to are jointly responsible for the pairing,
        // so the assertion may honestly live in the helper.
        [$readers] = self::readers(['a/A.php' => str_replace(
            'return implode(\'\', array_slice(',
            'assert($lines === file((string) (new \ReflectionClass(self::class))->getFileName()));'
                . "\n        return implode('', array_slice(",
            $delegatedUnchecked,
        )]);
        self::assertSame(['a/A.php::body' => true], $readers, 'the check was ignored because it '
            . 'sits in the helper that does the slicing rather than in the function that reads '
            . 'the line numbers, which is one of the two places it can honestly be');

        // ...BUT ONLY THE HELPER THAT DOES THE SLICING JOINS THE COVERAGE SET.
        // The hop exists to follow the LINE NUMBERS, so absorbing every
        // same-file callee would let a `getFileName` in an unrelated helper —
        // a logging line, a path assertion about something else entirely —
        // answer for a reader that never checks anything. This mutation
        // SURVIVED the fixtures above, which had no case where a non-slicing
        // neighbour holds the name; the window was wrong, not the mutation.
        $launderable = <<<'PHP'
            <?php
            final class A
            {
                private function body(string $method): string
                {
                    $r = new \ReflectionMethod(self::class, $method);
                    $this->note();

                    return implode('', array_slice(file(__FILE__) ?: [], $r->getStartLine() - 1, 2));
                }

                private function note(): void
                {
                    error_log((string) (new \ReflectionClass(self::class))->getFileName());
                }
            }
            PHP;

        [$readers] = self::readers(['a/A.php' => $launderable]);
        self::assertSame(['a/A.php::body' => false], $readers, 'a getFileName() in a same-file '
            . 'helper that does no slicing was counted as this reader\'s check. The hop follows '
            . 'the line numbers to the indexing; a neighbour that never indexes anything is not '
            . 'jointly responsible for the pairing and cannot vouch for it.');

        // ...AND A READ WHOSE SLICE IS OUT OF REACH IS REPORTED, NOT DROPPED.
        // A function that reads a line number and reaches no slice — because
        // it never slices, or because the indexing was lifted into a shared
        // class past this scanner's one-hop bound — is a reader this scanner
        // has LOST. Filing it is what makes the bound honest; a `continue`
        // here would make an out-of-reach reader indistinguishable from a
        // clean one, which is rule 14 exactly.
        [$readers, $problems, $unreachable] = self::readers([
            'a/A.php' => "<?php\nfinal class A { private function n(\$r): int { return \$r->getStartLine(); } }\n",
        ]);
        self::assertSame([], $readers, 'a function with no slice in reach was reported as a '
            . 'reader whose file check can be judged, which it cannot be');
        self::assertSame([], $problems);
        self::assertSame(['a/A.php::n' => 'a/A.php:2'], $unreachable, 'a reflection read with no '
            . 'slice in reach was dropped instead of being filed. An expected-empty assertion '
            . 'here would survive the scanner being deleted outright; this one does not.');

        // ...AND A SLICE SITE THIS SCANNER CANNOT ATTRIBUTE IS REPORTED.
        [, $problems] = self::readers([
            'a/A.php' => "<?php\n\$b = array_slice(file(__FILE__), \$r->getStartLine(), 2);\n",
        ]);
        self::assertNotSame([], $problems, 'a slice site at file scope was dropped instead of '
            . 'being reported — a site silently skipped is indistinguishable from one cleared');
    }

    /**
     * Every function that reaches a line-array slice with reflection's line
     * numbers, keyed `<relative path>::<function>`, valued by whether its
     * coverage set names the declaring file.
     *
     * SELECTION IS ON THE REFLECTION READ, NOT ON THE SLICE, and that is the
     * whole correction. Keying on both-in-one-function looks tighter and is
     * strictly blinder: it cannot see a reader that delegates the indexing, and
     * it drops the delegate too, because the delegate holds no reflection call.
     * The slice is therefore chased ONE HOP into the functions declared in the
     * same file, and a read whose slice is not reachable that way comes back in
     * the third return rather than being skipped.
     *
     * ONE HOP, WITHIN ONE FILE, AND NO FURTHER. Two hops or a cross-file
     * resolution would need a call graph over the whole suite, which is a
     * second instrument nobody could check. The bound is honest because
     * overrunning it is REPORTED — see the third return — rather than read as
     * a clean file.
     *
     * @param array<string,string> $sources relative path => source
     *
     * @return array{array<string,bool>, list<string>, array<string,string>}
     *         readers, unattributable sites, reads whose slice is out of reach
     */
    private static function readers(array $sources): array
    {
        $readers = [];
        $problems = [];
        $unreachable = [];

        foreach ($sources as $relative => $source) {
            $tokens = \token_get_all($source);
            $ranges = TokenFunctionRanges::scan($tokens);

            $sameFile = [];
            foreach ($ranges as $range) {
                $sameFile[$range['name']] = $range;
            }

            foreach ($tokens as $i => $token) {
                if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== 'getStartLine') {
                    continue;
                }

                $enclosing = TokenFunctionRanges::enclosing($ranges, $i);
                if ($enclosing === null) {
                    $problems[] = $relative . ':' . $token[2]
                        . ': a getStartLine() site at file scope, which this scanner cannot '
                        . 'attribute to a function and therefore cannot say anything about';

                    continue;
                }

                $key = $relative . '::' . $enclosing['name'];
                $names = self::namesIn($tokens, $enclosing['from'], $enclosing['to']);

                // THE COVERAGE SET: this function, plus every same-file
                // function it calls that does the indexing for it. Both are
                // responsible for the pairing, so a check in either one counts.
                $coverage = [$names];

                foreach (array_keys($names) as $called) {
                    if ($called === $enclosing['name'] || !isset($sameFile[$called])) {
                        continue;
                    }

                    $hop = self::namesIn($tokens, $sameFile[$called]['from'], $sameFile[$called]['to']);
                    if (isset($hop['array_slice'])) {
                        $coverage[] = $hop;
                    }
                }

                if (!isset($names['array_slice']) && \count($coverage) === 1) {
                    $unreachable[$key] = $relative . ':' . $token[2];

                    continue;
                }

                $namesTheFile = false;
                foreach ($coverage as $set) {
                    if (isset($set['getFileName'])) {
                        $namesTheFile = true;

                        break;
                    }
                }

                $readers[$key] = $namesTheFile;
            }
        }

        ksort($readers);
        sort($problems);
        ksort($unreachable);

        return [$readers, $problems, $unreachable];
    }

    /**
     * The set of function/method names called between two token indices.
     *
     * @param list<array{int,string,int}|string> $tokens
     *
     * @return array<string,true>
     */
    private static function namesIn(array $tokens, int $from, int $to): array
    {
        $names = [];

        for ($j = $from; $j <= $to; $j++) {
            $token = $tokens[$j] ?? null;
            if (!\is_array($token)) {
                continue;
            }
            if ($token[0] !== \T_STRING && $token[0] !== \T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            $names[\ltrim($token[1], '\\')] = true;
        }

        return $names;
    }

    /**
     * Every `.php` under `tests/`, keyed by its path relative to `tests/`.
     *
     * @return array<string,string> relative path => source
     */
    private static function everyTestFile(): array
    {
        $root = \dirname(__DIR__);
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $text = file_get_contents($file->getPathname());
            self::assertIsString($text, $relative . ' is unreadable, so this census is void: an '
                . 'unreadable source parses as no tokens, no tokens is no readers, and no '
                . 'readers is what a clean file looks like');

            $found[$relative] = $text;
        }

        ksort($found);

        return $found;
    }
}
