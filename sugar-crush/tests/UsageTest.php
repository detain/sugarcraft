<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Usage;

/**
 * {@see Usage} — the value object that carries a provider's own token count and
 * cost across the seams that used to drop them (crush_code.md Phase 5 item 7).
 *
 * The whole class exists to keep two claims apart: "the provider said this call
 * was free" and "no provider said anything". Every test here is about one of the
 * two, because collapsing them is what turns a status bar into a liar.
 */
final class UsageTest extends TestCase
{
    /**
     * The distinction the class exists for. A streamed turn commonly reports
     * `tokensUsed=0, costUsd=0.0` on every chunk — see
     * {@see \SugarCraft\Crush\Runtime}'s streaming note — and that is "we do not
     * know", which is why it must not become an object at all.
     */
    public function testNothingReportedIsNullAndNotAZeroValuedUsage(): void
    {
        $this->assertNull(Usage::reported(0, 0.0));
    }

    /**
     * The other half, and the one a naive `$total > 0 && $cost > 0` guard would
     * get wrong: a self-hosted provider genuinely bills nothing while still
     * counting tokens ({@see \SugarCraft\Crush\Providers\SglangProvider} and
     * {@see \SugarCraft\Crush\Providers\CustomProvider} both set
     * `costUsd: 0.0` beside a real `usage.total_tokens`). That is a MEASURED
     * free call, not an unknown one, and it has to survive.
     */
    public function testRealTokensAtZeroCostIsReportedBecauseFreeIsNotUnknown(): void
    {
        $usage = Usage::reported(1234, 0.0);

        $this->assertNotNull($usage);
        $this->assertSame(1234, $usage->totalTokens);
        $this->assertSame(0.0, $usage->costUsd);
    }

    /** And the mirror case: a cost with no token count is still a report. */
    public function testACostWithNoTokenCountIsReported(): void
    {
        $usage = Usage::reported(0, 0.25);

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->totalTokens);
        $this->assertSame(0.25, $usage->costUsd);
    }

    /**
     * A negative figure is a provider bug, and the choice made is to account it
     * as zero rather than to throw: failing a turn over a malformed usage block
     * would lose the reply the user was waiting for.
     */
    public function testNegativeFiguresClampRatherThanThrow(): void
    {
        $this->assertNull(Usage::reported(-5, -1.0));

        $usage = Usage::reported(-5, 2.0);
        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->totalTokens);
        $this->assertSame(2.0, $usage->costUsd);
    }

    /**
     * The operation an agentic turn needs: one turn is N provider calls, and the
     * turn's cost is their sum. See
     * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()}.
     */
    public function testPlusAddsBothFigures(): void
    {
        $summed = Usage::new(100, 0.01)->plus(Usage::new(250, 0.05));

        $this->assertSame(350, $summed->totalTokens);
        $this->assertSame(0.06, round($summed->costUsd, 10));
    }

    /** Immutable: `plus()` must not mutate either operand. */
    public function testPlusLeavesBothOperandsAlone(): void
    {
        $a = Usage::new(100, 0.01);
        $b = Usage::new(250, 0.05);
        $a->plus($b);

        $this->assertSame(100, $a->totalTokens);
        $this->assertSame(250, $b->totalTokens);
    }

    /**
     * `sum()` skips the nulls rather than reading them as zeros, which is what
     * lets a turn whose FIRST step reported usage and whose second did not keep
     * the figure it does have.
     */
    public function testSumSkipsUnreportedEntriesWithoutLosingTheReportedOnes(): void
    {
        $summed = Usage::sum([Usage::new(10, 0.1), null, Usage::new(5, 0.2)]);

        $this->assertNotNull($summed);
        $this->assertSame(15, $summed->totalTokens);
        $this->assertSame(0.30000000000000004, $summed->costUsd);
    }

    /**
     * A list of nothing-reported stays nothing-reported. If this returned a
     * zero-valued Usage, every offline run would attach one to every turn and
     * the status bar would print `$0.0000` for a session nobody measured.
     */
    public function testSumOfNothingIsNullAndNotAZero(): void
    {
        $this->assertNull(Usage::sum([]));
        $this->assertNull(Usage::sum([null, null]));
    }

    /**
     * The fork boundary: {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}
     * runs the turn in a child and the parent unserializes with
     * `allowed_classes => false`, so the object cannot cross — only this array
     * can.
     */
    public function testItRoundTripsThroughThePlainArrayTheForkBoundaryAllows(): void
    {
        $usage = Usage::new(4321, 0.1234);
        $wire = $usage->toArray();

        $this->assertSame(['totalTokens' => 4321, 'costUsd' => 0.1234], $wire);

        $back = Usage::fromArray($wire);
        $this->assertNotNull($back);
        $this->assertSame(4321, $back->totalTokens);
        $this->assertSame(0.1234, $back->costUsd);
    }

    /**
     * A corrupt or absent frame costs the turn its ACCOUNTING, not the turn: the
     * reply still resolves, with no usage attached. Anything that is not the
     * shape `toArray()` wrote is refused rather than coerced, so a garbled
     * payload cannot invent a bill.
     *
     * @dataProvider malformedPayloads
     */
    public function testFromArrayRefusesAnythingItDidNotWrite(mixed $payload): void
    {
        $this->assertNull(Usage::fromArray($payload));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedPayloads(): iterable
    {
        yield 'null (no usage key in the frame at all)' => [null];
        yield 'not an array' => ['4321'];
        yield 'tokens as a string' => [['totalTokens' => '4321', 'costUsd' => 0.1]];
        yield 'cost as a string' => [['totalTokens' => 4321, 'costUsd' => '0.1']];
        yield 'missing tokens' => [['costUsd' => 0.1]];
        yield 'missing cost' => [['totalTokens' => 4321]];
        // Not malformed — a real "nothing reported" frame, which must come back
        // as null for the same reason reported() returns null for it.
        yield 'a zero report' => [['totalTokens' => 0, 'costUsd' => 0.0]];
    }

    /** An int cost survives, because JSON-ish payloads flatten 0.0 to 0. */
    public function testFromArrayAcceptsAnIntegerCost(): void
    {
        $back = Usage::fromArray(['totalTokens' => 10, 'costUsd' => 1]);

        $this->assertNotNull($back);
        $this->assertSame(1.0, $back->costUsd);
    }
    // =====================================================================
    // The provider enumeration the class docblock rests on
    // =====================================================================

    /**
     * `Usage`'s central justification is a COUNT — how many providers know an
     * input/output split and throw it away — and that count is the whole stated
     * reason `TokenTracker::addTotalUsage()` and its `unsplitTokens` bucket exist.
     * Nothing asserted it, and it was wrong: the docblock said two (Bedrock,
     * Vertex) and listed `OpenAIProvider` among the five that "never had one to
     * lose", while `OpenAIProvider::calculateCost()` reads `prompt_tokens` and
     * `completion_tokens`, prices each side at its own rate, and then reports only
     * `total_tokens`. Three, not two.
     *
     * DERIVED FROM THE PROVIDER SOURCES rather than restated, because a fourth
     * literal of a number that has already drifted once is a fourth thing to go
     * stale. The two sets are read off the files; the docblock is then required to
     * name each provider on the correct side of the sentence. A new provider, or
     * an existing one gaining or losing its split, reds this test with the name.
     */
    public function testTheDocblocksSplitEnumerationMatchesTheProviderSources(): void
    {
        $dir = dirname(__DIR__) . '/src/Providers';

        $split = [];
        $totalOnly = [];
        foreach ($this->providerClasses() as $short => $file) {
            $source = (string) file_get_contents($file);
            // The usage array keys, quoted, so a local variable named
            // $inputTokens cannot masquerade as a provider that reads one.
            $hasInput = preg_match("/'(?:inputTokens|input_tokens|prompt_tokens)'/", $source) === 1;
            $hasOutput = preg_match("/'(?:outputTokens|output_tokens|completion_tokens)'/", $source) === 1;
            if ($hasInput && $hasOutput) {
                $split[] = $short;
            } else {
                $totalOnly[] = $short;
            }
        }
        sort($split);
        sort($totalOnly);

        $this->assertSame(
            ['BedrockProvider', 'OpenAIProvider', 'VertexProvider'],
            $split,
            'the set of providers that read a separate input/output usage key changed',
        );
        $this->assertCount(
            7,
            [...$split, ...$totalOnly],
            'the provider count the docblock quotes ("three of the seven") changed',
        );

        // The docblock's two sides, read out of it by their own markers rather
        // than by position, so unrelated prose further down cannot be mistaken
        // for either list.
        $docblock = (string) (new \ReflectionClass(Usage::class))->getDocComment();
        $this->assertMatchesRegularExpression(
            '/THREE of the seven providers know the split(.*?)remaining four \(([^)]*)\)/s',
            $docblock,
            'the docblock no longer states the two-sided enumeration this test pins',
        );
        preg_match('/THREE of the seven providers know the split(.*?)remaining four \(([^)]*)\)/s', $docblock, $m);
        [, $splitSide, $totalOnlySide] = $m;

        foreach ($split as $name) {
            $this->assertStringContainsString(
                $name,
                $splitSide,
                "{$name} reads a separate input/output usage key; the docblock must name it on the split side",
            );
            $this->assertStringNotContainsString(
                $name,
                $totalOnlySide,
                "{$name} knows the split and must not be listed among those that never had one",
            );
        }
        foreach ($totalOnly as $name) {
            $this->assertStringContainsString(
                $name,
                $totalOnlySide,
                "{$name} reports a total only and must be listed on that side",
            );
            $this->assertStringNotContainsString(
                $name,
                $splitSide,
                "{$name} has no split to lose and must not be named on the split side",
            );
        }
    }

    /**
     * The one place the split is NOT already gone below this seam, which
     * `Usage`'s docblock claimed of all of them and `Runtime`'s docblock
     * contradicted. Runtime was right, and this pins which.
     *
     * Vertex's unary path sums (`tokensUsed: $inputTokens + $outputTokens`); its
     * Anthropic STREAM emits the two halves as separate `CompleteResponse`s with
     * `tokensUsed: $inputTokens` and `tokensUsed: $outputTokens`, which is why
     * `Runtime` sums across chunks instead of reading the last one.
     */
    public function testVertexsStreamEmitsTheTwoHalvesSeparatelyUnlikeItsUnaryPath(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Providers/VertexProvider.php');

        $this->assertStringContainsString(
            'tokensUsed: $inputTokens + $outputTokens',
            $source,
            'the unary path still collapses the split before the response leaves',
        );
        $this->assertMatchesRegularExpression(
            '/tokensUsed: \$inputTokens,/',
            $source,
            'the stream still emits input tokens on their own (message_start)',
        );
        $this->assertMatchesRegularExpression(
            '/tokensUsed: \$outputTokens,/',
            $source,
            'and output tokens on their own (message_delta)',
        );

        // Bedrock, whose docblock Vertex's used to claim equivalence with, really
        // does land its usage once - both of its paths sum.
        $bedrock = (string) file_get_contents(dirname(__DIR__) . '/src/Providers/BedrockProvider.php');
        $this->assertSame(
            2,
            preg_match_all('/tokensUsed: \$inputTokens \+ \$outputTokens/', $bedrock),
            'Bedrock sums on both its unary and its streaming path, which is the contract Vertex does NOT share',
        );
    }

    /**
     * @return array<string, string> short class name => absolute file path, for
     *                               every concrete {@see \SugarCraft\Crush\Providers\ProviderInterface}
     */
    private function providerClasses(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/src/Providers/*Provider.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $fqn = 'SugarCraft\\Crush\\Providers\\' . $short;
            if (!class_exists($fqn)) {
                continue;
            }
            $reflection = new \ReflectionClass($fqn);
            if ($reflection->isAbstract()
                || !$reflection->implementsInterface(\SugarCraft\Crush\Providers\ProviderInterface::class)
            ) {
                continue;
            }
            $out[$short] = $file;
        }

        return $out;
    }
}
