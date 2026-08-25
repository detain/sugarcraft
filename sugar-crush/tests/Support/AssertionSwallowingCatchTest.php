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
 * AND THE FIRST DRAFT OF THAT FIX MADE THE SAME MISTAKE ONE LEVEL DOWN. It
 * spelled the hierarchy as six literal strings and intersected them with the
 * types as WRITTEN — so an alphabet whose whole argument is the class hierarchy
 * was in fact keyed on spelling, and in this tree the PHPUnit types are spelled
 * fully qualified most of the time while the one qualified name in the list
 * never occurs at all. Five of the eight PHPUnit-typed catches were invisible.
 * The decision is now {@see swallowsAnAssertionFailure()}, which RESOLVES the
 * name a `catch` writes — through the file's own `use` imports and namespace —
 * and asks `is_a(ExpectationFailedException::class, $caught, true)`. A spelling
 * cannot buy its way in or out (rule 40), and a name that resolves to nothing
 * is REPORTED rather than assumed harmless (rule 14).
 *
 * THE WALK DESCENDS INTO A `try` BODY. It used to resume at the end of the
 * outer block, which skipped every nested `try` wholesale — ten sites in this
 * tree, MEASURED — so a `catch` inside one was never judged at all. That is
 * worse than judging it wrongly: the report and the blind spot are the same
 * empty list.
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
 * `AssertionFailedError`. The count is derived by {@see swallowingCatches()} on
 * every run and is deliberately not written down here (rule 18) — an earlier
 * draft of this sentence said "around twenty", which is a cardinality over
 * `tests/` and was wrong within two commits of being written. Restructuring
 * them is a mechanical change across files five audit lanes own concurrently,
 * and it is filed as a follow-up rather than half-done.
 * {@see testTheWiderSwallowingPopulationIsStillVisible()} keeps the scanner
 * honest about it: if that population collapses to nothing, this instrument has
 * gone blind rather than the tree having got clean.
 *
 * AND ONE SHAPE IS DELIBERATELY NOT RED, BECAUSE IT IS THE FIX. A `catch` that
 * RECORDS the failure into a variable an assertion after the `try` then reads
 * is exactly what this guard's own failure message prescribes; classifying it
 * as silent would red on correct code and hand the next reader an exemption row
 * to write instead — rule 33, where the classifier rather than the code is the
 * defect. The test for it is structural and not textual
 * ({@see recordsForLater()}), so no comment can buy it.
 *
 * @internal
 */
final class AssertionSwallowingCatchTest extends TestCase
{
    use RefusesAnUnreadableSourceTrait;
    use TestFileWalkTrait;

    /**
     * Caught type names this scan could not resolve to a real symbol.
     *
     * REPORTED, NOT DROPPED (rule 14). A `catch` whose type cannot be resolved
     * is a `catch` this guard has no opinion about, and an instrument that
     * silently has no opinion is indistinguishable from one that has a clean
     * verdict. {@see testEveryCaughtTypeInTestsResolvesToARealSymbol()} reds on
     * a non-empty list.
     *
     * @var list<string>
     */
    private array $unresolvable = [];


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
            'a failed assertion no longer has the ancestry this guard scans for; the reasoning '
            . 'in this file has to be re-derived from this chain rather than the assertion relaxed',
        );

        // AND THE DECISION, over each link and over a type that is NOT one.
        // The scan asks `is_a(ExpectationFailedException::class, $caught, true)`
        // — "is the caught type a SUPERTYPE of a failed assertion" — which is
        // the question, and which no list of spellings can answer.
        foreach ([...$chain, 'Throwable'] as $ancestor) {
            $this->assertTrue(
                is_a(ExpectationFailedException::class, $ancestor, true),
                $ancestor . ' is on the ancestry of a failed assertion but the test this scan '
                . 'makes says otherwise',
            );
        }
        foreach (['TypeError', 'InvalidArgumentException', 'LogicException'] as $unrelated) {
            $this->assertFalse(
                is_a(ExpectationFailedException::class, $unrelated, true),
                $unrelated . ' cannot catch a failed assertion, so a catch on it must not be '
                . 'reported; this scan says it can, and would flag correct code',
            );
        }

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
     * @return list<array{file: string, line: int, types: list<string>, catchAsserts: bool, rethrows: bool, recordsForLater: bool}>
     */
    private function swallowingCatches(): array
    {
        $found = [];

        // THE WALK AND THE READ ARE BORROWED, NOT COPIED. The first draft grew
        // its own roster helper and its own `realpath(__DIR__ . '/../..')`;
        // `DuplicatedTestHelperDriftTest` reported the second as a one-token
        // divergence from the copy in `SymbolCitationDriftTest`, which is
        // precisely what that guard exists to catch.
        foreach (self::everyTestFile() as $label => $path) {
            $source = self::readOrFail($path);
            foreach ($this->swallowingCatchesIn($source) as $row) {
                $found[] = ['file' => 'tests/' . $label] + $row;
            }
        }
        usort($found, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $found;
    }

    /**
     * The same scan over one source, so a fixture can drive it (rule 15).
     *
     * @return list<array{line: int, types: list<string>, catchAsserts: bool, rethrows: bool, recordsForLater: bool}>
     */
    private function swallowingCatchesIn(string $source): array
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);
        $imports = $this->importsIn($source);
        $namespace = $this->namespaceOf($source);
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

                if ($tryAsserts && $this->swallowsAnAssertionFailure($types, $imports, $namespace)) {
                    $found[] = [
                        'line' => $line,
                        'types' => $types,
                        'catchAsserts' => $this->assertsBetween($tokens, $catchStart, $catchEnd),
                        'rethrows' => $this->rethrowsBetween($tokens, $catchStart, $catchEnd),
                        'recordsForLater' => $this->recordsForLater($tokens, $catchStart, $catchEnd, $count),
                    ];
                }
                $k = $catchEnd + 1;
            }
            // DESCEND INTO THE `try` BODY RATHER THAN STEPPING OVER IT. Jumping
            // to `$tryEnd` skipped every nested `try` wholesale — a blind spot
            // with ten sites in this tree, MEASURED, and the reason a
            // `catch (\Throwable)` inside one was invisible rather than judged.
            // Resuming at `$tryStart` visits each `T_TRY` exactly once: the
            // outer one has just been handled and the walk moves forward from
            // its opening brace, so a nested one is found on its own terms.
            $i = $tryStart;
        }

        return $found;
    }

    /**
     * Whether any of the types this `catch` names can catch a failed assertion.
     *
     * THE QUESTION IS `is_a(ExpectationFailedException::class, $caught, true)`
     * — is the caught type a SUPERTYPE of what a failed `assert*()` throws —
     * and it is asked of a RESOLVED symbol, never of a spelling.
     *
     * WHAT THIS REPLACED, AND WHY (rule 7). It used to be a list of six literal
     * strings intersected with the tokens as written, holding the UNQUALIFIED
     * `AssertionFailedError` and `ExpectationFailedException` alongside a
     * FULLY-QUALIFIED `PHPUnit\Framework\Exception`. In this tree those first
     * two are spelled fully qualified most of the time and the third does not
     * occur at all, so the alphabet's spelling set and the tree's were nearly
     * disjoint for exactly the two types the hierarchy argument is about — the
     * scan was blind to most of the PHPUnit-typed catches while its doc-block
     * claimed to derive its alphabet from the hierarchy. Rule 11: a census
     * reports zero for whatever its alphabet cannot express, and an alphabet
     * written as spellings expresses spellings.
     *
     * ANYTHING THAT CANNOT BE RESOLVED IS RECORDED AND NOT ASSUMED HARMLESS
     * (rule 14); {@see testEveryCaughtTypeInTestsResolvesToARealSymbol()} reds
     * on it.
     *
     * @param list<string>          $types
     * @param array<string, string> $imports alias => fully-qualified
     */
    private function swallowsAnAssertionFailure(array $types, array $imports, string $namespace): bool
    {
        $swallows = false;
        foreach ($types as $written) {
            $fqn = $this->resolveCaughtType($written, $imports, $namespace);
            if ($fqn === null) {
                $this->unresolvable[] = $written;

                continue;
            }
            if (is_a(ExpectationFailedException::class, $fqn, true)) {
                $swallows = true;
            }
        }

        return $swallows;
    }

    /**
     * A type as a `catch` spells it, resolved to a symbol that exists.
     *
     * Three bases in order, which is how PHP itself resolves a name in a
     * namespaced file: as written (a leading `\` has already been stripped by
     * {@see caughtTypes()}, so a fully-qualified name lands here first), then
     * through the file's own `use` imports, then relative to the file's own
     * namespace.
     */
    private function resolveCaughtType(string $written, array $imports, string $namespace): ?string
    {
        $written = ltrim($written, '\\');
        if ($written === '') {
            return null;
        }

        $candidates = [$written];
        $head = explode('\\', $written)[0];
        if (isset($imports[$head])) {
            $candidates[] = $imports[$head] . substr($written, \strlen($head));
        }
        if ($namespace !== '') {
            $candidates[] = $namespace . '\\' . $written;
        }

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The `use` imports one source declares, alias => fully-qualified.
     *
     * `use function`/`use const` are excluded — they import a different symbol
     * table and can never name a catchable type — and a grouped `use A\{B, C};`
     * is left out rather than half-parsed, which makes any type it imports
     * UNRESOLVABLE and therefore reported rather than silently unmatched.
     *
     * @return array<string, string>
     */
    private function importsIn(string $source): array
    {
        preg_match_all(
            '/^use\s+(?!function\s|const\s)(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/m',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $imports = [];
        foreach ($matches as $row) {
            $fqn = ltrim($row[1], '\\');
            $position = strrpos($fqn, '\\');
            $short = $position === false ? $fqn : substr($fqn, $position + 1);
            $imports[($row[2] ?? '') !== '' ? $row[2] : $short] = $fqn;
        }

        return $imports;
    }

    /**
     * The namespace a source declares, or `''`.
     *
     * BYTE-IDENTICAL TO THE COPY IN {@see \SugarCraft\Crush\Tests\SymbolCitationDriftTest},
     * deliberately, and the parameter is named for that rather than for this
     * file. `DuplicatedTestHelperDriftTest` reports two copies of one private
     * helper that agree except for a single token — the shape where a fix lands
     * only in the copy a lane happens to own — and it reported exactly that on
     * the first draft of this method, where the parameter was `$source`.
     * Identical copies are the one state it treats as safe. Change one and
     * change both, or lift it into a trait.
     */
    private function namespaceOf(string $text): string
    {
        return preg_match('/^namespace\s+([^;]+);/m', $text, $m) === 1 ? trim($m[1]) : '';
    }

    /**
     * Whether the catch body RECORDS the failure for an assertion that follows
     * the `try` statement.
     *
     * THIS IS THE SHAPE THIS GUARD'S OWN FAILURE MESSAGE ASKS FOR, and without
     * it the guard reds on the fix it prescribes. `tests/Providers/…` holds the
     * canonical form: a `try` calling an assertion helper, a
     * `catch (\PHPUnit\Framework\AssertionFailedError) { $failed = true; }`,
     * and `assertTrue($failed, …)` on the next line. The catch body neither
     * asserts nor rethrows, so the two older tests classified it as SILENT —
     * and it is the deliberately-correct negative test of a validator. Rule 33:
     * when a guard offers an exemption row for code that is right, the
     * classifier is the defect.
     *
     * THE TEST IS STRUCTURAL, NOT TEXTUAL (rule 40). It is not "the catch body
     * does something"; it is that a variable ASSIGNED in the catch body appears
     * inside the argument list of an `assert*()`/`fail()` call reached before
     * the enclosing function ends. A comment cannot buy it, and neither can a
     * bare assignment nobody reads.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function recordsForLater(array $tokens, int $catchStart, int $catchEnd, int $count): bool
    {
        $recorded = [];
        for ($i = $catchStart + 1; $i < $catchEnd; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
                continue;
            }
            $j = $i + 1;
            while ($j < $catchEnd && \is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $catchEnd && $tokens[$j] === '=') {
                $recorded[$tokens[$i][1]] = true;
            }
        }
        if ($recorded === []) {
            return false;
        }

        // THE WINDOW IS THE REST OF THE ENCLOSING FUNCTION, and it ends where
        // the brace depth would go negative — the `}` that closes the method
        // this try/catch sits in. Reading past it would let an assertion in the
        // NEXT method excuse this one.
        $depth = 0;
        for ($i = $catchEnd + 1; $i < $count; $i++) {
            if ($tokens[$i] === '{' || (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;

                continue;
            }
            if ($tokens[$i] === '}') {
                if ($depth === 0) {
                    return false;
                }
                $depth--;

                continue;
            }
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
                continue;
            }
            $name = $tokens[$i][1];
            if (!str_starts_with($name, 'assert') && $name !== 'fail') {
                continue;
            }
            if ($this->argumentsMention($tokens, $i, $count, $recorded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the call whose name is at `$from` passes one of `$names`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, true>                           $names
     */
    private function argumentsMention(array $tokens, int $from, int $count, array $names): bool
    {
        $i = $from;
        while ($i < $count && $tokens[$i] !== '(') {
            if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                return false;
            }
            $i++;
        }
        $depth = 0;
        for (; $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;

                continue;
            }
            if ($tokens[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return false;
                }

                continue;
            }
            if (\is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE && isset($names[$tokens[$i][1]])) {
                return true;
            }
        }

        return false;
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
            // `"{$x}"` OPENS WITH AN ARRAY TOKEN AND CLOSES WITH A PLAIN `}`.
            // Counting only the literal brace sends the depth one level down at
            // the first interpolated string in a file and every try/catch
            // boundary after it is wrong. `InterpolationOpenerTokenTest` caught
            // exactly this in the first draft of this scanner, which is what
            // that guard is for.
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                continue;
            }
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
     * The rows in `$rows` where the failure vanishes: neither asserted on nor
     * rethrown.
     *
     * EXTRACTED BECAUSE A MUTATION OF IT SURVIVED. Written inline in the guard
     * below, `if (true) { continue; }` here — the change that makes the guard
     * report nothing at all, ever — passed the whole file: the guard's
     * assertion is `[]` and an instrument that returns `[]` satisfies it, and
     * the known-answer fixture drove `swallowingCatchesIn()` one level lower
     * and never reached this decision. The fixture asserts on THIS now as well.
     * Rule 2: when a mutation survives, suspect the assertion's window before
     * the mutation's relevance.
     *
     * @param list<array{file?: string, line: int, types: list<string>, catchAsserts: bool, rethrows: bool, recordsForLater: bool}> $rows
     *
     * @return list<string>
     */
    private function silentIn(array $rows): array
    {
        $silent = [];
        foreach ($rows as $row) {
            if ($row['catchAsserts'] || $row['rethrows'] || $row['recordsForLater']) {
                continue;
            }
            $silent[] = ($row['file'] ?? '(fixture)') . ':' . $row['line']
                . ' catch(' . implode('|', $row['types']) . ')';
        }

        return $silent;
    }

    /**
     * No assertion failure disappears into a catch that does nothing with it.
     */
    public function testNoCatchSilentlyEatsAnAssertionFailure(): void
    {
        $this->assertSame(
            [],
            $this->silentIn($this->swallowingCatches()),
            "this catch can catch PHPUnit's own assertion failure — a failed assert*() or fail() "
            . 'inside the try lands here — and its body neither asserts nor rethrows, so the '
            . 'failure is gone and the test continues as if the assertion had passed. Move the '
            . 'assertion out of the try (record whether it threw, assert afterwards); narrowing '
            . 'the catch to \\RuntimeException does NOT help, that type swallows it too',
        );
    }

    /**
     * The scanner can still see the population, and the scan still works on
     * real files.
     *
     * RULE 15, AND RULE 25 UNDER IT. The assertion above expects an empty list,
     * which is also what a scanner that matches nothing returns, so something
     * has to prove the instrument is alive.
     *
     * WHAT THIS SAID: that the floor was `>= 12` hits over the real tree, "well
     * under the measured population so ordinary work can move it", plus a row
     * requiring at least one `catch(\RuntimeException)` among them.
     *
     * WHAT IS TRUE NOW: round 58 repaired the population. Nineteen sites moved
     * their `fail()` out of the try, and the real-tree count fell from 23 to 4
     * — every survivor a DELIBERATE catch of an assertion-failure type, so not
     * one of them is spelled `\RuntimeException`. MEASURED: with the floor
     * dropped to zero, the `catch(\RuntimeException)` row below still failed.
     * Both anchors were cardinalities of the tree, and the tree was the thing
     * being fixed — a liveness check that dies when the defect it watches is
     * cured is a budget wearing a liveness check's doc-block.
     *
     * WHY THIS STILL EARNS ITS PLACE: the reasoning was right and only its
     * ANCHOR was wrong. The type-expressiveness claims are now driven through
     * {@see self::swallowingCatchesIn()} on a fixture, which is the same
     * scanner one level down and cannot be moved by anybody's sweep, and the
     * real tree is asked only what a fixture genuinely cannot answer: that the
     * whole-tree walk still reads files off disk and resolves their paths.
     *
     * @see \SugarCraft\Crush\Tests\SwallowingCatchCensusTest for the guard
     *      that now refuses the shape tree-wide, and for why the four survivors
     *      are correct code rather than exemptions.
     */
    public function testTheWiderSwallowingPopulationIsStillVisible(): void
    {
        $all = $this->swallowingCatches();

        // THE TYPE EXPRESSIVENESS, ON A FIXTURE. If the alphabet narrows back to
        // `\Throwable` — the shape the original write-up had — the majority of
        // the class becomes invisible, and that is true whatever the tree
        // happens to contain this week.
        $probe = $this->swallowingCatchesIn(
            "<?php\nclass P {\n"
            . "  function a() { try { \$this->assertSame(1, 1); } catch (\\RuntimeException) { } }\n"
            . "  function b() { try { \$this->assertSame(1, 1); } catch (\\Throwable) { } }\n"
            . "  function c() { try { \$this->assertSame(1, 1); } catch (\\Exception) { } }\n"
            . "  function d() { try { \$this->assertSame(1, 1); } catch (\\PHPUnit\\Framework\\AssertionFailedError) { } }\n"
            . "}\n",
        );
        $this->assertCount(
            4,
            $probe,
            'the scanner no longer reports one row per catch clause it is looking straight at, so '
            . 'every emptiness claim in this file is worthless',
        );
        foreach (['RuntimeException', 'Throwable', 'Exception', 'PHPUnit\\Framework\\AssertionFailedError'] as $type) {
            $this->assertNotSame(
                [],
                array_values(array_filter(
                    $probe,
                    static fn (array $r): bool => \in_array($type, $r['types'], true),
                )),
                'catch(' . $type . ') is not being reported, so the alphabet has narrowed and part '
                . 'of the population is unseen',
            );
        }

        // AND THE REAL TREE, ASKED ONLY WHAT THE FIXTURE CANNOT ANSWER: that the
        // walk still reaches files on disk. NOT a floor — if a later round
        // legitimately takes this to zero, delete this row and say so; do not
        // weaken it to `>= 0`, which is what a dead walk also satisfies.
        $this->assertNotSame(
            [],
            $all,
            'the whole-tree walk reports nothing at all. A fixture cannot catch this: it drives '
            . 'the scan over a string, so a walk that has stopped reading files, stopped '
            . 'resolving paths or stopped being pointed at tests/ looks identical to a clean tree',
        );
        foreach ($all as $row) {
            // THE `.php` CHECK IS NOT DECORATION. `assertFileExists()` is
            // satisfied by a DIRECTORY, so a row with no `file` key at all
            // would resolve to the package root and pass — the row would then
            // be asserting that sugar-crush/ exists.
            $file = $row['file'] ?? '';
            $this->assertNotSame('', $file, 'a reported row carries no file at all');
            $this->assertStringEndsWith('.php', $file, 'a reported row does not name a PHP file');
            $this->assertFileExists(
                \dirname(__DIR__, 2) . '/' . $file,
                'a reported row names a path that is not on disk, so the walk is reporting rows it '
                . 'did not read',
            );
        }

        // AND THE SPELLING-INDEPENDENCE, ON A FIXTURE.
        //
        // WHAT THIS WAS: a filter over the REAL TREE for rows whose type string
        // starts `PHPUnit\`, asserting at least one, under a comment reading
        // "five of eight PHPUnit-typed catches were invisible to it".
        //
        // WHAT IS TRUE NOW, and both halves were wrong:
        //
        //   - "five of eight" is not derivable at any tree. Re-derived with
        //     this file's own generator, the population is 4 rows today and was
        //     23 before this round's sweep; the `PHPUnit\`-spelled share is 3
        //     of 4 now and was 3 of 23 then. The figure was inherited prose.
        //   - The filter looked in the wrong place. `types` records the type as
        //     the author WROTE it, not as this scanner RESOLVED it, so a row
        //     only matched `PHPUnit\` when the author had already spelled it
        //     fully qualified — which needs no import map and no namespace at
        //     all. The one row in the tree that genuinely exercises resolution
        //     is a BARE imported name, and the filter excluded it. The
        //     assertion's message claimed it proved resolution; it could not
        //     see resolution.
        //
        // WHY THIS STILL EARNS ITS PLACE: the claim was right and only its
        // instrument was wrong. Resolution IS the thing worth pinning, and a
        // fixture can state it exactly — a bare imported name and an ALIASED
        // import are classes only if `resolveCaughtType()` consults the import
        // map, so deleting that arm makes the first two rows vanish. It is also
        // the shape a real-tree count cannot survive: this round's own sweep
        // took the population from 23 to 4 and would have taken the old margin
        // with it.
        $resolved = $this->swallowingCatchesIn(
            "<?php\n"
            . "namespace Demo\\Deep;\n"
            . 'use PHPUnit\Framework\AssertionFailedError;' . "\n"
            . 'use PHPUnit\Framework\ExpectationFailedException as Boom;' . "\n"
            . "class R {\n"
            . "  function a() { try { \$this->assertSame(1, 1); } catch (AssertionFailedError) { } }\n"
            . "  function b() { try { \$this->assertSame(1, 1); } catch (Boom) { } }\n"
            . "  function c() { try { \$this->assertSame(1, 1); } catch (\\PHPUnit\\Framework\\AssertionFailedError) { } }\n"
            . "  function d() { try { \$this->assertSame(1, 1); } catch (\\TypeError) { } }\n"
            . "}\n",
        );
        $this->assertSame(
            [
                ['line' => 6, 'types' => ['AssertionFailedError'], 'catchAsserts' => false, 'rethrows' => false, 'recordsForLater' => false],
                ['line' => 7, 'types' => ['Boom'], 'catchAsserts' => false, 'rethrows' => false, 'recordsForLater' => false],
                ['line' => 8, 'types' => ['PHPUnit\\Framework\\AssertionFailedError'], 'catchAsserts' => false, 'rethrows' => false, 'recordsForLater' => false],
            ],
            $resolved,
            'the caught type is no longer being RESOLVED before it is judged. A bare imported '
            . 'name and an aliased import are not classes on their own, so if either row is '
            . 'missing the decision has gone back to comparing spellings; if the \TypeError row '
            . 'has appeared, resolution has gone the other way and stopped discriminating',
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
                public function fullyQualifiedPhpUnitType(): void {
                    try { $this->assertSame(1, 1); } catch (\PHPUnit\Framework\AssertionFailedError) { }
                }
                public function recordsForLater(): void {
                    $failed = false;
                    try { $this->assertSame(1, 1); } catch (\PHPUnit\Framework\AssertionFailedError) { $failed = true; }
                    $this->assertTrue($failed, 'x');
                }
                public function recordsAndNeverReads(): void {
                    $failed = false;
                    try { $this->assertSame(1, 1); } catch (\RuntimeException) { $failed = true; }
                    $this->assertTrue(true, 'x');
                }
                public function nestedInsideATryBody(): void {
                    try {
                        try { $this->assertSame(1, 1); } catch (\RuntimeException) { }
                    } catch (\TypeError) { }
                }
            }
            PHP;

        $rows = $this->swallowingCatchesIn($fixture);

        $this->assertSame(
            [
                ['line' => 4, 'types' => ['RuntimeException'], 'catchAsserts' => false, 'rethrows' => false, 'recordsForLater' => false],
                ['line' => 7, 'types' => ['Throwable'], 'catchAsserts' => true, 'rethrows' => false, 'recordsForLater' => false],
                ['line' => 10, 'types' => ['Exception'], 'catchAsserts' => false, 'rethrows' => true, 'recordsForLater' => false],
                ['line' => 19, 'types' => ['PHPUnit\\Framework\\AssertionFailedError'], 'catchAsserts' => false, 'rethrows' => false, 'recordsForLater' => false],
                ['line' => 23, 'types' => ['PHPUnit\\Framework\\AssertionFailedError'], 'catchAsserts' => false, 'rethrows' => false, 'recordsForLater' => true],
                ['line' => 28, 'types' => ['RuntimeException'], 'catchAsserts' => false, 'rethrows' => false, 'recordsForLater' => false],
                ['line' => 33, 'types' => ['RuntimeException'], 'catchAsserts' => false, 'rethrows' => false, 'recordsForLater' => false],
            ],
            $rows,
            'the scanner does not agree with a source whose every catch was written to be '
            . 'classified one way; with this red, its verdict over tests/ is worthless',
        );

        // AND THE DECISION THE GUARD ACTUALLY MAKES, over the same rows. The
        // fixture's first catch is the silent shape and its other two are not;
        // without this, a filter that answered "nothing is silent" for every
        // input passed every assertion in this file (MEASURED — that mutation
        // SURVIVED before this was added).
        $this->assertSame(
            [
                '(fixture):4 catch(RuntimeException)',
                '(fixture):19 catch(PHPUnit\\Framework\\AssertionFailedError)',
                '(fixture):28 catch(RuntimeException)',
                '(fixture):33 catch(RuntimeException)',
            ],
            $this->silentIn($rows),
            'the silent-shape filter does not pick out the rows written to be silent, so the '
            . "guard's empty-list assertion over tests/ is satisfied by an instrument that "
            . 'answers empty for everything',
        );
    }

    /**
     * Every `catch` type in `tests/` resolves to a symbol that exists.
     *
     * RULE 14, AND IT IS THE HALF THAT MAKES THE VERDICT ABOVE MEAN ANYTHING.
     * The scan decides by asking a resolved class whether it is a supertype of
     * a failed assertion; a type it cannot resolve gets no opinion at all, and
     * a guard that quietly has no opinion looks exactly like one with a clean
     * verdict. This makes the unresolvable list visible instead.
     */
    public function testEveryCaughtTypeInTestsResolvesToARealSymbol(): void
    {
        $this->swallowingCatches();

        $this->assertSame(
            [],
            array_values(array_unique($this->unresolvable)),
            'a catch names a type this scan cannot resolve, so it has no opinion about that '
            . 'catch and says so rather than reporting the file clean. Teach the resolver the '
            . 'shape (a grouped `use`, an alias) rather than dropping the occurrence',
        );

        // AND THE RESOLVER ANSWERS A QUESTION WHOSE ANSWER IS KNOWN, in both
        // polarities, because an empty list is also what a resolver that
        // answers every name returns (E228).
        $imports = ['AFE' => 'PHPUnit\\Framework\\AssertionFailedError'];
        $this->assertSame(
            'PHPUnit\\Framework\\AssertionFailedError',
            $this->resolveCaughtType('AFE', $imports, 'SugarCraft\\Crush\\Tests\\Support'),
            'an aliased import does not resolve, so every catch written through one is silently '
            . 'unclassified',
        );
        $this->assertSame(
            'RuntimeException',
            $this->resolveCaughtType('RuntimeException', [], 'SugarCraft\\Crush\\Tests\\Support'),
            'a global type does not resolve from inside a namespaced file',
        );
        $this->assertNull(
            $this->resolveCaughtType('NoSuchTypeWasEverDeclared', [], 'SugarCraft\\Crush\\Tests'),
            'the resolver invents a symbol for a name that does not exist, so the empty list '
            . 'above proves nothing',
        );

        // AND THE FILING ITSELF, which an empty tree cannot exercise (rule 25,
        // and rule 41 beside it). Deleting the line that files an unresolvable
        // type is INVISIBLE in a tree where every type resolves — MEASURED,
        // that mutation SURVIVED the whole file before this was added, which is
        // the definition of an assertion whose expected value is what a dead
        // instrument returns.
        $this->assertFalse(
            $this->swallowsAnAssertionFailure(['NoSuchTypeWasEverDeclared'], [], 'SugarCraft\\Crush\\Tests'),
            'a type that resolves to nothing was nonetheless judged able to swallow an '
            . 'assertion failure',
        );
        $this->assertSame(
            ['NoSuchTypeWasEverDeclared'],
            array_values(array_unique($this->unresolvable)),
            'an unresolvable type was not filed, so the empty verdict above is exactly what a '
            . 'scan that files nothing at all also returns',
        );
    }
}
