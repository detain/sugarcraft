<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A READER THAT SLICES A LINE ARRAY WITH REFLECTION'S LINE NUMBERS MUST BE
 * ADDRESSING THE FILE THOSE NUMBERS CAME FROM, AND ELEVEN OF THEM DO IT.
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
 * reader is safe when its enclosing function NAMES `getFileName` at all, which
 * covers both honest spellings: slicing `file($reflection->getFileName())`
 * directly, which cannot be wrong, and slicing `__FILE__` after asserting the
 * two agree. IT DOES NOT CHECK WHAT THE FUNCTION DOES WITH THE NAME — one that
 * fetched it and ignored it would pass. That is a coarser question than the
 * real one and it is the one that can be answered from tokens; the alternative
 * is a dataflow analysis whose own correctness nobody could check. The bound is
 * here so the next reader knows what the green means.
 *
 * THE SECOND HALF OF THE ORIGINAL DEFECT IS NOT COVERED BY THIS FILE, and the
 * omission is deliberate and recorded. Reflection's line numbers are fixed when
 * the class loads while `file()` is read on every call, so an edit to the file
 * in between shifts every slice WHILE THE FILE NAME STILL MATCHES. That half
 * was the one actually OBSERVED. Only `VhsTapeContractTest` guards it, through
 * a `declaredSlice()` that refuses unless the slice's first line spells
 * `function <name>`. Every other reader here is open to it. Rostering ten
 * readers in nine files across five concurrently-merging lanes would red on
 * every rename, so it is a backlog entry rather than a guard — see the round-49
 * lane c report.
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

    public function testEveryReflectionLineSliceReaderNamesTheDeclaringFile(): void
    {
        $this->assertTheScannerIsAlive();

        [$readers, $problems] = self::readers(self::everyTestFile());

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

        [$readers, $problems] = self::readers(['a/A.php' => $unchecked]);
        self::assertSame(['a/A.php::body' => false], $readers, 'an unchecked reader was not '
            . 'reported as one. Until this passes, every answer this census gives is a '
            . 'statement about a dead walk.');
        self::assertSame([], $problems);

        // ...AND THE CHECKED FORM IS SPARED, or the predicate is stuck at yes
        // and every reader in the tree is on the hook.
        $checked = str_replace('file(__FILE__)', 'file((string) $r->getFileName())', $unchecked);
        [$readers] = self::readers(['a/A.php' => $checked]);
        self::assertSame(['a/A.php::body' => true], $readers, 'a reader that slices the file '
            . 'reflection named was reported as unchecked');

        // ...AND A FUNCTION THAT ASKS FOR A LINE NUMBER WITHOUT SLICING
        // ANYTHING IS NOT A READER. Selection is on the SLICE: a test that
        // merely asserts a declaration's line has nothing to address wrongly.
        [$readers] = self::readers([
            'a/A.php' => "<?php\nfinal class A { private function n(\$r): int { return \$r->getStartLine(); } }\n",
        ]);
        self::assertSame([], $readers, 'a function that reads a line number without slicing was '
            . 'selected as a reader, which puts every reflection assertion in the tree on the hook');

        // ...AND A SLICE SITE THIS SCANNER CANNOT ATTRIBUTE IS REPORTED.
        [, $problems] = self::readers([
            'a/A.php' => "<?php\n\$b = array_slice(file(__FILE__), \$r->getStartLine(), 2);\n",
        ]);
        self::assertNotSame([], $problems, 'a slice site at file scope was dropped instead of '
            . 'being reported — a site silently skipped is indistinguishable from one cleared');
    }

    /**
     * Every function that slices a line array with reflection's line numbers,
     * keyed `<relative path>::<function>`, valued by whether it names the
     * declaring file.
     *
     * @param array<string,string> $sources relative path => source
     *
     * @return array{array<string,bool>, list<string>} readers, problems
     */
    private static function readers(array $sources): array
    {
        $readers = [];
        $problems = [];

        foreach ($sources as $relative => $source) {
            $tokens = \token_get_all($source);
            $ranges = TokenFunctionRanges::scan($tokens);

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

                $names = self::namesIn($tokens, $enclosing['from'], $enclosing['to']);

                // SELECTION IS ON THE SLICE. A function that reads a line
                // number and never indexes a line array with it has nothing to
                // address wrongly, and taking every reflection call would put
                // half the suite on the hook — which is answered with
                // exemptions, which is where the next real one hides.
                if (!isset($names['array_slice'])) {
                    continue;
                }

                $readers[$relative . '::' . $enclosing['name']] = isset($names['getFileName']);
            }
        }

        ksort($readers);
        sort($problems);

        return [$readers, $problems];
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
