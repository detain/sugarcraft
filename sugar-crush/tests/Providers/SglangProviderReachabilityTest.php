<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * `SglangProvider` IS REACHABLE, AND A CONSTRUCTION SCAN SAYS IT IS NOT.
 *
 * WHY THIS FILE EXISTS. Round 48 routed six refusals onto the
 * {@see RuntimeNoticeSink} seam: four in
 * {@see \SugarCraft\Crush\Agents\WorktreeManager} and two in
 * {@see SglangProvider::decodeToolArguments()}. It then established
 * `WorktreeManager`'s dormancy at length — see
 * `StderrEmitterCensusTest::testTheWorktreeManagerSeamSitesAreDormantBecauseNothingConstructsIt()`
 * — and applied no symmetric check to the other class. Two of the six moves
 * therefore rested on an unstated assumption. This file states it, in the
 * direction the measurement actually points: `SglangProvider` is LIVE.
 *
 * THE TRAP, WHICH IS THE VALUABLE HALF. The obvious instrument for the
 * question is the scanner that settled `WorktreeManager` —
 * `constructionSites()`, which recognises `new Foo` and the project's
 * canonical `Foo::new()` factory. Pointed at `SglangProvider` over `src/` plus
 * `bin/sugarcrush` it answers ZERO (MEASURED, PHP 8.3.6), which reads exactly
 * like the zero that proved `WorktreeManager` dormant. It is not the same
 * zero. Nothing spells `new SglangProvider` anywhere, because the class is
 * built through a DIFFERENTLY-NAMED static factory,
 * {@see SglangProvider::openAiCompatible()} (whose body is `return new
 * self(...)`), selected out of a name-keyed `match` on a config string:
 *
 *   {@see \SugarCraft\Crush\Cli\Bootstrap} `new ProviderFactory()`
 *     -> {@see ProviderFactory::create()}
 *     -> `ProviderFactory::instantiateProvider()`, `match ($type)`, arm `'sglang'`
 *     -> `ProviderFactory::createSglang()`
 *     -> `SglangProvider::openAiCompatible()` -> `new self(...)`
 *
 * NOT ONE LINK IN THAT CHAIN IS VISIBLE TO A `T_NEW`-ADJACENCY WALK. The
 * factory's name is not `new`, and the arm that selects it is a string key. A
 * name-keyed lookup a token scanner cannot see is the hole a guard must
 * ANNOUNCE rather than silently score as absence, so the two claims below are
 * pinned by different instruments on purpose: the reachability by RUNNING the
 * chain, and the blindness by fixture.
 */
final class SglangProviderReachabilityTest extends TestCase
{
    /**
     * A base URL that resolves to nothing. Construction performs no I/O — the
     * Guzzle client is built, not used — so the chain can be driven for real
     * without a server, and a regression that made construction dial out would
     * fail here rather than silently reach the network.
     */
    private const UNROUTABLE_BASE_URL = 'http://127.0.0.1:1/v1';

    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
    }

    /**
     * THE REACHABILITY CLAIM, MADE BY RUNNING THE CHAIN RATHER THAN READING IT.
     *
     * A test that asserted the `'sglang'` arm exists by grepping the `match`
     * would survive `createSglang()` being gutted. This drives the public entry
     * point and asks the object what it is.
     */
    public function testTheProviderFactoryReallyBuildsAnSglangProvider(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'sglang',
            'baseUrl' => self::UNROUTABLE_BASE_URL,
            'model' => SglangProvider::DEFAULT_MODEL,
        ]);

        self::assertInstanceOf(
            SglangProvider::class,
            $provider,
            "the 'sglang' arm of ProviderFactory's name-keyed dispatch no longer yields an "
                . 'SglangProvider. If the class was genuinely retired, the two RuntimeNoticeSink '
                . 'sites in decodeToolArguments() became dormant with it and their doc-blocks '
                . 'need rewriting; if it merely moved, repoint this test rather than deleting it.',
        );
        self::assertInstanceOf(ProviderInterface::class, $provider);
        self::assertSame('sglang', $provider->name());
    }

    /**
     * AND THE SEAM ITSELF IS LIVE, not merely the class around it.
     *
     * {@see SglangProvider::argumentDecoder()} is the public seam
     * `ProviderFactory::createSglang()` hands to the tool-call parser it builds,
     * and it is a thin closure over {@see SglangProvider::decodeToolArguments()}
     * — the method holding both of round 48's moves. Driving it with a
     * syntactically valid but non-object payload exercises the first of the two
     * refusals end to end, so "these sites are reachable" is a behaviour here
     * and not an inference from the call graph.
     *
     * The scalar `12` is the case the site's own comment describes: valid JSON,
     * unusable as arguments, and previously a `TypeError` out of
     * `ToolCall::fromArray()`.
     */
    public function testTheDecodeSeamRefusesAScalarPayloadThroughThePublicDecoder(): void
    {
        RuntimeNoticeSink::arm(false);

        $decoder = SglangProvider::argumentDecoder();

        // `warn()` is `error_log()` PLUS `record()`, so driving it here would
        // print the refusal into the suite's own stderr. Divert the log for the
        // duration rather than asserting on a quieter seam: the noisy one is
        // the seam round 48 moved, and it is the one that has to be reachable.
        $logged = self::withErrorLogDiscarded(static function () use ($decoder): void {
            self::assertSame([], $decoder('12', 'Read'));
        });
        self::assertStringContainsString('decoded to int', $logged, 'the refusal no longer reaches stderr either');

        $notices = RuntimeNoticeSink::drain();
        self::assertCount(1, $notices, 'the scalar-payload refusal no longer reaches the notice sink');
        self::assertStringContainsString('decoded to int, not an object', $notices[0]);
        self::assertStringContainsString('"Read"', $notices[0]);

        // THE NEGATIVE HALF, so the assertion above cannot be satisfied by a
        // seam that warns about everything. A genuine zero-argument call
        // arrives as an empty payload and must stay quiet — on BOTH channels.
        $quiet = self::withErrorLogDiscarded(static function () use ($decoder): void {
            self::assertSame([], $decoder('', 'Read'));
        });
        self::assertSame('', $quiet);
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    /**
     * Run `$body` with `error_log()` diverted to a scratch file, and return
     * what it wrote there.
     *
     * `tempnam()` and not a hand-built name: five suites share one uid-keyed
     * TMPDIR during an audit round, and the argument-less `uniqid` form is
     * microtime-derived rather than process-unique — the mechanism behind the
     * cross-process collision db90e768 swept out of `tests/`. The bare call is
     * deliberately not spelled here; that sweep ate the prose describing it.
     */
    private static function withErrorLogDiscarded(callable $body): string
    {
        $log = tempnam(sys_get_temp_dir(), 'sc_r49b_sglang_');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);

        try {
            $body();
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $contents = (string) file_get_contents($log);
        @unlink($log);

        return $contents;
    }

    /**
     * THE BLINDNESS, PINNED BY FIXTURE RATHER THAN BY A COUNT OVER `src/`.
     *
     * A cardinality over the tree is wrong the moment another lane merges, so
     * the claim "a `new`-shaped scan cannot see how this class is built" is
     * made against sources written here, where a merge cannot move the answer.
     * Both polarities run through the SAME scanner in the same test: the two
     * shapes it knows are found, and the shape the application actually uses is
     * not.
     *
     * IF THIS FILE'S SCANNER AND `StderrEmitterCensusTest`'s EVER DISAGREE, the
     * disagreement is the finding — this one is deliberately a superset,
     * reporting named static factories alongside the `new`-shaped sites, and it
     * exists to measure the gap between the two rather than to replace either.
     */
    public function testTheNewShapedScanCannotSeeTheFactoryThisClassIsBuiltWith(): void
    {
        // The shape ProviderFactory::createSglang() actually uses.
        $real = <<<'PHP'
            <?php
            return SglangProvider::openAiCompatible(baseUrl: $u, model: $m);
            PHP;

        self::assertSame(0, self::constructionShapes('SglangProvider', $real)['newShaped']);
        self::assertSame(
            ['openAiCompatible'],
            self::constructionShapes('SglangProvider', $real)['namedFactories'],
            'the superset arm has stopped seeing the named factory, so this test no longer '
                . 'demonstrates a gap between two instruments — it just reports two zeroes',
        );

        // KNOWN-POSITIVE THROUGH THE SAME SCANNER (rule 15): the two shapes a
        // `new`-adjacency walk DOES recognise, plus a non-construction that
        // must not be counted.
        $known = <<<'PHP'
            <?php
            $a = new SglangProvider($x);
            $b = SglangProvider::new();
            $c = SglangProvider::class;
            $d = new CustomProvider($x);
            PHP;

        $shapes = self::constructionShapes('SglangProvider', $known);
        self::assertSame(2, $shapes['newShaped'], 'constructionShapes() has gone blind');
        self::assertSame([], $shapes['namedFactories']);
    }

    /**
     * A STATIC CALL WHOSE METHOD NAME THIS SCANNER CANNOT READ IS RECORDED, NOT
     * DROPPED.
     *
     * `SglangProvider::{$method}()` and `SglangProvider::$method()` are legal
     * PHP and could construct the class as readily as a literal factory name.
     * Answering `[]` for them would give this file's central claim — "the only
     * construction path is a named factory the other scanner misses" — a hole
     * shaped exactly like the next one. It reports `<dynamic method name>`
     * instead, so a future dynamic dispatch surfaces as an unreadable site
     * rather than as an absence.
     */
    public function testTheScannerReportsADynamicStaticCallRatherThanIgnoringIt(): void
    {
        self::assertSame(
            ['<dynamic method name>', '<dynamic method name>'],
            self::constructionShapes('SglangProvider', <<<'PHP'
                <?php
                SglangProvider::{$method}($config);
                SglangProvider::$method($config);
                PHP)['namedFactories'],
        );
    }

    /**
     * The construction shapes of `$class` in `$source`.
     *
     * `newShaped` counts what a `T_NEW`-adjacency walk sees: `new Foo`,
     * `new \Ns\Foo`, `new Ns\Foo` (all three with or without an argument list,
     * since `new Foo;` constructs just as much as `new Foo()`), and the
     * canonical `Foo::new()` factory — which has to be matched on `T_NEW`
     * preceded by `T_DOUBLE_COLON`, because PHP 8.3.6 lexes the `new` in
     * `Foo::new(` as `T_NEW` rather than `T_STRING`.
     *
     * THAT FACTORY'S OWN DECLARATION IS EXCLUDED TOO, BUT NOT BY THAT RULE.
     * WHAT THIS PARAGRAPH USED TO SAY: "a `T_NEW` preceded by `T_FUNCTION` is
     * that factory's DECLARATION and is excluded by the same rule". WHAT IS
     * TRUE: `public static function new()` lexes as `T_FUNCTION T_NEW '('`
     * (VERIFIED by `token_get_all()`, PHP 8.3.6), so its previous token is not
     * `T_DOUBLE_COLON` and the factory arm is never entered. It is the
     * bare-`new` arm that rejects it, by asking whether the token AFTER `T_NEW`
     * names the target class — and there it is `(`, which names nothing. WHY THE
     * SENTENCE STILL EARNS ITS PLACE: a reader who dropped that class-name check
     * on the grounds that the double-colon rule already handles declarations
     * would start scoring every `function new()` in the tree as a construction.
     *
     * `namedFactories` is the arm the other scanner does not have: every OTHER
     * static call on `$class`, by method name, in source order. That is where
     * `openAiCompatible` shows up.
     *
     * @return array{newShaped: int, namedFactories: list<string>}
     */
    private static function constructionShapes(string $class, string $source): array
    {
        $significant = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        $newShaped = 0;
        $named = [];

        foreach ($significant as $i => $token) {
            if (\is_array($token) && $token[0] === T_NEW) {
                $previous = $significant[$i - 1] ?? null;
                if (\is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    if (
                        ($significant[$i + 1] ?? null) === '('
                        && self::shortName($significant[$i - 2] ?? null) === $class
                    ) {
                        $newShaped++;
                    }

                    continue;
                }
                if (self::shortName($significant[$i + 1] ?? null) === $class) {
                    $newShaped++;
                }

                continue;
            }

            if ($token !== '::' && !(\is_array($token) && $token[0] === T_DOUBLE_COLON)) {
                continue;
            }
            if (self::shortName($significant[$i - 1] ?? null) !== $class) {
                continue;
            }

            $method = $significant[$i + 1] ?? null;

            // `Foo::new()` is the `newShaped` arm's business, and `Foo::CONST`
            // / `Foo::class` construct nothing.
            if (\is_array($method) && $method[0] === T_NEW) {
                continue;
            }
            if (\is_array($method) && $method[0] === T_STRING) {
                if (($significant[$i + 2] ?? null) === '(') {
                    $named[] = $method[1];
                }

                continue;
            }
            if (\is_array($method) && $method[0] === T_CLASS) {
                continue;
            }

            // Anything else after `::` is a method name this walk cannot read
            // — a variable or a `{…}` expression. Record it; do not drop it.
            $named[] = '<dynamic method name>';
        }

        return ['newShaped' => $newShaped, 'namedFactories' => $named];
    }

    /**
     * The short class name a token denotes, or null if it names no class.
     *
     * `T_NAME_QUALIFIED` and `T_NAME_FULLY_QUALIFIED` matter as much as
     * `T_STRING`: `Providers\SglangProvider` and `\SugarCraft\Crush\Providers\SglangProvider`
     * are the same class as `SglangProvider`, and a walk that accepted only
     * `T_STRING` would score both as absences.
     */
    private static function shortName(array|string|null $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }
        if (!\in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $segments = explode('\\', $token[1]);
        $short = array_pop($segments);

        return $short === '' ? null : $short;
    }
}
