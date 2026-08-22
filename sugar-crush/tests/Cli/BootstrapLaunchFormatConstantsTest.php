<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;

/**
 * The launch-report formats that other files name are named, and the methods
 * that emit them do not keep a second copy.
 *
 * WHY THIS FILE EXISTS, AND IT IS NOT "CONSTANTS ARE TIDIER". E104 recorded
 * that every `sprintf()` in {@see Bootstrap} reaching stderr or the transcript
 * was pinned only by a test RE-TYPING it, and measured the consequence:
 * changing the launcher's format from `disabled %d of the %d` to
 * `removed %d of the %d` left the two guards that exist for that line at
 * `OK (14 tests, 84 assertions)`. E118 promoted the formats with an external
 * reader to `public const` and pointed those guards at the names.
 *
 * THAT SWAP IS ONLY AN IMPROVEMENT UNDER ONE CONDITION, and this file is the
 * condition. A `public const` that the emitting code does not `sprintf()` FROM
 * is not a shared definition — it is the same duplication with a nicer name,
 * and it is strictly WORSE than the re-typed literal was, because the reader of
 * {@see \SugarCraft\Crush\Tests\Config\ReadmeRosterDriftTest} now believes the
 * README is being compared against the launcher when it is being compared
 * against a decoration. Concretely, the hole this closes: leave
 * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT} alone, re-inline a
 * DIFFERENT literal in `reportProjectTierToolRemovals()`, and every guard on
 * that line stays green while the launcher prints something else. Measured —
 * see this round's mutation table.
 *
 * WHAT IS *NOT* PINNED HERE, stated because the absence looks like an
 * oversight. `Bootstrap.php` holds TWELVE `sprintf()` call sites, TEN of them
 * with a literal format (derived by
 * {@see testTheLiteralFormatCensusHasAGenerator()}, not counted by hand). Only
 * two are promoted, and the rule that decided it is EXTERNAL READERSHIP: a
 * format quoted by `README.md`, `docs/SETTINGS.md` or a drift guard has a
 * second party that must agree with it, and a name is how two parties agree. A
 * format read by exactly one method is not improved by moving it away from the
 * only code that cares. Promoting all ten is recorded as a deferred finding
 * rather than done, because "every literal is a constant" buys nothing and
 * costs a reader one indirection per line.
 *
 * @see \SugarCraft\Crush\Tests\Config\ReadmeRosterDriftTest for the README end
 *      of the same claim — that file compares the constants to the page.
 * @see \SugarCraft\Crush\Tests\Cli\BootstrapToolAndPermissionSettingsTest for
 *      the behavioural end: a real child launch whose stderr carries the line.
 */
final class BootstrapLaunchFormatConstantsTest extends TestCase
{
    /**
     * Each promoted FORMAT, the method obliged to `sprintf()` from it, and how
     * many conversions its callers pass.
     *
     * The conversion count is here because it is the one property of a format
     * that a caller can violate without any string looking wrong: `sprintf()`
     * in PHP 8 throws `ArgumentCountError` when the format asks for more than it
     * is given (measured on PHP 8.3.6: `3 arguments are required, 2 given`), so
     * an extra `%s` added to one of these constants is a fatal at the first
     * warning of every launch that raises one — not a cosmetic drift.
     *
     * @var array<string, array{method: string, conversions: int}>
     */
    private const NAMED_FORMATS = [
        'STDERR_LINE_FORMAT' => ['method' => 'warnPermissionConfig', 'conversions' => 1],
        'PROJECT_TIER_TOOL_REMOVAL_FORMAT' => [
            'method' => 'reportProjectTierToolRemovals',
            'conversions' => 5,
        ],
    ];

    /**
     * Each promoted plain LITERAL and the method obliged to use it.
     *
     * @var array<string, string>
     */
    private const NAMED_LITERALS = [
        'PROJECT_TIER_TOOL_REMOVAL_LEAVING' => 'reportProjectTierToolRemovals',
        'PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE' => 'reportProjectTierToolRemovals',
    ];

    public function testEveryNamedFormatIsReferencedByTheMethodThatEmitsIt(): void
    {
        self::assertNotSame([], self::obligations(), 'the obligation roster is empty, so this is vacuous');

        foreach (self::obligations() as $constant => $method) {
            self::assertContains(
                $constant,
                self::identifiersIn(self::methodBody($method)),
                "Bootstrap::{$method}() no longer names Bootstrap::{$constant}. A promoted constant the "
                    . 'emitting code does not use is a second copy with a nicer name, and every guard that '
                    . 'reads the constant is then measuring a decoration rather than the launcher.',
            );
        }
    }

    /**
     * No method that owns a named format may hold a format literal of its own.
     *
     * A PRINTF CONVERSION IS THE MARKER, not equality with the constant's
     * value: the failure being closed is a re-inlined literal that DIFFERS from
     * the constant, so a check for the constant's own text would pass on exactly
     * the case that matters. Ordinary literals in these bodies — `'disabledTools'`,
     * the `', '` an `implode()` joins on — carry no conversion and are left alone.
     */
    public function testNoMethodThatOwnsANamedFormatAlsoHoldsALiteralOne(): void
    {
        foreach (self::obligations() as $constant => $method) {
            $offenders = self::formatLiteralsIn(self::methodBody($method));

            self::assertSame(
                [],
                $offenders,
                "Bootstrap::{$method}() holds a literal containing a printf conversion: "
                    . implode(' | ', $offenders) . ". It is obliged to format from Bootstrap::{$constant}; "
                    . 'a literal alongside it is the drift this file exists to catch.',
            );
        }
    }

    public function testEachNamedFormatAsksForExactlyTheConversionsItsCallersPass(): void
    {
        foreach (self::NAMED_FORMATS as $constant => $spec) {
            $value = (string) \constant(Bootstrap::class . '::' . $constant);

            self::assertSame(
                $spec['conversions'],
                self::conversionsIn($value),
                "Bootstrap::{$constant} no longer asks for {$spec['conversions']} conversions. sprintf() "
                    . 'throws ArgumentCountError when a format asks for more than it is given, so this is a '
                    . 'launch-time fatal and not a cosmetic drift.',
            );
        }
    }

    /**
     * KNOWN-POSITIVE FIXTURES FOR BOTH SCANNERS, in the same test that uses
     * them to assert an absence.
     *
     * `testNoMethodThatOwnsANamedFormatAlsoHoldsALiteralOne()` asserts `[]`, and
     * an assertion of `[]` is not evidence unless something proves the scanner
     * still works: round 44 emptied a census in this tree, mutated the scanner
     * to never match, and watched the "nothing is stale" assertion pass with
     * 18,228 assertions green. So both scanners are run here against sources
     * whose answer is known, including the exact shape the guard exists to
     * reject.
     */
    public function testTheScannersAnswerCorrectlyOnSourcesWhoseAnswerIsKnown(): void
    {
        $reInlined = "<?php class B {\n"
            . "    private static function f(): void {\n"
            . "        self::emit(sprintf('%s (disabledTools) removed %d of %d — %s — %s', \$a));\n"
            . "    }\n}\n";
        self::assertSame(
            ["'%s (disabledTools) removed %d of %d — %s — %s'"],
            self::formatLiteralsIn(self::methodBody('f', $reInlined)),
            'the format-literal scanner no longer sees a re-inlined format; every [] above is vacuous',
        );

        $fromConstant = "<?php class B {\n"
            . "    private static function f(): void {\n"
            . "        self::emit(sprintf(self::THE_FORMAT, \$a), 'disabledTools', ', ');\n"
            . "    }\n}\n";
        self::assertSame(
            [],
            self::formatLiteralsIn(self::methodBody('f', $fromConstant)),
            'the scanner reports a literal with no conversion in it; it would red on ordinary strings',
        );
        self::assertContains(
            'THE_FORMAT',
            self::identifiersIn(self::methodBody('f', $fromConstant)),
            'the identifier scanner cannot see a constant reference; the obligation check is vacuous',
        );
        self::assertNotContains(
            'THE_FORMAT',
            self::identifiersIn(self::methodBody('f', $reInlined)),
            'the identifier scanner reports a constant that is not there',
        );

        // Nested braces must not end the body early — a scanner that stopped at
        // the first `}` would see none of a method whose first statement is a
        // closure or an `if`, and would answer [] for every one of them.
        $nested = "<?php class B {\n"
            . "    private static function f(): void {\n"
            . "        if (true) { \$x = 1; }\n"
            . "        self::emit(sprintf('late %s', \$a));\n"
            . "    }\n}\n";
        self::assertSame(
            ["'late %s'"],
            self::formatLiteralsIn(self::methodBody('f', $nested)),
            'methodBody() stops at a nested closing brace, so it reads only the head of every method',
        );

        self::assertSame(2, self::conversionsIn('%s and %d'));
        self::assertSame(0, self::conversionsIn('100%% done'), '%% is an escaped percent, not a conversion');
        self::assertSame(1, self::conversionsIn("sugarcrush: %s.\n"));
    }

    /**
     * A method this file names but cannot find is a FAILURE, not a skip.
     *
     * A guard that quietly ignores what it cannot parse has a hole shaped
     * exactly like the next defect: rename `warnPermissionConfig()` and every
     * assertion above would otherwise pass on an empty body.
     */
    public function testTheScannerRedsOnAMethodItCannotFind(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('noSuchMethodOnBootstrap');

        self::methodBody('noSuchMethodOnBootstrap');
    }

    /**
     * The figure this class's doc-block quotes, derived rather than counted.
     *
     * It is asserted as a RANGE-FREE exact pair on purpose: a new `sprintf()`
     * with a literal format in `Bootstrap.php` reds here, and the right response
     * is to ask whether the new format has an external reader — if it does, it
     * wants a name; if it does not, bump the figure. That question being asked
     * once per new format IS the value; the number itself is worth nothing.
     */
    public function testTheLiteralFormatCensusHasAGenerator(): void
    {
        [$calls, $literal] = self::sprintfCensus(self::bootstrapSource());

        self::assertSame(12, $calls, "Bootstrap.php's sprintf() call-site count moved; see this test's doc-block");
        self::assertSame(10, $literal, 'a sprintf() in Bootstrap.php gained or lost a literal format');

        // Known-answer control for the census itself.
        self::assertSame(
            [2, 1],
            self::sprintfCensus("<?php sprintf('a %s', 1); sprintf(self::F, 2); \$x = 'sprintf(';"),
            'the sprintf census miscounts a source whose answer is known',
        );
    }

    // ── the scanners ─────────────────────────────────────────────────────

    /** @return array<string, string> constant name => the method obliged to use it */
    private static function obligations(): array
    {
        $out = self::NAMED_LITERALS;
        foreach (self::NAMED_FORMATS as $constant => $spec) {
            $out[$constant] = $spec['method'];
        }

        return $out;
    }

    private static function bootstrapSource(): string
    {
        return (string) file_get_contents((string) (new \ReflectionClass(Bootstrap::class))->getFileName());
    }

    /**
     * The significant tokens of one method's body, brace-matched.
     *
     * BRACE-MATCHED AND NOT `strpos("\n    }\n")`: indentation is a convention a
     * reformat may change, and a body that happens to contain a nested `}` at
     * class-body indentation inside a heredoc would truncate silently. The
     * fixture in {@see testTheScannersAnswerCorrectlyOnSourcesWhoseAnswerIsKnown()}
     * covers the nesting case.
     *
     * @return list<array{0: int, 1: string}|string>
     */
    private static function methodBody(string $method, ?string $source = null): array
    {
        $significant = [];
        foreach (token_get_all($source ?? self::bootstrapSource()) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $at = null;
        foreach ($significant as $i => $token) {
            $previous = $significant[$i - 1] ?? null;
            if (
                \is_array($token) && $token[0] === T_STRING && $token[1] === $method
                && \is_array($previous) && $previous[0] === T_FUNCTION
            ) {
                $at = $i;

                break;
            }
        }

        if ($at === null) {
            throw new \RuntimeException("Bootstrap has no method {$method}(); this guard cannot answer for it");
        }

        $depth = 0;
        $body = [];
        for ($i = $at; $i < \count($significant); $i++) {
            $token = $significant[$i];
            if ($token === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }
            if ($depth >= 1) {
                $body[] = $token;
            }
        }

        throw new \RuntimeException("Bootstrap::{$method}() has no locatable end");
    }

    /**
     * Every string literal in `$body` that carries a printf conversion.
     *
     * @param list<array{0: int, 1: string}|string> $body
     *
     * @return list<string>
     */
    private static function formatLiteralsIn(array $body): array
    {
        $out = [];
        foreach ($body as $token) {
            if (!\is_array($token)) {
                continue;
            }
            if (!\in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (self::conversionsIn($token[1]) > 0) {
                $out[] = $token[1];
            }
        }

        return $out;
    }

    /**
     * Every bare identifier in `$body` — enough to see a `self::CONST`
     * reference without caring which scope operator reached it.
     *
     * @param list<array{0: int, 1: string}|string> $body
     *
     * @return list<string>
     */
    private static function identifiersIn(array $body): array
    {
        $out = [];
        foreach ($body as $token) {
            if (\is_array($token) && $token[0] === T_STRING) {
                $out[] = $token[1];
            }
        }

        return $out;
    }

    /** How many conversions `sprintf()` would consume from `$format`; `%%` is not one. */
    private static function conversionsIn(string $format): int
    {
        return preg_match_all(
            "/%(?:%|[-+ 0#']*[0-9]*(?:\\.[0-9]+)?[bcdeEfFgGosuxX])/",
            $format,
            $m,
        ) - \count(array_filter($m[0], static fn(string $c): bool => $c === '%%'));
    }

    /**
     * `[sprintf call sites, of which with a literal first argument]`.
     *
     * @return array{0: int, 1: int}
     */
    private static function sprintfCensus(string $source): array
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $calls = 0;
        $literal = 0;
        foreach ($significant as $i => $token) {
            if (!\is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'sprintf') {
                continue;
            }
            if (($significant[$i + 1] ?? null) !== '(') {
                continue;
            }

            $calls++;
            $first = $significant[$i + 2] ?? null;
            if (\is_array($first) && $first[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal++;
            }
        }

        return [$calls, $literal];
    }
}
