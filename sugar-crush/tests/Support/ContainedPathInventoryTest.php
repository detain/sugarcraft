<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ContainedPath;

/**
 * {@see ContainedPath}'s class doc-block inventory, DERIVED instead of trusted.
 *
 * The inventory is a security argument — "one implementation, and here is every
 * place that does not use it" — and it was hand-maintained across three rounds,
 * drifting each time. Every figure it quotes is measured here, per file, so the
 * prose cannot outlive the tree.
 *
 * WHAT CHANGED IN THIS REVISION, and why the previous bound statement was not
 * good enough. It read: "this counts the compares that are WRITTEN … it cannot
 * catch a read path that never had a compare." True, and much too flattering.
 * Two classes of defect were measured passing straight through the old
 * regex-based instrument, and NEITHER of them is a read path without a compare:
 *
 *  (a) A NEUTERED GATE KEEPS ITS COUNT. Replacing `InstructionFileLoader::loadRoot()`'s
 *      `if (!ContainedPath::within($path, $this->repoRoot)) { continue; }` with
 *      the bare statement `ContainedPath::within($path, $this->repoRoot);` —
 *      call present, RESULT DISCARDED, escape fully restored — left this file at
 *      `OK (5 tests, 14 assertions)`. The escape was caught, but by
 *      {@see \SugarCraft\Crush\Tests\Context\InstructionFileLoaderContainmentTest}
 *      (2 failures), not by the instrument whose whole job is to see gates.
 *
 *  (b) FIVE REAL HAND-SPELLED CONTAINMENT COMPARES WENT UNCOUNTED. Each was
 *      added to `src/Support/HomeDirectory.php` in turn and the inventory stayed
 *      `OK (5 tests, 14 assertions)` every time: arguments swapped
 *      (`str_starts_with($b . '/', $p)`), interpolated (`"$b/"`),
 *      `DIRECTORY_SEPARATOR`, `strncmp(…) === 0`, and a variable class name
 *      (`$c = ContainedPath::class; $c::within(…)`).
 *
 * So the instrument is no longer a line-regex. Both halves are derived from
 * `token_get_all()`:
 *
 *  - the ROUTED half counts a call site only when its RESULT IS USED, and a
 *    discarded result is a hard failure with its file and line named
 *    ({@see testNoRoutedContainmentCallHasItsResultDiscarded()});
 *  - the HAND-SPELLED half parses each `str_starts_with`/`strncmp`/
 *    `substr_compare` call's ARGUMENT LIST and asks whether any argument is
 *    boundary-suffixed, in any of the five spellings above.
 *
 * THE BOUND THAT REMAINS, stated so it is true of both (a) and (b) and of the
 * cases below rather than being a comfortable summary. This instrument sees a
 * containment compare when it is written as a call to one of four named
 * functions with a literal separator suffix in an argument, or as a
 * `::within()`/`::below()` whose result is consumed. It does NOT see:
 * a compare whose separator was concatenated onto a variable earlier
 * (`$prefix = $b . '/';` then `str_starts_with($p, $prefix)`); a compare built
 * out of `preg_match()`, `substr()` or `strpos()`; a compare living in a
 * dependency; or a read path with no compare at all — which is what
 * `InstructionFileLoader::loadRoot()` and `loadForPath()` were while this file's
 * ancestor listed the file as audited. The first two are asserted as misses in
 * {@see testTheInstrumentsOwnBlindSpotsAreMeasuredNotAssumed()}, so the bound is
 * a measurement rather than a claim.
 */
final class ContainedPathInventoryTest extends TestCase
{
    /** The functions a path-against-boundary prefix compare can be written with. */
    private const COMPARE_FUNCTIONS = ['str_starts_with', 'strncmp', 'substr_compare'];

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = \dirname(__DIR__, 2) . '/src';
    }

    /**
     * "NINETEEN call sites in SEVEN files", per file. Each count is one read
     * decision, so a dropped gate shows up as the file's number falling — which
     * is the half of #89 an instrument like this genuinely covers.
     *
     * The two foreign readers are new to this list and were not omissions of
     * wording: {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} and
     * {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter} each held
     * repository-chosen read paths with NO compare at all, in classes whose
     * doc-blocks honestly said they were unwired.
     */
    public function testTheRoutedCallSiteInventory(): void
    {
        $this->assertSame(
            [
                'Agents/AgentPresetRegistry.php' => 3,
                'Agents/ForeignAgentPresetRegistry.php' => 2,
                'Commands/CommandLoader.php' => 2,
                'Context/InstructionFileLoader.php' => 5,
                'Memory/ForeignMemoryImporter.php' => 2,
                'Skills/SkillLoader.php' => 3,
                'Workflows/WorkflowRegistry.php' => 2,
            ],
            $this->countPerFile($this->routedCalls(...), skip: 'Support/ContainedPath.php'),
        );
    }

    /**
     * FINDING (a), the one the old instrument could not see. A call whose result
     * is thrown away is not a gate, and there is no legitimate reason to write
     * one: both methods are pure predicates. This fails LOUDLY with file and
     * line rather than merely declining to count, because a discarded
     * containment result is always a defect.
     */
    public function testNoRoutedContainmentCallHasItsResultDiscarded(): void
    {
        $discarded = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            foreach ($this->routedCalls($path) as $call) {
                if (!$call['used']) {
                    $discarded[] = "src/{$relative}:{$call['line']}";
                }
            }
        }

        $this->assertSame(
            [],
            $discarded,
            'ContainedPath::within()/below() called for effect, which they have none of: '
            . implode(', ', $discarded),
        );
    }

    /**
     * "EIGHT spellings remain by hand, in FOUR files" — plus the two the
     * inventory deliberately EXCLUDES, named here so the exclusion is a recorded
     * decision rather than a hole. `WorktreeManager`'s pair matches relative paths
     * against a glob directory; it is not a boundary compare.
     */
    public function testTheHandSpelledInventoryIncludingItsStatedExclusion(): void
    {
        $counts = $this->countPerFile($this->handSpelledCompares(...), skip: 'Support/ContainedPath.php');

        $this->assertSame(
            [
                'Agents/WorktreeManager.php' => 2,
                'Hooks/BuiltIn/BashEscapeDenyHook.php' => 1,
                'Tools/BuiltIn/Glob.php' => 1,
                'Tools/IgnoreRules.php' => 1,
                'Tools/PathJail.php' => 5,
            ],
            $counts,
        );

        unset($counts['Agents/WorktreeManager.php']);
        $this->assertSame(8, array_sum($counts), 'containment spellings still by hand');
        $this->assertCount(4, $counts, 'files still holding one');
    }

    /**
     * `ContainedPath` itself holds exactly ONE prefix compare. That is the whole
     * point of the class, and it is the number that must never rise.
     */
    public function testTheClassItselfHoldsExactlyOneCompare(): void
    {
        $this->assertCount(
            1,
            $this->handSpelledCompares($this->srcDir . '/Support/ContainedPath.php'),
        );
    }

    /**
     * FINDING (b), driven: the five spellings that slipped past the line regex,
     * each parsed rather than pattern-matched. Without this, a widening that
     * quietly failed to widen would read as zero-drift.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function compareSpellings(): array
    {
        return [
            'the canonical form' => ["str_starts_with(\$real, \$rootReal . '/');", 1],
            'against $this' => ["str_starts_with(\$p, \$this->root . '/');", 1],
            'arguments swapped' => ["str_starts_with(\$b . '/', \$p);", 1],
            'interpolated separator' => ['str_starts_with($p, "$b/");', 1],
            'braced interpolation' => ['str_starts_with($p, "{$b}/");', 1],
            'DIRECTORY_SEPARATOR' => ['str_starts_with($p, $b . DIRECTORY_SEPARATOR);', 1],
            'strncmp' => ["strncmp(\$p, \$b . '/', strlen(\$b) + 1) === 0;", 1],
            'substr_compare' => ["substr_compare(\$p, \$b . '/', 0, strlen(\$b) + 1) === 0;", 1],
            'nested call in the first argument' => [
                "str_starts_with(\$realPath . '/', rtrim(\$realBoundary, '/') . '/');",
                1,
            ],
            // The controls. An absolute-path test is not a containment test, and
            // there are many of those.
            'an absolute-path test' => ["str_starts_with(\$path, '/');", 0],
            'an option-flag test' => ["str_starts_with(\$token, '-');", 0],
            // The false positive a line regex produced on
            // src/Hooks/BuiltIn/BashEscapeDenyHook.php:107 — a separator concat
            // that belongs to a DIFFERENT expression on the same line.
            'a separator concat outside the call' => [
                "\$base = str_starts_with(\$token, '/') ? \$token : \$root . '/' . \$token;",
                0,
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('compareSpellings')]
    public function testTheInstrumentRecognisesEverySpellingItClaimsTo(string $code, int $expected): void
    {
        $this->assertCount($expected, $this->handSpelledComparesIn("<?php\n" . $code . "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function routedShapes(): array
    {
        return [
            'negated in a condition' => ['if (!ContainedPath::within($a, $b)) { return null; }', true],
            'returned' => ['return ContainedPath::below($dir, $anchor);', true],
            'assigned' => ['$ok = ContainedPath::within($a, $b);', true],
            'in a boolean chain' => ['$ok = $x !== null && ContainedPath::below($a, $b);', true],
            'fully qualified' => ['if (\\SugarCraft\\Crush\\Support\\ContainedPath::within($a, $b)) { }', true],
            'through a variable class name' => ['if ($c::within($a, $b)) { }', true],
            // The neutered gate — finding (a).
            'result discarded' => ['ContainedPath::within($a, $b);', false],
            'result discarded through a variable' => ['$c::below($a, $b);', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routedShapes')]
    public function testTheInstrumentTellsAGateFromADiscardedCall(string $code, bool $used): void
    {
        $calls = $this->routedCallsIn("<?php\n" . $code . "\n");

        $this->assertCount(1, $calls);
        $this->assertSame($used, $calls[0]['used']);
    }

    /**
     * THE BOUND, MEASURED. Two shapes this instrument is known not to see,
     * asserted as misses so the doc-block's limitation is a fact rather than a
     * hedge. Both are legitimate containment compares; neither is counted.
     *
     * If a future revision teaches the scanner one of these, this test fails and
     * the bound statement above has to be rewritten — which is the point.
     */
    public function testTheInstrumentsOwnBlindSpotsAreMeasuredNotAssumed(): void
    {
        $viaVariable = "<?php\n\$prefix = \$b . '/';\nif (!str_starts_with(\$p, \$prefix)) { return; }\n";
        $this->assertCount(0, $this->handSpelledComparesIn($viaVariable), 'separator bound to a variable first');

        $viaRegex = "<?php\nif (!preg_match('#^' . preg_quote(\$b, '#') . '/#', \$p)) { return; }\n";
        $this->assertCount(0, $this->handSpelledComparesIn($viaRegex), 'a compare built out of preg_match()');
    }

    /**
     * The measured divergence behind the corrected `BashEscapeDenyHook` entry:
     * this class refuses a path it cannot resolve, and a file about to be CREATED
     * does not resolve. `false` therefore fails CLOSED at a `!within(...)` deny
     * site — the opposite of what the old entry claimed — and the real cost of
     * consolidating that hook is over-denial.
     */
    public function testAnUnresolvablePathIsRefusedWhichIsWhyTheDenyHookStaysHandSpelled(): void
    {
        $root = sys_get_temp_dir() . '/contained_path_inventory_' . uniqid();
        mkdir($root . '/sub', 0o777, true);

        try {
            $real = (string) realpath($root);

            // The case the old entry cited as inverting into an allow.
            $this->assertFalse(ContainedPath::within('/nonexistent', $real));

            // The case that actually diverges: in-root, lexically fine, not there yet.
            $this->assertFalse(ContainedPath::within($real . '/newfile.txt', $real));

            // The control: the same path once it exists.
            file_put_contents($real . '/newfile.txt', '');
            $this->assertTrue(ContainedPath::within($real . '/newfile.txt', $real));
        } finally {
            @unlink($root . '/newfile.txt');
            @rmdir($root . '/sub');
            @rmdir($root);
        }
    }

    // ─── the instrument ─────────────────────────────────────────────

    /** @return array<string, string> path relative to `src/` => absolute path, sorted by key */
    private function sourceFiles(): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), \strlen($this->srcDir) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @param  callable(string): list<mixed> $scan
     * @return array<string, int> path relative to `src/` => matches, sorted by key
     */
    private function countPerFile(callable $scan, string $skip): array
    {
        $counts = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            if ($relative === $skip) {
                continue;
            }

            $n = \count($scan($path));
            if ($n > 0) {
                $counts[$relative] = $n;
            }
        }

        return $counts;
    }

    /** @return list<array{line: int, used: bool}> */
    private function routedCalls(string $path): array
    {
        return $this->routedCallsIn((string) file_get_contents($path));
    }

    /**
     * Every `ContainedPath::within()`/`::below()` call in $code, with whether its
     * RESULT IS USED.
     *
     * "Used" is decided by the token immediately preceding the class expression:
     * a call that starts a statement (previous significant token is `;`, `{`,
     * `}`, `:` or the open tag) has nowhere to put its answer. Everything else —
     * `!`, `return`, `=`, `&&`, `(` — consumes it.
     *
     * A VARIABLE class expression counts, because `$c = ContainedPath::class;
     * $c::within(…)` is a real call this class's own inventory used to miss.
     * There is no other `::within(`/`::below(` in the package for it to
     * over-count, which {@see testTheRoutedCallSiteInventory()} pins.
     *
     * @return list<array{line: int, used: bool}>
     */
    private function routedCallsIn(string $code): array
    {
        $tokens = $this->significantTokens($code);
        $calls = [];

        foreach ($tokens as $i => $token) {
            if (!$this->isToken($token, \T_DOUBLE_COLON)) {
                continue;
            }

            $subject = $tokens[$i - 1] ?? null;
            $method = $tokens[$i + 1] ?? null;

            if (!$this->isToken($method, \T_STRING)
                || !\in_array(strtolower((string) $method[1]), ['within', 'below'], true)
            ) {
                continue;
            }

            $isContainedPath = $this->isToken($subject, \T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED)
                && str_ends_with((string) $subject[1], 'ContainedPath');

            if (!$isContainedPath && !$this->isToken($subject, \T_VARIABLE)) {
                continue;
            }

            $before = $tokens[$i - 2] ?? null;
            $startsAStatement = $before === null
                || \in_array($before, [';', '{', '}'], true)
                || $this->isToken($before, \T_OPEN_TAG);

            $calls[] = [
                'line' => \is_array($subject) ? (int) $subject[2] : 0,
                'used' => !$startsAStatement,
            ];
        }

        return $calls;
    }

    /** @return list<array{line: int, function: string}> */
    private function handSpelledCompares(string $path): array
    {
        return $this->handSpelledComparesIn((string) file_get_contents($path));
    }

    /**
     * Every call to one of {@see COMPARE_FUNCTIONS} in $code that passes a
     * BOUNDARY-SUFFIXED argument — the ` . '/'` (or its four other spellings)
     * that makes a prefix test a containment test rather than an absolute-path
     * test.
     *
     * Parsed rather than pattern-matched, because a line regex cannot tell an
     * argument from the rest of the line: `$base = str_starts_with($token, '/')
     * ? $token : $root . '/' . $token;` (src/Hooks/BuiltIn/BashEscapeDenyHook.php:107)
     * was counted as a containment compare by the previous instrument, and it is
     * not one.
     *
     * @return list<array{line: int, function: string}>
     */
    private function handSpelledComparesIn(string $code): array
    {
        $tokens = $this->significantTokens($code);
        $found = [];

        foreach ($tokens as $i => $token) {
            if (!$this->isToken($token, \T_STRING)
                || !\in_array(strtolower((string) $token[1]), self::COMPARE_FUNCTIONS, true)
            ) {
                continue;
            }

            // A method or a declaration of the same name is not a call to it.
            $before = $tokens[$i - 1] ?? null;
            if ($this->isToken($before, \T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION)) {
                continue;
            }

            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            foreach ($this->arguments($tokens, $i + 1) as $argument) {
                if ($this->isBoundarySuffixed($argument)) {
                    $found[] = ['line' => (int) $token[2], 'function' => (string) $token[1]];

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The top-level arguments of the call whose `(` sits at $open.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<list<array{0: int, 1: string, 2: int}|string>>
     */
    private function arguments(array $tokens, int $open): array
    {
        $depth = 0;
        $arguments = [];
        $current = [];

        for ($i = $open, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];

            // `"{$b}/"` opens its brace as T_CURLY_OPEN — an ARRAY token — and
            // closes it with a bare `}`. Counting only the string form left the
            // close unbalanced, which ended the argument list early and made the
            // braced-interpolation spelling invisible.
            if ($this->isToken($token, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES)) {
                ++$depth;
            } elseif (\in_array($token, ['(', '[', '{'], true)) {
                ++$depth;
                if ($depth === 1) {
                    continue;
                }
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                --$depth;
                if ($depth === 0) {
                    if ($current !== []) {
                        $arguments[] = $current;
                    }

                    return $arguments;
                }
            } elseif ($token === ',' && $depth === 1) {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        return $arguments;
    }

    /**
     * Does this argument end in a path separator glued onto something else?
     *
     * The five spellings measured slipping past the line regex, plus the two the
     * regex already caught:
     *
     *     $b . '/'        $b . "/"        $b . DIRECTORY_SEPARATOR
     *     "$b/"           "{$b}/"
     *
     * A bare `'/'` is NOT one of them — `str_starts_with($path, '/')` is an
     * absolute-path test, and there are many of those in `src/`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argument
     */
    private function isBoundarySuffixed(array $argument): bool
    {
        $last = $argument[\count($argument) - 1] ?? null;
        $previous = $argument[\count($argument) - 2] ?? null;

        // `… . '/'` / `… . "/"` / `… . DIRECTORY_SEPARATOR`
        if ($previous === '.') {
            if ($this->isToken($last, \T_CONSTANT_ENCAPSED_STRING)
                && \in_array((string) $last[1], ["'/'", '"/"'], true)
            ) {
                return true;
            }

            if ($this->isToken($last, \T_STRING) && (string) $last[1] === 'DIRECTORY_SEPARATOR') {
                return true;
            }
        }

        // `"$b/"` and `"{$b}/"` — a double-quoted string whose final literal
        // part ends in a separator. The closing `"` is the last token.
        if ($last === '"' && $this->isToken($previous, \T_ENCAPSED_AND_WHITESPACE)
            && str_ends_with((string) $previous[1], '/')
        ) {
            return true;
        }

        return false;
    }

    /**
     * $code's tokens with whitespace and comments dropped.
     *
     * Comments are dropped rather than skipped per line because a doc-comment
     * `{@see ContainedPath::within()}` is a cross-reference, not a call site,
     * and counting those is what inflated an earlier hand count.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private function significantTokens(string $code): array
    {
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (\is_array($token)
                && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)
            ) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /** @param array{0: int, 1: string, 2: int}|string|null $token */
    private function isToken(mixed $token, int ...$kinds): bool
    {
        return \is_array($token) && \in_array($token[0], $kinds, true);
    }
}
