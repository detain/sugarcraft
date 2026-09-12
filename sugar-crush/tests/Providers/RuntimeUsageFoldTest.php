<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Usage;

/**
 * E17 follow-through, fold half: Runtime's two seams — the per-chunk
 * collection in runStreaming() and the single projection in runBatch() — now
 * consult the CompleteResponse carrier before falling back to the
 * `tokensUsed`/`costUsd` projection, and the fallback is byte-identical to
 * the pre-fold answer for every provider that carries nothing.
 *
 * The rule being pinned is the pairing, not either side alone:
 * carrier measuring anything -> carrier whole (buckets survive);
 * carrier absent OR all-unmeasured (the shape an empty wire document parses
 * to, e.g. Bedrock's pre-terminal events) -> projection, which stays null
 * when nothing was counted. Zero remains distinct from unknown across the
 * fold, and no figure a pre-fold test pinned moves.
 */
final class RuntimeUsageFoldTest extends TestCase
{
    public function testTheBatchFoldPassesACarriedDocumentThroughWithItsBuckets(): void
    {
        $carrier = Usage::new(113, 0.5, 75, 24, 8, 6, 25);
        $provider = $this->batchProviderRespondingWith(
            new CompleteResponse(content: 'ok', tokensUsed: 113, costUsd: 0.5, usage: $carrier),
        );

        $usage = $this->turnUsage($provider);

        $this->assertSame($carrier, $usage, 'the carrier reaches the Message whole — same document, buckets intact');
        $this->assertSame(75, $usage->inputTokens);
        $this->assertSame(25, $usage->reasoningTokens);
        $this->assertSame(89, $usage->promptTokens(), 'a fully partitioned carrier resolves the prompt identity across the fold');
    }

    public function testTheBatchFoldFallsBackToTheProjectionWhenNoCarrierIsCarried(): void
    {
        $provider = $this->batchProviderRespondingWith(
            new CompleteResponse(content: 'ok', tokensUsed: 42, costUsd: 0.002),
        );

        $usage = $this->turnUsage($provider);

        $this->assertNotNull($usage, 'the projection still bills: this arm is byte-identical to the pre-fold fold');
        $this->assertSame(42, $usage->totalTokens);
        $this->assertSame(0.002, $usage->costUsd);
        $this->assertNull($usage->inputTokens, 'a projection-only turn reports no split — not even a zero');
        $this->assertNull($usage->reasoningTokens);
    }

    public function testAnEmptyCarrierYieldsToTheProjectionRatherThanReportingZero(): void
    {
        // Bedrock's pre-terminal events, and any provider whose wire usage
        // document arrives empty, parse to a Usage that measured nothing.
        // The fold must treat that as ABSENT — the projection owns the
        // answer — while the carrier's literal 0/0.0 must never beat a real
        // projection figure.
        $provider = $this->batchProviderRespondingWith(new CompleteResponse(
            content: 'ok',
            tokensUsed: 7,
            costUsd: 0.0,
            usage: Usage::new(0, 0.0),
        ));

        $usage = $this->turnUsage($provider);

        $this->assertNotNull($usage);
        $this->assertSame(7, $usage->totalTokens, 'the empty carrier did not erase the projection');
        $this->assertNull($usage->inputTokens);
    }

    public function testNothingReportedStillFoldsToNullNotZeroAcrossTheCarrierBranch(): void
    {
        $provider = $this->batchProviderRespondingWith(new CompleteResponse(
            content: 'ok',
            tokensUsed: 0,
            costUsd: 0.0,
            usage: Usage::new(0, 0.0),
        ));

        $this->assertNull($this->turnUsage($provider), 'no document, no projection -> "nothing reported", never "$0 spent"');
    }

    public function testTheStreamingFoldMergesPerSideCarriersAcrossTheSum(): void
    {
        // The Vertex message_start / message_delta topology through real
        // carriers: each side's buckets merge exactly once, and the totals
        // match the pre-fold sum.
        $start = Usage::new(12, 0.0012, 12, null, 6400, 500);
        $delta = Usage::new(4, 0.0004, null, 4);
        $provider = $this->streamProviderYielding([
            new CompleteResponse(content: 'Hel'),
            new CompleteResponse(content: '', tokensUsed: 12, costUsd: 0.0012, usage: $start),
            new CompleteResponse(content: '', tokensUsed: 4, costUsd: 0.0004, usage: $delta),
        ]);

        $usage = $this->turnUsage($provider);

        $this->assertNotNull($usage);
        $this->assertSame(16, $usage->totalTokens, 'sum unchanged from the projection fold');
        $this->assertSame(12, $usage->inputTokens);
        $this->assertSame(4, $usage->outputTokens);
        $this->assertSame(6400, $usage->cacheReadTokens, 'cache buckets survive the per-chunk fold and the sum');
        $this->assertSame(500, $usage->cacheCreationTokens);
        $this->assertEqualsWithDelta(0.0016, $usage->costUsd, 0.0000001);
    }

    public function testTheStreamingFoldSkipsEmptyCarriersAndKeepsTheTerminalOne(): void
    {
        // Bedrock's ConverseStream shape through the real fold: three events
        // that parsed an empty document, one terminal metadata event that did
        // not. The empty carriers fold to null (skipped by the sum) exactly
        // as the old zero projections did; only the terminal bill lands.
        $empty = Usage::new(0, 0.0);
        $terminal = Usage::new(140, 0.0028, 100, 40, 1152);
        $provider = $this->streamProviderYielding([
            new CompleteResponse(content: '', tokensUsed: 0, costUsd: 0.0, usage: $empty),
            new CompleteResponse(content: 'Hello', tokensUsed: 0, costUsd: 0.0, usage: $empty),
            new CompleteResponse(content: '', tokensUsed: 140, costUsd: 0.0028, usage: $terminal),
        ]);

        $usage = $this->turnUsage($provider);

        $this->assertSame($terminal, $usage, 'the empty carriers folded away; the terminal carrier arrived whole');
    }

    // ---- provider doubles ---------------------------------------------------

    private function turnUsage(ProviderInterface $provider): ?Usage
    {
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(App::new($provider, 'fold')));

        return $messages[0]->usage();
    }

    private function batchProviderRespondingWith(CompleteResponse $response): ProviderInterface
    {
        return new class ($response) implements ProviderInterface {
            public function __construct(private readonly CompleteResponse $answer) {}

            public function name(): string
            {
                return 'fold-batch-double';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return $this->answer;
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->answer;
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    /** @param list<CompleteResponse> $chunks */
    private function streamProviderYielding(array $chunks): ProviderInterface
    {
        return new class ($chunks) implements ProviderInterface {
            /** @param list<CompleteResponse> $frames */
            public function __construct(private readonly array $frames) {}

            public function name(): string
            {
                return 'fold-stream-double';
            }

            public function supportsStreaming(): bool
            {
                return true;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: 'unused batch arm');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                foreach ($this->frames as $frame) {
                    yield $frame;
                }
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }
}
