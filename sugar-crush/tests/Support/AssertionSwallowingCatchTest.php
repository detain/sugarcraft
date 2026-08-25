<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * A `catch` AROUND AN ASSERTION CATCHES THE ASSERTION'S OWN FAILURE.
 *
 * THE MECHANISM, MEASURED RATHER THAN ASSUMED (PHP 8.3.6, PHPUnit 10.5.64,
 * asserted below rather than only written here):
 * `ExpectationFailedException` — what a failed `assert*()` throws — extends
 * `AssertionFailedError` extends `PHPUnit\Framework\Exception` extends
 * `\RuntimeException` extends `\Exception`. `fail()` throws the second of
 * those. So a `catch` on ANY of five types swallows a failing assertion, and
 * the one people reach for when they want to be narrow — `\RuntimeException` —
 * is on that list.
 *
 * THAT IS WHY THE ALPHABET HERE IS NOT `\Throwable`. The finding this guard
 * came from was written up as a `catch (\Throwable)` problem and censused as
 * ten sites; scanning for every type that is actually a supertype of a failed
 * assertion found the population is dominated by `\RuntimeException`, and
 * `\Throwable` accounts for a small minority of it. Rule 11: a census reports
 * zero for whatever its alphabet cannot express, and this one's alphabet had
 * been written from the shape of the first example rather than from the
 * hierarchy.
 *
 * WHAT THIS GUARD REDS ON, and it is deliberately the narrow half: a `try`
 * whose body asserts, paired with a `catch` that can swallow the failure and
 * whose OWN body neither asserts nor rethrows. There the failure is gone with
 * no trace at all — the test continues as though the assertion had passed.
 * `tests/Agents/TeamTest.php` held exactly that shape, `fail()` inside the
 * `try` under a `catch (\RuntimeException) { // expected }`, and its later
 * assertions are the only reason the test was not silently green.
 *
 * WHAT IT DOES NOT RED ON, AND WHY THAT IS A RECORDED FINDING RATHER THAN AN
 * EXEMPTION. A swallowing `catch` whose body DOES assert is still wrong — the
 * `fail()` message from inside the `try` becomes the subject of the catch
 * block's assertions, so the diagnostic is about the wrong exception — but it
 * cannot go silently green, because those assertions were written for the
 * exception the code under test throws and will not hold for a
 * `AssertionFailedError`. There are around twenty such sites (the count is
 * derived by {@see swallowingCatches()} on every run and is deliberately not
 * written down as a number here, rule 18). Restructuring them is a mechanical
 * change across files five audit lanes own concurrently, and it is filed as a
 * follow-up rather than half-done. {@see testTheWiderSwallowingPopulationIsStillVisible()}
 * keeps the scanner honest about it: if that population collapses to nothing,
 * this instrument has gone blind rather than the tree having got clean.
 *
 * @internal
 */
final class AssertionSwallowingCatchTest extends TestCase
{
    /**
     * Every type whose `catch` swallows a failed assertion.
     *
     * ASSERTED, NOT TRUSTED, by {@see testTheHierarchyThisGuardRestsOnIsWhatItSays()}.
     * A PHPUnit upgrade that re-parented `ExpectationFailedException` would
     * make this list wrong in the direction that matters — too narrow — and
     * nothing else in the suite would notice.
     *
     * @var list<string>
     */
    private const SWALLOWS_AN_ASSERTION_FAILURE = [
        'Throwable',
        'Exception',
        'RuntimeException',
        'PHPUnit\\Framework\\Exception',
        'AssertionFailedError',
        'ExpectationFailedException',
    ];

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertIsString($root);

        return $root;
    }

    /**
     * The hierarchy the alphabet above is derived from, on the PHPUnit this
     * suite actually runs.
     *
     * NOT DECORATION. Every other assertion in this file is downstream of the
     * claim that `\RuntimeException` catches a failed assertion; if that stops
     * being true the guard keeps passing while pointing at the wrong types.
     */
    public function testTheHierarchyThisGuardRestsOnIsWhatItSays(): void
    {
        $chain = [];
        for ($class = ExpectationFailedException::class; $class !== false; $class = get_parent_class($class)) {
            $chain[] = $class;
        }

        $this->assertSame(
            [
                ExpectationFailedException::class,
                AssertionFailedError::class,
                'PHPUnit\\Framework\\Exception',
                'RuntimeException',
                'Exception',
            ],
            $chain,
            'a failed assertion no longer has the ancestry this guard scans for; the type list '
            . 'in SWALLOWS_AN_ASSERTION_FAILURE has to be re-derived from this chain',
        );

        // AND THE CONSEQUENCE, DEMONSTRATED RATHER THAN INFERRED: a catch that
        // looks narrow eats the assertion.
        //
        // ROUTED THROUGH A CALLABLE, AND THAT IS NOT AN ACCIDENT (rule 26). A
        // literal assertion written inside a `try` in THIS file would be the
        // exact shape this file exists to forbid, and the scan below - which
        // reads its own directory - reported it on the first run. It is also
        // what a helper honestly looks like, and it states the scanner's real
        // limit out loud: this is a TOKEN-level walk and it cannot see an
        // assertion reached through a callable. That limit holds in the tree at
        // large, which is why the population control below exists.
        $this->assertTrue(
            $this->assertionFailureEscapesInto(static function (): void {
                self::assertSame(1, 2, 'this failure is deliberate');
            }),
            'a failed assertion was NOT caught by catch (\\RuntimeException), so the premise of '
            . 'this whole guard is false on this PHPUnit and the scan below is measuring nothing',
        );
    }

    /**
     * Whether a `catch (\RuntimeException)` around `$body` catches what `$body`
     * throws.
     *
     * The catch body RECORDS rather than asserts, so the caller - outside the
     * `try` - is what decides. That is the same restructuring this guard's
     * failure message asks an offender to make.
     */
    private function assertionFailureEscapesInto(callable $body): bool
    {
        try {
            $body();
        } catch (\RuntimeException) {
            return true;
        }

        return false;
    }

    /**
     * Every `try`/`catch` in `tests/` whose catch can swallow an assertion
     * failure raised inside the `try`.
     *
     * TOKEN STREAM AND NOT A REGEX, for the ordinary reason: `catch` appears in
     * prose, in heredoc fixtures that are child scripts, and in doc-blocks
     * discussing this very defect. `token_get_all()` sees a heredoc body as one
     * string token, so a fixture's own `catch` is correctly invisible.
     *
     * @return list<array{file: string, line: int, types: list<string>, catchAsserts: bool, rethrows: bool}>
     */
    private function swallowingCatches(): array
    {
        $root = $this->root();
        $found = [];

        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root . '/tests',
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($walk as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString(
                $source,
                $file->getPathname() . ' could not be read, so this scan does not speak for it',
            );
            $label = substr($file->getPathname(), \strlen($root) + 1);
            foreach ($this->swallowingCatchesIn($source) as $row) {
                $found[] = ['file' => $label] + $row;
            }
        }
        usort($found, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $found;
    }

    /**
     * The same scan over one source, so a fixture can drive it (rule 15).
     *
     * @return list<array{line: int, types: list<string>, catchAsserts: bool, rethrows: bool}>
     */
    private function swallowingCatchesIn(string $source): array
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_TRY) {
                continue;
            }
            [$tryStart, $tryEnd] = $this->braceRun($tokens, $i, $count);
            $tryAsserts = $this->assertsBetween($tokens, $tryStart, $tryEnd);

            $k = $tryEnd + 1;
            while ($k < $count) {
                while (
                    $k < $count && \is_array($tokens[$k])
                    && \in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                ) {
                    $k++;
                }
                if ($k >= $count || !\is_array($tokens[$k]) || $tokens[$k][0] !== T_CATCH) {
                    break;
                }
                $line = $tokens[$k][2];
                $types = $this->caughtTypes($tokens, $k, $count);
                [$catchStart, $catchEnd] = $this->braceRun($tokens, $k, $count);

                if ($tryAsserts && array_intersect($types, self::SWALLOWS_AN_ASSERTION_FAILURE) !== []) {
                    $found[] = [
                        'line' => $line,
                        'types' => $types,
                        'catchAsserts' => $this->assertsBetween($tokens, $catchStart, $catchEnd),
                        'rethrows' => $this->rethrowsBetween($tokens, $catchStart, $catchEnd),
                    ];
                }
                $k = $catchEnd + 1;
            }
            $i = $tryEnd;
        }

        return $found;
    }

    /**
     * The `{ … }` that follows token `$from`, as `[openIndex, closeIndex]`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: int}
     */
    private function braceRun(array $tokens, int $from, int $count): array
    {
        $i = $from;
        while ($i < $count && $tokens[$i] !== '{') {
            $i++;
        }
        self::assertLessThan($count, $i, 'a try/catch with no block; this scan cannot answer for that source');
        $open = $i;
        $depth = 0;
        for (; $i < $count; $i++) {
            if ($tokens[$i] === '{') {
                $depth++;
            } elseif ($tokens[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$open, $i];
                }
            }
        }

        self::fail('an unterminated block; a source this scan cannot read must go red, not score zero');
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function assertsBetween(array $tokens, int $from, int $to): bool
    {
        for ($i = $from + 1; $i < $to; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
                continue;
            }
            $name = $tokens[$i][1];
            // `markTestSkipped()` counts: it also leaves the enclosing block by
            // throwing, so a catch that calls it is not silently continuing.
            if (str_starts_with($name, 'assert') || $name === 'fail' || $name === 'markTestSkipped') {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function rethrowsBetween(array $tokens, int $from, int $to): bool
    {
        for ($i = $from + 1; $i < $to; $i++) {
            if (\is_array($tokens[$i]) && $tokens[$i][0] === T_THROW) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class names one `catch (…)` names, unqualified leading `\` stripped.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    private function caughtTypes(array $tokens, int $from, int $count): array
    {
        $i = $from;
        while ($i < $count && $tokens[$i] !== '(') {
            $i++;
        }
        $types = [];
        $current = '';
        for ($i++; $i < $count; $i++) {
            if ($tokens[$i] === ')') {
                break;
            }
            if ($tokens[$i] === '|') {
                if ($current !== '') {
                    $types[] = $current;
                }
                $current = '';

                continue;
            }
            if (!\is_array($tokens[$i])) {
                continue;
            }
            if (\in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $current .= $tokens[$i][1];
            } elseif ($tokens[$i][0] === T_VARIABLE) {
                if ($current !== '') {
                    $types[] = $current;
                }
                $current = '';
            }
        }
        if ($current !== '') {
            $types[] = $current;
        }

        return array_values(array_map(static fn (string $t): string => ltrim($t, '\\'), $types));
    }

    /**
     * No assertion failure disappears into a catch that does nothing with it.
     */
    public function testNoCatchSilentlyEatsAnAssertionFailure(): void
    {
        $silent = [];
        foreach ($this->swallowingCatches() as $row) {
            if ($row['catchAsserts'] || $row['rethrows']) {
                continue;
            }
            $silent[] = $row['file'] . ':' . $row['line'] . ' catch(' . implode('|', $row['types']) . ')';
        }

        $this->assertSame(
            [],
            $silent,
            "this catch can catch PHPUnit's own assertion failure — a failed assert*() or fail() "
            . 'inside the try lands here — and its body neither asserts nor rethrows, so the '
            . 'failure is gone and the test continues as if the assertion had passed. Move the '
            . 'assertion out of the try (record whether it threw, assert afterwards); narrowing '
            . 'the catch to \\RuntimeException does NOT help, that type swallows it too',
        );
    }

    /**
     * The wider population is still there, and the scanner can still see it.
     *
     * RULE 15, AND RULE 25 UNDER IT. The assertion above expects an empty list,
     * which is also what a scanner that matches nothing returns. This is the
     * population control: the swallowing-catch class is large in this tree and
     * a run that finds almost none of it has a broken instrument rather than a
     * clean suite. The floor is well under the measured population so ordinary
     * work can move it; it is a liveness check, not a budget.
     */
    public function testTheWiderSwallowingPopulationIsStillVisible(): void
    {
        $all = $this->swallowingCatches();

        $this->assertGreaterThanOrEqual(
            12,
            \count($all),
            'the swallowing-catch scanner found almost nothing, so its verdict that none of them '
            . 'is silent means nothing either',
        );

        // AND THE TYPE THAT MAKES THIS FINDING WHAT IT IS. If every hit is a
        // `\Throwable`, the alphabet has quietly narrowed back to the shape the
        // original write-up had, and the majority of the population is invisible.
        $viaRuntimeException = array_filter(
            $all,
            static fn (array $r): bool => \in_array('RuntimeException', $r['types'], true),
        );
        $this->assertNotSame(
            [],
            $viaRuntimeException,
            'no catch(\\RuntimeException) is being reported, so the alphabet has narrowed back to '
            . '\\Throwable and most of the population is unseen',
        );
    }

    /**
     * A source this scanner has never seen, whose answer is known.
     *
     * Four cases in one fixture, because each kills a different way the scan
     * can be wrong: the silent shape must be FOUND, a catch whose body asserts
     * must be found but not called silent, a catch that rethrows likewise, and
     * a NARROW catch over an asserting try must not be reported at all.
     */
    public function testTheScannerAgreesWithASourceWhoseAnswerIsKnown(): void
    {
        $fixture = <<<'PHP'
            <?php
            final class Fixture {
                public function silent(): void {
                    try { $this->assertSame(1, 1); } catch (\RuntimeException) { }
                }
                public function asserting(): void {
                    try { $this->fail('x'); } catch (\Throwable $e) { $this->assertTrue(true); }
                }
                public function rethrowing(): void {
                    try { $this->assertSame(1, 1); } catch (\Exception $e) { throw $e; }
                }
                public function narrow(): void {
                    try { $this->assertSame(1, 1); } catch (\TypeError) { }
                }
                public function noAssertionInTry(): void {
                    try { $this->run(); } catch (\RuntimeException) { }
                }
            }
            PHP;

        $rows = $this->swallowingCatchesIn($fixture);

        $this->assertSame(
            [
                ['line' => 4, 'types' => ['RuntimeException'], 'catchAsserts' => false, 'rethrows' => false],
                ['line' => 7, 'types' => ['Throwable'], 'catchAsserts' => true, 'rethrows' => false],
                ['line' => 10, 'types' => ['Exception'], 'catchAsserts' => false, 'rethrows' => true],
            ],
            $rows,
            'the scanner does not agree with a source whose every catch was written to be '
            . 'classified one way; with this red, its verdict over tests/ is worthless',
        );
    }
}
