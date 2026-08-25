<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\DropsInsignificantTokensTrait;

/**
 * **The narrowed `catch` arms around `awaitPromise()` are pinned, because
 * without this file they were three comments and a green suite.**
 *
 * ## What was wrong, measured rather than argued
 *
 * Three tests in this directory wrap `$this->awaitPromise(...)` in a `try` and
 * catch the rejection they are asserting about. `awaitPromise()` ends in
 * `$this->fail('Promise did not settle within the test timeout')`, which raises
 * an `AssertionFailedError` — so a `catch` on any SUPERTYPE of that class
 * captures the harness's own timeout diagnostic and re-reports it through
 * whatever the test asserts next. Each of the three grew a narrowed
 * `catch (\PHPUnit\Framework\AssertionFailedError $e) { throw $e; }` arm ahead
 * of the wide one so the timeout arrives as itself.
 *
 * WHAT THE COMMIT THAT ADDED THEM SAID: that the change was MEASURED. It was —
 * the diagnostic really does differ. WHAT WAS NOT TRUE: that anything pinned
 * it. MEASURED, by deleting all three arms (47 lines) and running the WHOLE
 * suite rather than a filter: rc 0, green, and the assertion total moved by
 * nine — a census somewhere counts these sites and asserts nothing about them.
 * Rule 1: a green suite is not a pinned invariant, and a mutation that reds a
 * test in BOTH states is a demonstration that the diagnostic differs, not a
 * kill of the guard that is supposed to keep the arm there.
 *
 * ## The fourth site, which nobody had named
 *
 * Writing the scanner found a site the finding did not mention:
 * {@see StreamingCommandBackendTest::testACancelledCompletionReapsItsChild()}
 * wrapped `awaitPromise()` in `catch (\RuntimeException)` with a body of
 * `// expected`. `AssertionFailedError` extends `PHPUnit\Framework\Exception`
 * extends `\RuntimeException` (asserted in
 * {@see testTheHierarchyThisGuardRestsOnIsWhatItSays()}, not taken on trust),
 * so that arm swallowed the timeout outright and the test then went on to
 * assert a zombie count that has nothing to do with whether the promise ever
 * settled. That one was the real silent-pass shape, and it is why this guard
 * walks the whole of `tests/` rather than the three files it was commissioned
 * for: an alphabet written from the cases already known reports zero for the
 * case it was not written for (rule 11).
 *
 * ## What it asserts
 *
 * For every `try` whose body calls `awaitPromise()` and which has at least one
 * `catch` clause that would swallow an assertion failure: the FIRST such clause
 * must rethrow. Order is load-bearing — a rethrowing narrow arm placed after a
 * `catch (\Throwable)` never runs.
 *
 * A `try` with only a `finally` is deliberately not this guard's subject: an
 * assertion failure propagates out of it untouched, which is the behaviour the
 * guard exists to preserve. That is the large majority of the `try` statements
 * containing `awaitPromise()`. No count is written here: a cardinality over
 * `tests/` is invalidated by any lane's next merge (rule 18), and the first
 * draft of this paragraph said "~20" where the measured figure was 17. The
 * number this guard needs is derived instead - it asserts that the walk found
 * a swallowing site AT ALL before reporting that none of them offends.
 *
 * WHY A SECOND RESOLVER RATHER THAN {@see
 * \SugarCraft\Crush\Tests\Support\AssertionSwallowingCatchTest::resolveCaughtType()}.
 * That file asks a different question — "does this `catch` swallow an assertion
 * failure WITHOUT asserting anything in its body", over the whole suite — and
 * it is another lane's file this round, so a `use` of it here would put an edit
 * of theirs and a guard of mine in one inheritance chain during a concurrent
 * merge. The overlap is real and is recorded as a consolidation candidate
 * rather than pretended away; if the two are still separate next round, this
 * scanner's ordering question belongs inside that one.
 */
final class AwaitPromiseDiagnosticArmTest extends TestCase
{
    use DropsInsignificantTokensTrait;

    /** The helper whose timeout diagnostic this file exists to protect. */
    private const HELPER = 'awaitPromise';

    /**
     * The hierarchy the whole guard turns on, asserted rather than written down
     * (rule 46). If `AssertionFailedError` ever stops being a `RuntimeException`
     * the fourth site below stops being a defect and this file needs rereading.
     */
    public function testTheHierarchyThisGuardRestsOnIsWhatItSays(): void
    {
        foreach ([\Throwable::class, \Exception::class, \RuntimeException::class, \PHPUnit\Framework\Exception::class] as $supertype) {
            $this->assertTrue(
                is_a(AssertionFailedError::class, $supertype, true),
                "a catch on {$supertype} no longer swallows a failed assertion on PHP " . PHP_VERSION
                    . ', so this guard is measuring a hierarchy that has moved',
            );
        }

        // The other polarity, so "everything swallows" cannot be satisfied by a
        // resolver that answers true for any name at all (rule 33).
        $this->assertFalse(
            is_a(AssertionFailedError::class, \PHPUnit\Framework\ExpectationFailedException::class, true),
            'ExpectationFailedException is now a supertype of AssertionFailedError, so a catch on '
                . 'it would swallow fail() and the narrow arms in this directory are catching the '
                . 'wrong class',
        );
    }

    /**
     * THE ASSERTION. Every swallowing `try` around `awaitPromise()` in `tests/`
     * keeps its rethrowing arm, and keeps it FIRST.
     */
    public function testEverySwallowingCatchAroundAwaitPromiseRethrowsTheHarnessTimeoutFirst(): void
    {
        $offences = [];
        $seen = 0;
        foreach (self::everyTestFile() as $relative => $path) {
            foreach (self::swallowingAwaitSites((string) file_get_contents($path)) as $site) {
                $seen++;
                if ($site['ok']) {
                    continue;
                }
                $offences[] = $relative . ':' . $site['line'] . ' — ' . $site['why'];
            }
        }

        // Rule 15/25: an empty offence list is also what a dead scanner
        // returns. This says the walk still FOUND the population it is
        // reporting nothing about.
        $this->assertGreaterThan(
            0,
            $seen,
            'the scanner found no try/catch around ' . self::HELPER . '() anywhere in tests/. '
                . 'Either the helper has been renamed — in which case change self::HELPER — or '
                . 'this walk is dead and its empty offence list means nothing.',
        );

        $this->assertSame(
            [],
            $offences,
            "a catch around awaitPromise() swallows the harness's own timeout:\n  "
                . implode("\n  ", $offences) . "\n\n"
                . self::HELPER . "() ends in fail('Promise did not settle within the test\n"
                . "timeout'), which raises an AssertionFailedError - and that class extends\n"
                . "RuntimeException, so catching \\RuntimeException, \\Exception or \\Throwable\n"
                . "captures it. The test then re-reports the timeout through whatever it\n"
                . "asserts next, or (worse) treats it as the rejection it was expecting.\n\n"
                . "THE FIX, and it is the same one three sites in tests/Backend/ already carry:\n"
                . "put\n"
                . "    catch (\\PHPUnit\\Framework\\AssertionFailedError \$e) { throw \$e; }\n"
                . "AHEAD of the wide arm, so the timeout arrives as itself. Order matters - an\n"
                . "arm placed after the wide one never runs. Do NOT widen this guard's\n"
                . 'alphabet to exempt the site.',
        );
    }

    /**
     * Rule 15's known-positive AND rule 25's: `assertSame([], $offences)` above
     * is satisfied by a scanner that answers "nothing to see" for every input,
     * so the same scanner is pushed six sources whose answers are known — four
     * that it MUST report and two that it MUST NOT.
     *
     * @dataProvider scannerFixtures
     *
     * @param list<string> $expectedWhy substrings, one per expected offence
     */
    public function testTheScannerAgreesWithSourcesWhoseAnswerIsKnown(string $source, int $expectedSites, array $expectedWhy): void
    {
        $sites = self::swallowingAwaitSites($source);
        $this->assertCount($expectedSites, $sites, 'the scanner saw a different number of swallowing sites than the fixture has');

        $bad = array_values(array_filter($sites, static fn (array $s): bool => !$s['ok']));
        $this->assertCount(\count($expectedWhy), $bad, 'the scanner disagreed about which sites are offending');

        foreach ($expectedWhy as $i => $needle) {
            $this->assertStringContainsString($needle, $bad[$i]['why']);
        }
    }

    /**
     * @return array<string, array{0:string, 1:int, 2:list<string>}>
     */
    public static function scannerFixtures(): array
    {
        $wrap = static fn (string $body): string => "<?php\nfinal class F { public function t(): void { {$body} } }\n";
        $await = '$this->' . self::HELPER . '($p);';

        return [
            'narrow arm first, then the wide one' => [
                $wrap("try { {$await} } catch (\\PHPUnit\\Framework\\AssertionFailedError \$e) { throw \$e; } catch (\\Throwable \$e) { \$x = \$e; }"),
                1,
                [],
            ],
            'no narrow arm at all' => [
                $wrap("try { {$await} } catch (\\Throwable \$e) { \$x = \$e; }"),
                1,
                ['does not rethrow'],
            ],
            'the exact fourth-site shape: a bare RuntimeException arm' => [
                $wrap("try { {$await} } catch (\\RuntimeException) { }"),
                1,
                ['does not rethrow'],
            ],
            'a narrow arm that records instead of rethrowing' => [
                $wrap("try { {$await} } catch (\\PHPUnit\\Framework\\AssertionFailedError \$e) { \$x = \$e; } catch (\\Throwable \$e) { \$x = \$e; }"),
                1,
                ['does not rethrow'],
            ],
            'a narrow arm placed AFTER the wide one, which never runs' => [
                $wrap("try { {$await} } catch (\\Throwable \$e) { \$x = \$e; } catch (\\PHPUnit\\Framework\\AssertionFailedError \$e) { throw \$e; }"),
                1,
                ['does not rethrow'],
            ],
            'finally only, which is not this guard\'s subject' => [
                $wrap("try { {$await} } finally { \$x = 1; }"),
                0,
                [],
            ],
            'a swallowing catch around something that is not the helper' => [
                $wrap('try { $this->somethingElse($p); } catch (\\Throwable $e) { $x = $e; }'),
                0,
                [],
            ],
            'a try body containing an INTERPOLATED string, whose closing brace is bare' => [
                $wrap("try { \$label = \"a{\$p}b\"; {$await} } catch (\\RuntimeException) { }"),
                1,
                ['does not rethrow'],
            ],
            'an unresolvable caught type is reported, never dropped (rule 14)' => [
                $wrap("try { {$await} } catch (\\No\\Such\\TypeZZ \$e) { \$x = \$e; }"),
                1,
                ['cannot be resolved'],
            ],
        ];
    }

    /**
     * Every `try` in `$source` whose body calls {@see HELPER} and which carries
     * at least one `catch` clause able to swallow an assertion failure.
     *
     * `ok` is true when the FIRST such clause rethrows. A `try` this scanner
     * cannot parse — a body that never closes, a `catch` without parentheses,
     * a caught name that resolves to no real symbol — is returned as an
     * offending row rather than skipped (rule 14): a guard that quietly ignores
     * what it cannot read has a hole shaped exactly like the next defect.
     *
     * @return list<array{line:int, ok:bool, why:string}>
     */
    private static function swallowingAwaitSites(string $source): array
    {
        $tokens = self::significantTokens($source);
        $count = \count($tokens);
        $imports = self::importsIn($tokens);
        $namespace = self::namespaceIn($tokens);
        $sites = [];

        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_TRY) {
                continue;
            }
            $line = $tokens[$i][2];
            $open = $i + 1;
            if (self::text($tokens[$open] ?? '') !== '{') {
                $sites[] = ['line' => $line, 'ok' => false, 'why' => 'this try has no body this scanner can find'];

                continue;
            }
            $close = self::matching($tokens, $open, '{', '}');
            if ($close === null) {
                $sites[] = ['line' => $line, 'ok' => false, 'why' => 'this try body never closes'];

                continue;
            }

            if (!self::callsHelperBetween($tokens, $open, $close)) {
                continue;
            }

            $swallowing = [];
            $unparsed = null;
            $k = $close + 1;
            while ($k < $count && \is_array($tokens[$k]) && $tokens[$k][0] === \T_CATCH) {
                $paren = $k + 1;
                if (self::text($tokens[$paren] ?? '') !== '(') {
                    $unparsed = 'a catch clause with no parameter list this scanner can find';

                    break;
                }
                $parenEnd = self::matching($tokens, $paren, '(', ')');
                $bodyOpen = $parenEnd === null ? null : $parenEnd + 1;
                if ($bodyOpen === null || self::text($tokens[$bodyOpen] ?? '') !== '{') {
                    $unparsed = 'a catch clause whose body this scanner cannot find';

                    break;
                }
                $bodyEnd = self::matching($tokens, $bodyOpen, '{', '}');
                if ($bodyEnd === null) {
                    $unparsed = 'a catch body that never closes';

                    break;
                }

                foreach (self::caughtNames($tokens, $paren + 1, (int) $parenEnd) as $written) {
                    $resolved = self::resolve($written, $imports, $namespace);
                    if ($resolved === null) {
                        $unparsed = "the caught type {$written} cannot be resolved to a real symbol";

                        break 2;
                    }
                    if (!is_a(AssertionFailedError::class, $resolved, true)) {
                        continue;
                    }
                    $swallowing[] = [
                        'written' => $written,
                        'line' => $tokens[$k][2],
                        'rethrows' => self::rethrowsBetween($tokens, $bodyOpen, $bodyEnd),
                    ];
                }

                $k = $bodyEnd + 1;
            }

            if ($unparsed !== null) {
                $sites[] = ['line' => $line, 'ok' => false, 'why' => $unparsed];

                continue;
            }
            if ($swallowing === []) {
                continue;
            }

            $first = $swallowing[0];
            $sites[] = [
                'line' => $first['line'],
                'ok' => $first['rethrows'],
                'why' => "the first catch able to swallow a failed assertion here is {$first['written']}, "
                    . 'and it does not rethrow',
            ];
        }

        return $sites;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function callsHelperBetween(array $tokens, int $from, int $to): bool
    {
        for ($j = $from + 1; $j < $to; $j++) {
            if (\is_array($tokens[$j])
                && $tokens[$j][0] === \T_STRING
                && $tokens[$j][1] === self::HELPER
                && self::text($tokens[$j + 1] ?? '') === '('
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * `throw $x;` anywhere in the catch body. Deliberately not "rethrows THIS
     * variable": a body that throws something else has still not swallowed the
     * timeout, and a catch with no variable at all cannot name one.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function rethrowsBetween(array $tokens, int $from, int $to): bool
    {
        for ($j = $from + 1; $j < $to; $j++) {
            if (\is_array($tokens[$j]) && $tokens[$j][0] === \T_THROW) {
                return true;
            }
        }

        return false;
    }

    /**
     * The type names a `catch (A|B $e)` list writes, as written.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<string>
     */
    private static function caughtNames(array $tokens, int $from, int $to): array
    {
        $names = [];
        $current = '';
        for ($j = $from; $j < $to; $j++) {
            $token = $tokens[$j];
            if (\is_array($token) && $token[0] === \T_VARIABLE) {
                continue;
            }
            if (self::text($token) === '|') {
                if ($current !== '') {
                    $names[] = $current;
                }
                $current = '';

                continue;
            }
            $current .= self::text($token);
        }
        if ($current !== '') {
            $names[] = $current;
        }

        return $names;
    }

    /**
     * `$written` as a fully-qualified name, or null when it resolves to nothing
     * this interpreter knows.
     *
     * @param array<string, string> $imports
     */
    private static function resolve(string $written, array $imports, string $namespace): ?string
    {
        $candidates = [];
        if (str_starts_with($written, '\\')) {
            $candidates[] = substr($written, 1);
        } else {
            $head = explode('\\', $written)[0];
            if (isset($imports[strtolower($head)])) {
                $candidates[] = $imports[strtolower($head)] . substr($written, \strlen($head));
            }
            if ($namespace !== '') {
                $candidates[] = $namespace . '\\' . $written;
            }
            $candidates[] = $written;
        }

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The file's `use` imports, keyed by lowercased alias.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array<string, string>
     */
    private static function importsIn(array $tokens): array
    {
        $imports = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_USE) {
                continue;
            }
            // A closure's `use (...)` is not an import; a group or trait use is
            // not one this guard needs. Both are skipped by requiring the next
            // token to be a name.
            $fqn = '';
            $alias = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $text = self::text($tokens[$j]);
                if ($text === ';' || $text === '{' || $text === '(') {
                    break;
                }
                if (\is_array($tokens[$j]) && $tokens[$j][0] === \T_AS) {
                    $alias = '';

                    continue;
                }
                if ($alias === null) {
                    $fqn .= $text;
                } else {
                    $alias .= $text;
                }
            }
            if ($fqn === '') {
                continue;
            }
            $parts = explode('\\', $fqn);
            $imports[strtolower($alias ?? end($parts))] = ltrim($fqn, '\\');
        }

        return $imports;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function namespaceIn(array $tokens): string
    {
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_NAMESPACE) {
                continue;
            }
            $name = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $text = self::text($tokens[$j]);
                if ($text === ';' || $text === '{') {
                    break;
                }
                $name .= $text;
            }

            return trim($name, '\\');
        }

        return '';
    }

    /**
     * @param array{0:int,1:string,2:int}|string $token
     */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function matching(array $tokens, int $from, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $from, $n = \count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            // BOTH INTERPOLATION OPENERS COUNT WHEN THE PAIR IS BRACES.
            // `"{$x}"` opens with T_CURLY_OPEN - an ARRAY token - and closes
            // with a bare `}`, so a brace walk that reads only one-byte strings
            // decrements on a level it never incremented and closes the
            // enclosing block early. Here that would truncate a `try` body at
            // the first interpolated string in it, hiding both the
            // awaitPromise() call and every catch clause after it: a guard that
            // reports "nothing to see" for exactly the files that interpolate.
            // {@see \SugarCraft\Crush\Tests\Support\InterpolationOpenerTokenTest}
            // names this defect and did NOT flag this method - its detector
            // reads this file as "not a brace walker" - so this is its
            // prescription applied where its census could not reach.
            if ($open === '{'
                && \is_array($token)
                && \in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)
            ) {
                $depth++;

                continue;
            }
            if (!\is_string($token)) {
                continue;
            }
            if ($token === $open) {
                $depth++;
            } elseif ($token === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Every `.php` file under `tests/`, keyed by its path relative to `tests/`.
     *
     * NOT `Support/TestFileWalkTrait`, for the reason
     * {@see ScaledClockHelperSeamTest::everyTestFile()} gives: that trait is
     * another lane's file this round.
     *
     * @return array<string, string>
     */
    private static function everyTestFile(): array
    {
        $root = \dirname(__DIR__);
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $found[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
        }
        ksort($found);

        return $found;
    }
}
