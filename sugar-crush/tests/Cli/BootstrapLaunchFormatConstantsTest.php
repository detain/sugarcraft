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
 * was pinned only by a test RE-TYPING it.
 *
 * THE FIGURE THAT USED TO SIT HERE WAS QUOTED WITHOUT ITS TREE, and it is the
 * mistake this whole file family exists to stop. WHAT IT SAID: "measured the
 * consequence: changing the launcher's format from `disabled %d of the %d` to
 * `removed %d of the %d` left the two guards that exist for that line at
 * `OK (14 tests, 84 assertions)`", with no commit attached. WHAT IS TRUE NOW,
 * re-measured at `06126017` — the tree E118 actually replaced, PHP 8.3.6:
 * that same mutation gives `Tests: 14, Assertions: 92, Failures: 1`. It was
 * NOT blind. By then E104's finding had already been half-answered by round
 * 43, which replaced the re-typed literal with a regex scrape of
 * `Bootstrap.php`'s source text, and the scrape reads the literal — so it reds
 * on a literal change. The 84 describes the tree BEFORE that scrape existed,
 * which is a tree neither number in this doc-block was measured against.
 * WHY THE FIGURE STILL EARNS ITS PLACE: it is the only measurement of the
 * failure E104 named, and deleting it would leave the item reading as a
 * tidiness complaint. It is a historical figure for the retyped-literal shape,
 * not a description of what E118 improved on.
 *
 * WHAT E118 ACTUALLY IMPROVED ON is the scrape, and its weakness is a
 * different one: it was coupled to the syntactic SHAPE of the code rather than
 * to the string, so a pure refactor broke it. Measured at `06126017`, turning
 * `warnPermissionConfig()`'s interpolation into
 * `sprintf(self::STDERR_LINE_FORMAT, $message)` — not one byte of output
 * changed — gives `Tests: 14, Assertions: 89, Failures: 1`. E118 promoted the
 * formats with an external reader to `public const` and pointed those guards
 * at the names.
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
 * oversight. `Bootstrap.php` holds more `sprintf()` call sites with a literal
 * format than it has promoted constants — HOW MANY MORE IS DELIBERATELY NOT
 * WRITTEN HERE, and that is this round's rule rather than laziness: a
 * cardinality in prose is invalidated by the next commit that adds a
 * `sprintf()`, and round 44 shipped one into `src/` that was correct in its
 * lane and wrong at master an hour later. The pair is asserted, with its
 * generator, by {@see testTheLiteralFormatCensusHasAGenerator()}; read it
 * there, where a new `sprintf()` moves the number and the assertion at the same
 * time.
 *
 * WHICH FORMATS ARE PROMOTED is decided by EXTERNAL READERSHIP: a format quoted
 * by `README.md`, `docs/SETTINGS.md` or a drift guard has a second party that
 * must agree with it, and a name is how two parties agree. A format read by
 * exactly one method is not improved by moving it away from the only code that
 * cares. Promoting the rest is recorded as a deferred finding rather than done,
 * because "every literal is a constant" buys nothing and costs a reader one
 * indirection per line.
 *
 * ONE PROMOTED CONSTANT DOES NOT SATISFY THAT RULE, and saying so is cheaper
 * than pretending. WHAT THE RULE IMPLIES: every name here has a second party.
 * WHAT IS TRUE NOW, measured — `grep -rn "leaving no tools at all" src/ tests/
 * docs/ README.md` returns exactly one hit, its own declaration.
 * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE} has no external
 * reader at all: {@see \SugarCraft\Crush\Tests\Config\ReadmeRosterDriftTest}
 * reads the FORMAT and the `leaving: ` prefix, never this one, because the
 * README's sample is the branch where tools survive.
 * WHY IT STILL EARNS ITS PLACE: it is the other branch of ONE field —
 * `PROJECT_TIER_TOOL_REMOVAL_FORMAT`'s last `%s` is either
 * `PROJECT_TIER_TOOL_REMOVAL_LEAVING` plus a list or this — and splitting a
 * two-branch field across a constant and a literal is worse than either
 * choice made consistently. What makes it a real obligation rather than a
 * decoration is {@see METHOD_LITERALS}, not a second party; until the
 * no-survivors branch has a behavioural assertion of its own (recorded as a
 * deferred finding), that is the whole of what pins it.
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

    /**
     * Every string literal each obliged method is allowed to hold, exactly.
     *
     * WHY AN EXACT SET AND NOT "NO FORMAT LITERALS", which is what this file
     * shipped first and what round 45's review broke. WHAT THE OLD GUARD SAID:
     * "A PRINTF CONVERSION IS THE MARKER" — a re-inlined literal is caught
     * because a format carries a `%s`. WHAT IS TRUE NOW, measured: the review
     * re-inlined `'WRECKED: no tools survive'` in place of
     * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING_NONE} while leaving the
     * constant's NAME mentioned in the body, ran the six classes that could
     * plausibly cover it, and got `OK (109 tests, 339 assertions)`, rc 0. The
     * conversion marker cannot see a promoted literal that carries no
     * conversion, and {@see identifiersIn()} pins only that the name is
     * MENTIONED, never that it is USED. That is exactly the state this file's
     * own doc-block calls "the same duplication with a nicer name … strictly
     * WORSE than the re-typed literal".
     *
     * WHY THE REVIEW'S OWN PRESCRIPTION WAS NOT IMPLEMENTED. It asked for an
     * assertion that the constant's VALUE does not also appear as a literal in
     * the body. Measured against the mutation it was prescribed for: the
     * re-inlined literal was `'WRECKED: no tools survive'` and the constant's
     * value is `'leaving no tools at all'`, so a value-equality check passes on
     * precisely the case that motivated it. A prescription in a review is a
     * hypothesis; this one does not kill its own mutation.
     *
     * SO THE OBLIGATION IS AN ALLOWLIST, which is the conversion-free
     * generalisation that does work: a method that owns a named constant may
     * hold these literals and no others, so ANY new string in it — a format, a
     * message, a re-inlined constant that differs from the constant — is a
     * failure until someone classifies it. THE FRICTION IS THE FEATURE, the
     * same bargain {@see \SugarCraft\Crush\Tests\Cli\StderrEmitterCensusTest}'s
     * per-file rosters make: bumping the list is a fine response, not noticing
     * is not.
     *
     * @var array<string, list<string>>
     */
    private const METHOD_LITERALS = [
        'warnPermissionConfig' => [],
        'reportProjectTierToolRemovals' => ["'disabledTools'", "', '"],
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

    /**
     * Each obliged method holds exactly the literals it is allowed to hold.
     *
     * THE KNOWN-POSITIVE IS IN THIS TEST AND NOT IN THE SHARED PROVIDER,
     * because one of the two expectations is `[]`:
     * `warnPermissionConfig()`'s body is a single `fwrite()` and holds no string
     * at all, so a blinded {@see literalsIn()} would pass that row on its own.
     * An assertion of `[]` is not evidence unless something in the same test
     * proves the scanner still works.
     */
    public function testEachObligedMethodHoldsExactlyTheLiteralsItIsAllowed(): void
    {
        self::assertSame(
            ["'kept'", "'%s and more'"],
            self::literalsIn(self::methodBody('f', "<?php class B {\n"
                . "    private static function f(): void {\n"
                . "        self::emit('kept', 'kept', sprintf('%s and more', \$a));\n"
                . "    }\n}\n")),
            'literalsIn() no longer reports a body\'s literals in order and without duplicates; the '
                . 'expectations below are not measuring anything',
        );

        foreach (self::METHOD_LITERALS as $method => $allowed) {
            self::assertSame(
                $allowed,
                self::literalsIn(self::methodBody($method)),
                "Bootstrap::{$method}() holds a different set of string literals than this file allows. A "
                    . 'method that owns a named constant may hold plumbing strings and nothing else: a new '
                    . 'literal here is either a message the launcher prints — in which case it wants a name, '
                    . 'and a second party that reads the name — or it is plumbing, in which case add it to '
                    . 'self::METHOD_LITERALS. Re-inlining a promoted constant\'s text under a different '
                    . 'wording is the failure this list exists to catch, and it carries no printf conversion '
                    . 'for the marker in the test above to see.',
            );
        }
    }

    /**
     * Every method that owns a named constant has a literal allowlist.
     *
     * Without this, promoting a constant into {@see NAMED_FORMATS} or
     * {@see NAMED_LITERALS} while forgetting the allowlist row would leave the
     * new obligation covered by the conversion marker alone — which is the hole
     * the allowlist exists to close, silently reopened by the next promotion.
     */
    public function testEveryObligedMethodHasALiteralAllowlist(): void
    {
        foreach (self::obligations() as $constant => $method) {
            self::assertArrayHasKey(
                $method,
                self::METHOD_LITERALS,
                "Bootstrap::{$method}() owns Bootstrap::{$constant} but has no row in "
                    . 'self::METHOD_LITERALS, so nothing stops a re-inlined literal in it.',
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

        // The two forms the first version of this pattern could not express.
        self::assertSame(2, self::conversionsIn('%1$s and %2$s'), 'a positional format reads as no conversions');
        self::assertSame(1, self::conversionsIn("%'*10s"), 'a custom pad character reads as no conversions');
        self::assertSame(1, self::conversionsIn('%05.2f'));
        self::assertSame(1, self::conversionsIn('%-10s'));

        // Unparseable is loud, not zero.
        self::assertSame([], self::unparsedPercentsIn('%s and %1$d and 100%%'));
        self::assertSame(['%z'], self::unparsedPercentsIn('%z'), 'an unknown conversion reads as no percent');
        self::assertSame(
            ["'a %z b'"],
            self::formatLiteralsIn(self::methodBody('f', "<?php class B {\n"
                . "    private static function f(): void {\n"
                . "        self::emit('a %z b');\n"
                . "    }\n}\n")),
            'a literal carrying a % sequence this file cannot parse is passed over rather than reported',
        );
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
            if (self::conversionsIn($token[1]) > 0 || self::unparsedPercentsIn($token[1]) !== []) {
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

    /**
     * Every `%` sequence in `$format` that `sprintf()` would recognise, as
     * matched text.
     *
     * THE ALPHABET IS PART OF THE COVERAGE, and this pattern's first version
     * could not express two of the forms PHP accepts. MEASURED on PHP 8.3.6:
     * `%1$s and %2$s` answered 0 conversions and `%'*10s` answered 0, so a
     * re-inlined POSITIONAL format was not an offender at all —
     * {@see formatLiteralsIn()} gates on the count being above zero. Round 45's
     * review re-inlined exactly that and the guard whose doc-block says a
     * printf conversion is the marker stayed silent; the mutation died on an
     * unrelated census count instead.
     *
     * The order of the optional groups is the order `sprintf()` parses them:
     * argnum, then flags (`'` takes the next character as the pad), then width,
     * then precision, then the specifier.
     *
     * @return list<string>
     */
    private static function conversionSpecsIn(string $format): array
    {
        preg_match_all(
            "/%(?:%|(?:[0-9]+\\\$)?(?:[-+ 0#]|'.)*[0-9]*(?:\\.[0-9]+)?[bcdeEfFgGosuxX])/",
            $format,
            $m,
        );

        /** @var list<string> $out */
        $out = $m[0];

        return $out;
    }

    /** How many conversions `sprintf()` would consume from `$format`; `%%` is not one. */
    private static function conversionsIn(string $format): int
    {
        $specs = self::conversionSpecsIn($format);

        return \count($specs) - \count(array_filter($specs, static fn(string $c): bool => $c === '%%'));
    }

    /**
     * The `%` sequences in `$format` that {@see conversionSpecsIn()} could not
     * account for.
     *
     * A GUARD MUST GO RED ON WHAT IT CANNOT PARSE, NOT SILENTLY SKIP IT. Every
     * `%` in a string reaching `sprintf()` as a format is either a conversion
     * or an escaped `%%`; anything else is a `ValueError` at runtime on PHP 8,
     * and answering "zero conversions" for it is a hole shaped exactly like the
     * next format this pattern has not met. {@see formatLiteralsIn()} reports a
     * literal carrying one as an offender rather than passing it over.
     *
     * @return list<string>
     */
    private static function unparsedPercentsIn(string $format): array
    {
        $remainder = str_replace(self::conversionSpecsIn($format), '', $format);

        preg_match_all('/%.{0,3}/s', $remainder, $m);

        /** @var list<string> $out */
        $out = $m[0];

        return $out;
    }

    /**
     * Every string literal in `$body`, in source order, without duplicates.
     *
     * The token text and not the decoded value: `'disabledTools'` and
     * `"disabledTools"` are different literals to a reader deciding whether one
     * of them is a re-inlined message, and collapsing them would hide the
     * quoting change that usually comes with a re-inline.
     *
     * @param list<array{0: int, 1: string}|string> $body
     *
     * @return list<string>
     */
    private static function literalsIn(array $body): array
    {
        $out = [];
        foreach ($body as $token) {
            if (!\is_array($token)) {
                continue;
            }
            if (!\in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (!\in_array($token[1], $out, true)) {
                $out[] = $token[1];
            }
        }

        return $out;
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
