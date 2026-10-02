<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Audit A15 (Vertex half): Claude and Gemini on Vertex were priced by a flat
 * `return 0.0` placeholder, so every paid turn read as a confident `$0.0000`
 * with no unpriced signal and the spend cap could never trip. These tests
 * pin the built-in family table, the operator override seam, and the
 * unpriced signal on every usage-producing path (unary Anthropic, both
 * streamed Anthropic events, unary and streamed Gemini).
 */
final class VertexPricingTest extends TestCase
{
    /**
     * @param array<string, mixed> $modelPrices
     * @param list<array<string, mixed>> $events
     */
    private function provider(
        string $model = 'claude-sonnet-4-5@20250929',
        array $modelPrices = [],
        array $predicted = [],
        array $events = [],
    ): VertexProvider {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: fn (string $endpoint, string $method, array $body): array => $predicted,
            streamer: static function (string $endpoint, string $method, array $body) use ($events): \Generator {
                yield from $events;
            },
            modelPrices: $modelPrices,
        );
    }

    /** @return list<CompleteResponse> */
    private function stream(VertexProvider $provider, string $model): array
    {
        return iterator_to_array($provider->completeStream(new CompleteRequest(
            model: $model,
            messages: [new UserMessage('hi')],
        )), false);
    }

    /** @return iterable<string, array{string, float, float}> */
    public static function pricedIds(): iterable
    {
        yield 'factory default, versioned' => ['claude-3-sonnet@20240229', 0.003, 0.015];
        yield 'sonnet 4.5 dated' => ['claude-sonnet-4-5@20250929', 0.003, 0.015];
        yield 'opus 4.1 dated' => ['claude-opus-4-1@20250805', 0.015, 0.075];
        yield 'opus 4 -0 alias' => ['claude-opus-4-0', 0.015, 0.075];
        yield 'sonnet 3.5 v2' => ['claude-3-5-sonnet-v2@20241022', 0.003, 0.015];
        yield 'opus 4.6 bare' => ['claude-opus-4-6', 0.005, 0.025];
        yield 'sonnet 4.6 bare' => ['claude-sonnet-4-6', 0.003, 0.015];
        yield 'opus 5.5 bare' => ['claude-opus-5-5', 0.004, 0.02];
        yield 'haiku 4.5 dated' => ['claude-haiku-4-5@20251001', 0.001, 0.005];
        yield 'resource path' => ['publishers/anthropic/models/claude-3-haiku@20240307', 0.00025, 0.00125];
        yield 'upper case' => ['CLAUDE-SONNET-4-5@20250929', 0.003, 0.015];
        yield 'gemini 2.5 pro' => ['gemini-2.5-pro', 0.00125, 0.01];
        yield 'gemini 2.5 flash revision' => ['gemini-2.5-flash-001', 0.0003, 0.0025];
        yield 'gemini 2.5 flash-lite' => ['gemini-2.5-flash-lite', 0.0001, 0.0004];
        yield 'gemini 2.0 flash revision' => ['gemini-2.0-flash-001', 0.00015, 0.0006];
    }

    #[DataProvider('pricedIds')]
    public function testKnownFamiliesArePricedWhateverTheirReleaseDecoration(string $model, float $input, float $output): void
    {
        $provider = $this->provider();

        $this->assertEqualsWithDelta($input, $provider->costPer1kTokens($model, 'input'), 1e-12);
        $this->assertEqualsWithDelta($output, $provider->costPer1kTokens($model, 'output'), 1e-12);
    }

    /** @return iterable<string, array{string}> */
    public static function unpricedIds(): iterable
    {
        yield 'unknown claude' => ['claude-x'];
        yield 'unreleased sonnet must not borrow sonnet-4' => ['claude-sonnet-4-7'];
        yield 'unreleased opus dated' => ['claude-opus-4-9@20270101'];
        yield 'family with a trailing word' => ['claude-sonnet-4-5-extra'];
        yield 'gemini newer than the table' => ['gemini-3-pro'];
        yield 'retired gemini 1.5' => ['gemini-1.5-pro-002'];
        yield 'palm' => ['chat-bison@002'];
    }

    #[DataProvider('unpricedIds')]
    public function testUnknownFamiliesAreUnpricedNotZero(string $model): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->costPer1kTokens($model, 'input'));
        $this->assertNull($provider->costPer1kTokens($model, 'output'));
    }

    public function testAnUnknownClaudeModelBillsTheLowerBoundAndNamesItself(): void
    {
        $usage = $this->provider()->parseAnthropicUsage(['input_tokens' => 1000, 'output_tokens' => 500], 'claude-x');

        $this->assertSame(1500, $usage->totalTokens);
        $this->assertSame(0.0, $usage->costUsd);
        $this->assertSame('claude-x', $usage->unpricedModel);
    }

    public function testAKnownClaudeModelBillsBothSidesAndCarriesNoUnpricedSignal(): void
    {
        $usage = $this->provider()->parseAnthropicUsage(
            ['input_tokens' => 1000, 'output_tokens' => 500],
            'claude-sonnet-4-5@20250929',
        );

        // 1000 x $0.003/1k + 500 x $0.015/1k
        $this->assertEqualsWithDelta(0.0105, $usage->costUsd, 1e-12);
        $this->assertNull($usage->unpricedModel);
    }

    public function testAnOperatorRateForTheRawIdWinsOverTheBuiltInRow(): void
    {
        $provider = $this->provider(modelPrices: [
            'claude-sonnet-4-5@20250929' => ['input' => 6, 'output' => 30],
        ]);

        $this->assertEqualsWithDelta(0.006, $provider->costPer1kTokens('claude-sonnet-4-5@20250929', 'input'), 1e-12);
        $this->assertEqualsWithDelta(0.03, $provider->costPer1kTokens('claude-sonnet-4-5@20250929', 'output'), 1e-12);
        // A different release of the same family still reads the built-in row.
        $this->assertEqualsWithDelta(0.003, $provider->costPer1kTokens('claude-sonnet-4-5', 'input'), 1e-12);
    }

    public function testAnOperatorRateForTheFamilyCoversEveryReleaseOfIt(): void
    {
        $provider = $this->provider(modelPrices: ['claude-sonnet-4-5' => ['input' => 3.3, 'output' => 16.5]]);

        $this->assertEqualsWithDelta(0.0033, $provider->costPer1kTokens('claude-sonnet-4-5@20250929', 'input'), 1e-12);
        $this->assertEqualsWithDelta(0.0165, $provider->costPer1kTokens('claude-sonnet-4-5@20250929', 'output'), 1e-12);
    }

    public function testAnOperatorCanPriceAModelTheTableDoesNotKnow(): void
    {
        $provider = $this->provider(modelPrices: ['claude-x' => ['input' => 1, 'output' => 2]]);

        $usage = $provider->parseAnthropicUsage(['input_tokens' => 1000, 'output_tokens' => 1000], 'claude-x');

        $this->assertEqualsWithDelta(0.003, $usage->costUsd, 1e-12);
        $this->assertNull($usage->unpricedModel);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'non-numeric' => [['input' => 'cheap', 'output' => 15]];
        yield 'negative' => [['input' => -3, 'output' => 15]];
        yield 'infinite' => [['input' => INF, 'output' => 15]];
        yield 'missing direction' => [['output' => 15]];
        yield 'not a map' => ['3/15'];
    }

    #[DataProvider('invalidDeclarations')]
    public function testANamedButInvalidOperatorRateIsUnpricedNotTheShippedRow(mixed $entry): void
    {
        $provider = $this->provider(modelPrices: ['claude-sonnet-4-5' => $entry]);

        $this->assertNull($provider->costPer1kTokens('claude-sonnet-4-5@20250929', 'input'));

        $usage = $provider->parseAnthropicUsage(
            ['input_tokens' => 10, 'output_tokens' => 10],
            'claude-sonnet-4-5@20250929',
        );
        $this->assertSame(0.0, $usage->costUsd);
        $this->assertSame('claude-sonnet-4-5@20250929', $usage->unpricedModel);
    }

    public function testAZeroOperatorRateIsAGenuinelyFreeModelNotAnUnpricedOne(): void
    {
        $provider = $this->provider(modelPrices: ['claude-x' => ['input' => 0, 'output' => 0]]);

        $usage = $provider->parseAnthropicUsage(['input_tokens' => 100, 'output_tokens' => 100], 'claude-x');

        $this->assertSame(0.0, $usage->costUsd);
        $this->assertNull($usage->unpricedModel);
    }

    public function testTheStreamedAnthropicEventsEachBillOnlyTheirOwnSide(): void
    {
        $model = 'claude-sonnet-4-5@20250929';
        $chunks = $this->stream($this->provider(model: $model, events: [
            // Both-sided on purpose: the start event must still bill input only.
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 1000, 'output_tokens' => 1]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2000]],
            ['type' => 'message_stop'],
        ]), $model);

        $billed = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->tokensUsed > 0));
        $this->assertCount(2, $billed);

        [$start, $delta] = $billed;
        $this->assertEqualsWithDelta(0.003, $start->costUsd, 1e-12, '1000 input x $0.003/1k');
        $this->assertEqualsWithDelta(0.003, $start->usage?->costUsd, 1e-12);
        $this->assertNull($start->usage?->unpricedModel);
        $this->assertEqualsWithDelta(0.03, $delta->costUsd, 1e-12, '2000 output x $0.015/1k');
        $this->assertEqualsWithDelta(0.03, $delta->usage?->costUsd, 1e-12);
        $this->assertNull($delta->usage?->unpricedModel);
    }

    public function testTheStreamedAnthropicEventsOfAnUnknownModelBothCarryTheUnpricedSignal(): void
    {
        $chunks = $this->stream($this->provider(model: 'claude-x', events: [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 1000]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2000]],
            ['type' => 'message_stop'],
        ]), 'claude-x');

        $billed = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->tokensUsed > 0));
        $this->assertCount(2, $billed);

        foreach ($billed as $chunk) {
            $this->assertSame(0.0, $chunk->costUsd);
            $this->assertSame('claude-x', $chunk->usage?->unpricedModel);
        }
    }

    public function testAOneSidedDeclarationLeavesOnlyTheUndeclaredSideUnpriced(): void
    {
        // Only `input` declared: message_start prices, message_delta (output
        // tokens only) is unpriced - and a side with no tokens never needs a rate.
        $provider = $this->provider(model: 'claude-x', modelPrices: ['claude-x' => ['input' => 1]], events: [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 1000]]],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 10]],
            ['type' => 'message_stop'],
        ]);

        $billed = array_values(array_filter(
            $this->stream($provider, 'claude-x'),
            static fn (CompleteResponse $c): bool => $c->tokensUsed > 0,
        ));
        $this->assertCount(2, $billed);

        $this->assertEqualsWithDelta(0.001, $billed[0]->costUsd, 1e-12);
        $this->assertNull($billed[0]->usage?->unpricedModel);
        $this->assertSame(0.0, $billed[1]->costUsd);
        $this->assertSame('claude-x', $billed[1]->usage?->unpricedModel);
    }

    public function testAUnaryAnthropicCompletionIsPricedEndToEnd(): void
    {
        $model = 'claude-3-sonnet@20240229';
        $response = $this->provider(model: $model, predicted: [
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 1000],
        ])->complete(new CompleteRequest(model: $model, messages: [new UserMessage('hi')]));

        // 2000 x 0.003/1k + 1000 x 0.015/1k
        $this->assertEqualsWithDelta(0.021, $response->costUsd, 1e-12);
        $this->assertEqualsWithDelta(0.021, $response->usage?->costUsd, 1e-12);
        $this->assertNull($response->usage?->unpricedModel);
    }

    public function testAUnaryCompletionOfAnUnknownModelNamesItAsUnpriced(): void
    {
        $response = $this->provider(model: 'claude-x', predicted: [
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 1000],
        ])->complete(new CompleteRequest(model: 'claude-x', messages: [new UserMessage('hi')]));

        $this->assertSame(3000, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);
        $this->assertSame('claude-x', $response->usage?->unpricedModel);
    }

    public function testGeminiUsageIsPriced(): void
    {
        $usage = $this->provider()->parseUsageMetadata(
            ['promptTokenCount' => 1000, 'candidatesTokenCount' => 100],
            'gemini-2.5-flash',
        );

        // 1000 x 0.0003/1k + 100 x 0.0025/1k
        $this->assertEqualsWithDelta(0.00055, $usage->costUsd, 1e-12);
        $this->assertNull($usage->unpricedModel);
    }

    public function testAnUnknownGeminiModelIsUnpriced(): void
    {
        $usage = $this->provider()->parseUsageMetadata(
            ['promptTokenCount' => 1000, 'candidatesTokenCount' => 100],
            'gemini-1.5-pro-002',
        );

        $this->assertSame(0.0, $usage->costUsd);
        $this->assertSame('gemini-1.5-pro-002', $usage->unpricedModel);
    }

    public function testTheStreamedGeminiUsageEmitIsPriced(): void
    {
        $model = 'gemini-2.5-pro';
        $chunks = $this->stream($this->provider(model: $model, events: [
            ['candidates' => [['content' => ['parts' => [['text' => 'Hi']]]]]],
            [
                'candidates' => [['content' => ['parts' => [['text' => '!']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 100],
            ],
        ]), $model);

        $billed = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->tokensUsed > 0));
        $this->assertCount(1, $billed);
        // 1000 x 0.00125/1k + 100 x 0.01/1k
        $this->assertEqualsWithDelta(0.00225, $billed[0]->costUsd, 1e-12);
        $this->assertNull($billed[0]->usage?->unpricedModel);
    }
}
