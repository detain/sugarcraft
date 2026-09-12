<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;
use SugarCraft\Crush\Usage;

/**
 * E17 follow-through, provider half: every construction site that parses a
 * usage DOCUMENT off the wire now also hands that document to
 * CompleteResponse on `usage:` — the buckets stop dying at the projection.
 *
 * Each test drives the provider's REAL transport path (mock handler / SDK
 * double / injected predictor) exactly as the P4.S2 pins in
 * UsageWiringTest do, then asks the one question this fold adds: is the
 * carrier on the returned response, and is it the document the parse made?
 * The two projections are re-pinned beside it, byte-equal to the carrier's
 * own total and cost, because the fold's fallback promise is that no
 * existing figure moves. Flat-wire providers (ClaudeCode, Echo) keep the
 * carrier null on purpose — the decision is commented at their sites and
 * their behavior is pinned at the fold (RuntimeUsageFoldTest's projection
 * arms), so nothing here fabricates a split they never received.
 */
final class ProviderUsageCarriersTest extends TestCase
{
    public function testBedrockUnaryPutsTheWholeTokenUsageDocumentOnTheCarrier(): void
    {
        $provider = new BedrockProvider(
            $this->bedrockUnaryClient([
                'inputTokens' => 100,
                'outputTokens' => 40,
                'cacheReadInputTokens' => 1152,
                'cacheWriteInputTokens' => 8,
            ]),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
        ));

        $this->assertNotNull($response->usage, 'E17: the parsed document rides out on the carrier');
        $this->assertSame(140, $response->usage->totalTokens);
        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(40, $response->usage->outputTokens);
        $this->assertSame(1152, $response->usage->cacheReadTokens);
        $this->assertSame(8, $response->usage->cacheCreationTokens);
        $this->assertSame($response->tokensUsed, $response->usage->totalTokens, 'carrier and projection cannot disagree — one parse produced both');
        $this->assertSame($response->costUsd, $response->usage->costUsd);
    }

    public function testBedrockStreamCarrierLandsOnTheTerminalMetadataEventOnly(): void
    {
        $mock = new \Aws\MockHandler();
        $mock->append(new \Aws\Result(['stream' => new \ArrayIterator([
            ['messageStart' => ['role' => 'assistant']],
            ['contentBlockDelta' => ['delta' => ['text' => 'Hello'], 'contentBlockIndex' => 0]],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => [
                'inputTokens' => 100,
                'outputTokens' => 40,
                'cacheReadInputTokens' => 1152,
            ]]],
        ])]));
        $provider = new BedrockProvider(
            new \Aws\BedrockRuntime\BedrockRuntimeClient([
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
                'handler' => $mock,
            ]),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
        );

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
        )));

        $terminal = $chunks[count($chunks) - 1];
        $this->assertNotNull($terminal->usage, 'the metadata event is the bill — its document must survive the parse boundary');
        $this->assertSame(140, $terminal->usage->totalTokens);
        $this->assertSame(1152, $terminal->usage->cacheReadTokens);

        foreach (array_slice($chunks, 0, -1) as $i => $chunk) {
            $this->assertSame(
                [$chunk->tokensUsed, $chunk->costUsd, $chunk->usage?->totalTokens],
                [0, 0.0, 0],
                "event {$i} has no usage document: the carrier is the all-unmeasured empty parse, which Runtime's fold projects back to nothing-reported",
            );
            $this->assertNull($chunk->usage?->inputTokens, 'an empty document must not grow a reported bucket');
        }
    }

    public function testOpenAiUnaryCarrierSplitsTheCachedPrefixTheSameWayTheParseDoes(): void
    {
        [$provider, $response] = $this->openAiCompleteWith([
            'prompt_tokens' => 75,
            'completion_tokens' => 24,
            'total_tokens' => 99,
            'prompt_tokens_details' => ['cached_tokens' => 8],
        ]);

        $this->assertNotNull($response->usage);
        $this->assertSame(99, $response->usage->totalTokens);
        $this->assertSame(67, $response->usage->inputTokens, 'prompt_tokens counts the cached prefix; the carrier keeps the family\'s subtraction');
        $this->assertSame(24, $response->usage->outputTokens);
        $this->assertSame(8, $response->usage->cacheReadTokens);
        $this->assertNull($response->usage->cacheCreationTokens, 'no cache-creation field exists here and the carrier must not invent one');
        $this->assertSame($response->tokensUsed, $response->usage->totalTokens);
        $this->assertSame($response->costUsd, $response->usage->costUsd);
    }

    public function testCustomUnaryCarrierMirrorsTheFamilyDocument(): void
    {
        $body = '{"choices":[{"message":{"role":"assistant","content":"ok"}}],'
            . '"usage":{"prompt_tokens":75,"completion_tokens":24,"total_tokens":99,'
            . '"prompt_tokens_details":{"cached_tokens":8}}}';
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], $body));
        $provider = new CustomProvider('probe-family', 'https://api.example.com', 'test-model', null, $httpClient, true, true);

        $response = $provider->complete(new CompleteRequest(
            model: 'test-model',
            messages: [new UserMessage('hi')],
        ));

        $this->assertNotNull($response->usage, 'the Custom arm flipped on the same rule as its family');
        $this->assertSame(67, $response->usage->inputTokens);
        $this->assertSame(8, $response->usage->cacheReadTokens);
        $this->assertSame($response->tokensUsed, $response->usage->totalTokens);
        $this->assertSame($response->costUsd, $response->usage->costUsd);
    }

    public function testSglangUnaryCarrierKeepsTheFlatReasoningBucket(): void
    {
        // LIVE-PROBE payload shape (skynet2 sglang, 2026-09-02, byte-copied
        // through UsageWiringTest's SGLANG_PROBE_COLD): the deployment that
        // started this bucket's story — reasoning 25 of completion 24+ is a
        // provider bug the clamp eats, and the flat reasoning key really
        // lands. Kept literal so the carrier pin rides the measured wire.
        $body = '{"id":"8985b0de56a44d82b3fdd6fafa3ec3e8","object":"chat.completion","created":1788360201,'
            . '"model":"deepseek-ai/DeepSeek-V4-Flash-0731",'
            . '"choices":[{"index":0,"message":{"role":"assistant","content":"","reasoning_content":"We need to respond","tool_calls":null},'
            . '"logprobs":null,"finish_reason":"length","matched_stop":null}],'
            . '"usage":{"prompt_tokens":75,"total_tokens":99,"completion_tokens":24,"prompt_tokens_details":null,"reasoning_tokens":25},'
            . '"metadata":{"weight_version":"default"}}';
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], $body));
        $provider = new SglangProvider('https://api.example.com', 'deepseek-ai/DeepSeek-V4-Flash-0731', null, $httpClient);

        $response = $provider->complete(new CompleteRequest(
            model: 'deepseek-ai/DeepSeek-V4-Flash-0731',
            messages: [new UserMessage('What fruit am I thinking of?')],
        ));

        $this->assertNotNull($response->usage);
        $this->assertSame(99, $response->usage->totalTokens);
        $this->assertSame(75, $response->usage->inputTokens);
        $this->assertSame(24, $response->usage->outputTokens);
        $this->assertNull($response->usage->cacheReadTokens, 'this deployment reports prompt_tokens_details null — unreported, never zero');
        $this->assertSame(25, $response->usage->reasoningTokens, 'the §Q6 bucket had nowhere to go before the carrier; now it has');
        $this->assertSame($response->tokensUsed, $response->usage->totalTokens);
    }

    public function testSglangStreamPutsTheTerminalUsageDocumentOnThatChunksCarrierOnly(): void
    {
        $delta = '{"id":"c7a59cdb72e24ebd99d9821ebc0e4b8a","object":"chat.completion.chunk","created":1788360234,"model":"m","choices":[{"index":0,"delta":{"content":"%s"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}';
        $usageChunk = '{"id":"c7a59cdb72e24ebd99d9821ebc0e4b8a","object":"chat.completion.chunk","created":1788360234,"model":"m","choices":[],'
            . '"usage":{"prompt_tokens":60,"total_tokens":72,"completion_tokens":12,"prompt_tokens_details":null,"reasoning_tokens":13}}';
        $sse = sprintf('data: ' . $delta . "\n", 'Hel')
            . sprintf('data: ' . $delta . "\n", 'lo')
            . 'data: ' . $usageChunk . "\n"
            . "data: [DONE]\n";

        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], $sse));
        $provider = new SglangProvider('https://api.example.com', 'm', null, $httpClient);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'm',
            messages: [new UserMessage('hi')],
        )));

        $this->assertCount(3, $chunks);
        $this->assertNull($chunks[0]->usage, 'a content delta carries no document — null, not an empty one');
        $this->assertNull($chunks[1]->usage);
        $this->assertNotNull($chunks[2]->usage, 'the §Q6 terminal chunk bills its full document through the carrier');
        $this->assertSame(60, $chunks[2]->usage->inputTokens);
        $this->assertSame(12, $chunks[2]->usage->outputTokens);
        $this->assertSame(13, $chunks[2]->usage->reasoningTokens);
        $this->assertSame(72, $chunks[2]->usage->totalTokens);
    }

    public function testVertexAnthropicUnaryCarrierCarriesAllFourBuckets(): void
    {
        $provider = $this->vertexUnaryWith(
            ['content' => [['type' => 'text', 'text' => 'Hi']], 'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'cache_read_input_tokens' => 7000,
                'cache_creation_input_tokens' => 900,
            ]],
            'claude-3-sonnet@20240229',
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('hi')],
        ));

        $this->assertNotNull($response->usage, 'the unary Anthropic reply carries its document whole (UNVERIFIED-DOCUMENTED fixture shape, labelled per the parse seam)');
        $this->assertSame(15, $response->usage->totalTokens);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
        $this->assertSame(7000, $response->usage->cacheReadTokens);
        $this->assertSame(900, $response->usage->cacheCreationTokens);
    }

    public function testVertexAnthropicStreamCarriersNameOnlyTheirOwnSideOfTheDocument(): void
    {
        // The double-bill guard, now visible one layer up. Both events carry
        // BOTH-sided documents (review-2's fixture posture); each emitted
        // carrier must still name only its own side's buckets, because
        // Runtime SUMS the pair — a start carrier that reported the stray
        // `output_tokens: 1` would bill one token the delta also bills.
        $provider = $this->vertexStreamWith([
            ['type' => 'message_start', 'message' => ['usage' => [
                'input_tokens' => 12,
                'output_tokens' => 1,
                'cache_read_input_tokens' => 6400,
                'cache_creation_input_tokens' => 500,
            ]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel']],
            ['type' => 'message_delta', 'usage' => ['input_tokens' => 12, 'output_tokens' => 4]],
            ['type' => 'message_stop'],
        ], 'claude-3-sonnet@20240229');

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('hi')],
        )));
        $usageChunks = array_values(array_filter(
            $chunks,
            static fn (CompleteResponse $c): bool => $c->tokensUsed !== 0,
        ));

        $this->assertCount(2, $usageChunks, 'fixture: exactly the two split events bill');
        [$start, $delta] = $usageChunks;

        $this->assertNotNull($start->usage);
        $this->assertSame(12, $start->usage->inputTokens);
        $this->assertNull($start->usage->outputTokens, 'the start carrier owns the input side only — the document\'s stray output_tokens belongs to the delta emit');
        $this->assertSame(6400, $start->usage->cacheReadTokens);
        $this->assertSame(500, $start->usage->cacheCreationTokens);
        $this->assertSame($start->tokensUsed, $start->usage->totalTokens);
        $this->assertSame($start->costUsd, $start->usage->costUsd, 'the carrier is priced per-side exactly like the projection');

        $this->assertNotNull($delta->usage);
        $this->assertSame(4, $delta->usage->outputTokens);
        $this->assertNull($delta->usage->inputTokens, 'and the delta owns the output side only — the restated input stays with the start');
        $this->assertNull($delta->usage->cacheReadTokens);
        $this->assertSame($delta->tokensUsed, $delta->usage->totalTokens);

        $sum = Usage::sum([$start->usage, $delta->usage]);
        $this->assertNotNull($sum);
        $this->assertSame(16, $sum->totalTokens, 'the summed turn equals the pre-fold sum');
        $this->assertSame(12, $sum->inputTokens);
        $this->assertSame(4, $sum->outputTokens);
    }

    public function testVertexGeminiUnaryCarrierSubtractsTheLocallyProvenCache(): void
    {
        $provider = $this->vertexUnaryWith(
            ['candidates' => [['content' => ['parts' => [['text' => 'sure']]]]],
             'usageMetadata' => [
                 'promptTokenCount' => 100,
                 'candidatesTokenCount' => 40,
                 'totalTokenCount' => 140,
                 'cachedContentTokenCount' => 8,
             ]],
            'gemini-1.5-pro-002',
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'gemini-1.5-pro-002',
            messages: [new UserMessage('hi')],
        ));

        $this->assertNotNull($response->usage);
        $this->assertSame(140, $response->usage->totalTokens);
        $this->assertSame(92, $response->usage->inputTokens);
        $this->assertSame(40, $response->usage->outputTokens);
        $this->assertSame(8, $response->usage->cacheReadTokens);
        $this->assertNull($response->usage->cacheCreationTokens);
    }

    public function testVertexGeminiStreamParkedResponseCarriesTheCumulativeDocumentOnce(): void
    {
        $provider = $this->vertexStreamWith([
            ['candidates' => [['content' => ['parts' => [['text' => 'hi']]]]],
             'usageMetadata' => ['promptTokenCount' => 10, 'cachedContentTokenCount' => 10, 'candidatesTokenCount' => 0]],
        ], 'gemini-1.5-pro-002');

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'gemini-1.5-pro-002',
            messages: [new UserMessage('hi')],
        )));

        $this->assertCount(2, $chunks, 'fixture: text delta plus the parked terminal response');
        $this->assertNull($chunks[0]->usage, 'the text delta carries nothing');
        $this->assertSame(10, $chunks[1]->usage?->totalTokens, 'the all-cached turn parks its bill, and now its buckets too');
        $this->assertSame(0, $chunks[1]->usage?->inputTokens, '10 of 10 cached leaves a REPORTED zero fresh input — distinct from unreported');
        $this->assertSame(10, $chunks[1]->usage?->cacheReadTokens);
    }

    public function testEchoKeepsTheCarrierNullBecauseThereIsNoWireToRead(): void
    {
        // The honest null of the family: Echo synthesizes text with no
        // provider behind it, so "the split was not carried" is the truth
        // and any Usage here would be theater.
        $response = (new EchoProvider())->complete(new CompleteRequest(
            model: 'echo',
            messages: [new UserMessage('ping')],
        ));

        $this->assertNull($response->usage);
        $this->assertSame(0, $response->tokensUsed);
    }

    // ---- transport doubles (same shapes as UsageWiringTest's p4s2 harness) --

    /** @param array<string, mixed> $usage */
    private function bedrockUnaryClient(array $usage): \Aws\BedrockRuntime\BedrockRuntimeClient
    {
        $mock = new \Aws\MockHandler();
        $mock->append(new \Aws\Result([
            'output' => ['message' => [
                'role' => 'assistant',
                'content' => [['text' => 'Hello']],
            ]],
            'stopReason' => 'end_turn',
            'usage' => $usage,
        ]));

        return new \Aws\BedrockRuntime\BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $mock,
        ]);
    }

    /**
     * @param array<string, mixed> $usageArray
     * @return array{0: OpenAIProvider, 1: CompleteResponse}
     */
    private function openAiCompleteWith(array $usageArray): array
    {
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);
        $chat->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-e17',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
            'usage' => $usageArray,
        ], MetaInformation::from([])));

        $provider = new OpenAIProvider($client, 'gpt-4o');

        return [$provider, $provider->complete(new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('hi')],
        ))];
    }

    /** @param array<string, mixed> $response */
    private function vertexUnaryWith(array $response, string $model): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: static fn (): array => $response,
        );
    }

    /** @param array<int, array<string, mixed>> $events */
    private function vertexStreamWith(array $events, string $model): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: static fn (): array => [],
            streamer: static function () use ($events): \Generator {
                yield from $events;
            },
        );
    }
}
