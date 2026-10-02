<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Audit A20 + the Bedrock half of A15: the window and price tables used to
 * exact-match BARE ids, so every real versioned, inference-profile or ARN id
 * got an 8,192-token window and an invented $0.01/1k both ways. Ids are now
 * normalised to a family before an exact table lookup, unknown models answer
 * window 0 / price null, and a turn on an unpriced model carries
 * {@see \SugarCraft\Crush\Usage::$unpricedModel}.
 */
final class BedrockModelIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, float, float}>
     */
    public static function realIds(): iterable
    {
        yield 'us. profile + version' => ['us.anthropic.claude-sonnet-4-6-v1:0', 1_000_000, 0.003, 0.015];
        yield 'global. profile' => ['global.anthropic.claude-opus-4-6-v1', 1_000_000, 0.005, 0.025];
        yield 'eu. profile + date + version' => ['eu.anthropic.claude-sonnet-4-5-20250929-v1:0', 200_000, 0.003, 0.015];
        yield 'apac. profile, Sonnet 4' => ['apac.anthropic.claude-sonnet-4-20250514-v1:0', 200_000, 0.003, 0.015];
        yield 'us-gov. profile' => ['us-gov.anthropic.claude-3-haiku-20240307-v1:0', 200_000, 0.00025, 0.00125];
        yield 'foundation-model id with date + version' => ['anthropic.claude-haiku-4-5-20251001-v1:0', 200_000, 0.001, 0.005];
        yield 'v2 version' => ['anthropic.claude-3-5-sonnet-20241022-v2:0', 200_000, 0.003, 0.015];
        yield 'provisioned throughput tail' => ['anthropic.claude-3-sonnet-20240229-v1:0:200k', 200_000, 0.003, 0.015];
        yield 'inference-profile ARN' => [
            'arn:aws:bedrock:us-east-1:123456789012:inference-profile/us.anthropic.claude-opus-4-1-20250805-v1:0',
            200_000,
            0.015,
            0.075,
        ];
        yield 'foundation-model ARN' => [
            'arn:aws:bedrock:us-east-1::foundation-model/anthropic.claude-3-5-haiku-20241022-v1:0',
            200_000,
            0.0008,
            0.004,
        ];
        yield 'uppercase' => ['US.ANTHROPIC.CLAUDE-OPUS-5-5', 1_000_000, 0.004, 0.02];
        yield 'versioned llama' => ['meta.llama3-70b-instruct-v1:0', 8_192, 0.00065, 0.00275];
    }

    #[DataProvider('realIds')]
    public function testRealBedrockIdsResolveToTheirFamily(string $id, int $window, float $input, float $output): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()), 'us-east-1', $id);

        $this->assertSame($window, $provider->contextWindow());
        $this->assertSame($input, $provider->costPer1kTokens($id, 'input'));
        $this->assertSame($output, $provider->costPer1kTokens($id, 'output'));
    }

    public function testTheAuditsProfileIdIsSizedAtLeast200k(): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()), defaultModel: 'us.anthropic.claude-sonnet-4-6-v1:0');

        $this->assertGreaterThanOrEqual(200_000, $provider->contextWindow());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'profile on an unknown vendor model' => ['us.meta.x'];
        yield 'unknown claude family' => ['anthropic.claude-x'];
        // Exact family match, not a prefix: an unknown minor version must
        // not borrow the `anthropic.claude-sonnet-4` row.
        yield 'unknown sonnet minor' => ['anthropic.claude-sonnet-4-7'];
        yield 'unknown sonnet minor via profile' => ['us.anthropic.claude-sonnet-4-7-v1:0'];
        yield 'the fabricated haiku-4-7 row is gone' => ['anthropic.claude-haiku-4-7'];
    }

    #[DataProvider('unknownIds')]
    public function testUnknownModelsAreUnpricedAndUnsized(string $id): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()), 'us-east-1', $id);

        $this->assertNull($provider->costPer1kTokens($id, 'input'));
        $this->assertNull($provider->costPer1kTokens($id, 'output'));
        $this->assertSame(0, $provider->contextWindow());
    }

    public function testAnUnpricedModelBillsTheLowerBoundAndNamesItself(): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()));

        $usage = $provider->parseUsage(['inputTokens' => 1000, 'outputTokens' => 500], 'anthropic.claude-x');

        $this->assertSame(0.0, $usage->costUsd);
        $this->assertSame('anthropic.claude-x', $usage->unpricedModel);
        $this->assertSame(1500, $usage->totalTokens);
    }

    public function testAPricedModelCarriesNoUnpricedSignal(): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()));

        $usage = $provider->parseUsage(['inputTokens' => 1000, 'outputTokens' => 1000], 'us.anthropic.claude-sonnet-4-6-v1:0');

        $this->assertEqualsWithDelta(0.018, $usage->costUsd, 1e-12);
        $this->assertNull($usage->unpricedModel);
    }

    public function testAnEmptyUsageDocumentIsNotUnpriced(): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()));

        // Every ConverseStream event before `metadata` parses an empty
        // document: no tokens carried, so no rate was needed.
        $this->assertNull($provider->parseUsage([], 'anthropic.claude-x')->unpricedModel);
    }

    public function testTheUnaryReplyOnAnUnpricedModelCarriesTheSignal(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'hi']]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
        ]));
        $provider = new BedrockProvider($this->client($mock), 'us-east-1', 'amazon.nova-pro-v1:0');

        $response = $provider->complete(new CompleteRequest(model: '', messages: [new UserMessage('hi')]));

        $this->assertSame(0.0, $response->costUsd);
        $this->assertSame('amazon.nova-pro-v1:0', $response->usage?->unpricedModel);
    }

    public function testTheStreamedMetadataChunkCarriesTheUnpricedSignal(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['stream' => new \ArrayIterator([
            ['messageStart' => ['role' => 'assistant']],
            ['contentBlockDelta' => ['delta' => ['text' => 'Hello'], 'contentBlockIndex' => 0]],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => ['inputTokens' => 100, 'outputTokens' => 40]]],
        ])]));
        $provider = new BedrockProvider($this->client($mock), 'us-east-1', 'anthropic.claude-x');

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'anthropic.claude-x',
            messages: [new UserMessage('hi')],
        )));

        $terminal = $chunks[count($chunks) - 1];
        $this->assertSame(0.0, $terminal->costUsd);
        $this->assertSame('anthropic.claude-x', $terminal->usage?->unpricedModel);
        foreach (array_slice($chunks, 0, -1) as $chunk) {
            $this->assertNull($chunk->usage?->unpricedModel, 'an event with no tokens needed no rate');
        }
    }

    public function testTheStreamedMetadataChunkIsPricedOnAProfileId(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['stream' => new \ArrayIterator([
            ['metadata' => ['usage' => ['inputTokens' => 1000, 'outputTokens' => 1000]]],
        ])]));
        $provider = new BedrockProvider($this->client($mock));

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'global.anthropic.claude-opus-4-6-v1',
            messages: [new UserMessage('hi')],
        )));

        $this->assertEqualsWithDelta(0.030, $chunks[0]->costUsd, 1e-12);
        $this->assertNull($chunks[0]->usage?->unpricedModel);
    }

    public function testAnOperatorRateOverridesTheBuiltInRowForTheRawId(): void
    {
        $id = 'us.anthropic.claude-sonnet-4-6-v1:0';
        $provider = new BedrockProvider($this->client(new MockHandler()), modelPrices: [
            $id => ['input' => 3.3, 'output' => 16.5],
        ]);

        $this->assertEqualsWithDelta(0.0033, $provider->costPer1kTokens($id, 'input'), 1e-12);
        $this->assertEqualsWithDelta(0.0165, $provider->costPer1kTokens($id, 'output'), 1e-12);
        // A different spelling of the same family is not the named id.
        $this->assertSame(0.003, $provider->costPer1kTokens('anthropic.claude-sonnet-4-6', 'input'));
    }

    public function testAnOperatorRateForTheFamilyCoversEverySpelling(): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()), modelPrices: [
            'amazon.nova-pro' => ['input' => 0.8, 'output' => 3.2],
        ]);

        $this->assertEqualsWithDelta(0.0008, $provider->costPer1kTokens('us.amazon.nova-pro-v1:0', 'input'), 1e-12);
        $this->assertEqualsWithDelta(0.0032, $provider->costPer1kTokens('amazon.nova-pro-v1:0', 'output'), 1e-12);
    }

    public function testANamedButInvalidOperatorRateIsUnpricedNotTheShippedRow(): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()), modelPrices: [
            'anthropic.claude-sonnet-4-6' => ['input' => 'cheap', 'output' => -15],
        ]);

        $this->assertNull($provider->costPer1kTokens('us.anthropic.claude-sonnet-4-6', 'input'));
        $this->assertNull($provider->costPer1kTokens('us.anthropic.claude-sonnet-4-6', 'output'));

        $usage = $provider->parseUsage(['inputTokens' => 10, 'outputTokens' => 10], 'us.anthropic.claude-sonnet-4-6');
        $this->assertSame('us.anthropic.claude-sonnet-4-6', $usage->unpricedModel);
    }

    public function testAZeroOperatorRateIsAGenuinelyFreeModel(): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()), modelPrices: [
            'meta.llama3-8b-instruct' => ['input' => 0, 'output' => 0],
        ]);

        $usage = $provider->parseUsage(['inputTokens' => 10, 'outputTokens' => 10], 'meta.llama3-8b-instruct-v1:0');

        $this->assertSame(0.0, $usage->costUsd);
        $this->assertNull($usage->unpricedModel);
    }

    public function testCreateAcceptsModelPrices(): void
    {
        $provider = BedrockProvider::create('us-east-1', 'amazon.nova-pro-v1:0', [
            'amazon.nova-pro' => ['input' => 0.8, 'output' => 3.2],
        ]);

        $this->assertEqualsWithDelta(0.0008, $provider->costPer1kTokens('amazon.nova-pro-v1:0', 'input'), 1e-12);
    }

    public function testTheDefaultModelIsAPricedSizedInferenceProfile(): void
    {
        $provider = new BedrockProvider($this->client(new MockHandler()));
        $model = $provider->model();

        $this->assertMatchesRegularExpression('/^(?:us|eu|apac|global)\.anthropic\./', $model);
        $this->assertNotNull($provider->costPer1kTokens($model, 'input'));
        $this->assertNotNull($provider->costPer1kTokens($model, 'output'));
        $this->assertGreaterThanOrEqual(200_000, $provider->contextWindow());
        $this->assertSame($model, BedrockProvider::create()->model());
    }

    private function client(MockHandler $handler): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $handler,
        ]);
    }
}
