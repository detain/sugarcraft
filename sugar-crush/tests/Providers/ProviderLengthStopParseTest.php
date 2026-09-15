<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result as AwsResult;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Chat\CreateStreamedResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Responses\StreamResponse;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * E707 (round 81), the honesty half: every provider that ships a wire stop
 * verdict now READS it into CompleteResponse::$truncated - previously only
 * the Sglang port parsed `finish_reason`, and even its flag was consumed by
 * nothing. Each test below feeds one provider its OWN wire shape (the exact
 * key spellings differ per API: OpenAI-style `finish_reason: "length"`,
 * Anthropic-family `stop_reason: "max_tokens"`, Gemini's screaming
 * `finishReason: "MAX_TOKENS"`, Bedrock's `stopReason` enum) and pins both
 * polarities: the ceiling stop sets the flag, a model-chosen ending does not.
 *
 * The negative arms are not padding. The flag answers only "the wire SAID
 * the output ceiling bit" - a false on `end_turn`/`stop`/`tool_calls` is what
 * keeps the transcript notice from crying wolf on every normal reply, and the
 * deliberate exclusions (Bedrock's context-window stop, the hard transport
 * cut on Sglang, Claude Code's absent key) are pinned here so a future
 * "widening" has to be a decision against a red test, not a quiet drift.
 */
final class ProviderLengthStopParseTest extends TestCase
{
    // =========================================================================
    // OpenAI — batch and stream
    // =========================================================================

    public function testTheOpenAIBatchReadsALengthFinishReasonAsACeilingStop(): void
    {
        $response = $this->openAiBatch('length');

        $this->assertTrue($response->truncated, 'finish_reason "length" is the server saying it ran out of output budget');
        $this->assertSame('the text stops mid', $response->content, 'the partial text still arrives whole');
    }

    public function testTheOpenAIBatchKeepsAModelChosenStopClean(): void
    {
        $this->assertFalse($this->openAiBatch('stop')->truncated);
        $this->assertFalse($this->openAiBatch('tool_calls')->truncated);
    }

    public function testTheOpenAIStreamRidesTheFlagOnTheChunkThatClosesTheChoice(): void
    {
        $chunks = $this->openAiStream([
            ['index' => 0, 'delta' => ['content' => 'Hel']],
            ['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => 'length'],
        ]);

        $this->assertFalse($chunks[0]->truncated, 'an open chunk says nothing about endings');
        $this->assertTrue($chunks[1]->truncated, 'the closing chunk carries the verdict beside its last delta');
        $this->assertSame('Hello', $chunks[0]->content . $chunks[1]->content, 'streamed text is untouched by the flag');
    }

    public function testTheOpenAIStreamKeepsACleanFinalChoiceFlagFree(): void
    {
        $chunks = $this->openAiStream([
            ['index' => 0, 'delta' => ['content' => 'done'], 'finish_reason' => 'stop'],
        ]);

        foreach ($chunks as $chunk) {
            $this->assertFalse($chunk->truncated, 'end_turn on this wire is the model finishing, not the ceiling');
        }
    }

    // =========================================================================
    // Custom (vLLM / SGLang behind an OpenAI-compatible URL) — batch and stream
    // =========================================================================

    public function testTheCustomBatchReadsLengthAndAbortAsCeilingStops(): void
    {
        foreach (['length', 'abort'] as $reason) {
            $response = $this->customBatch($reason);

            $this->assertTrue(
                $response->truncated,
                '"abort" joins "length" here for the same reason it does on the Sglang port: an engine-side cutoff, not a model choice',
            );
        }
    }

    public function testTheCustomBatchKeepsToolCallsClean(): void
    {
        $this->assertFalse($this->customBatch('tool_calls')->truncated);
        $this->assertFalse($this->customBatch(null)->truncated, 'a server that never names an ending has not named a ceiling');
    }

    public function testTheCustomStreamRidesTheFlagOnADeltaThatAlsoCloses(): void
    {
        $sse = "data: " . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'partial'], 'finish_reason' => 'length']]]) . "\n\n"
            . "data: [DONE]\n\n";

        $chunks = $this->customStream($sse);

        $this->assertCount(1, $chunks);
        $this->assertSame('partial', $chunks[0]->content);
        $this->assertTrue($chunks[0]->truncated);
    }

    public function testTheCustomStreamYieldsAFlagOnlyFrameWhenTheStopCarriesNoDelta(): void
    {
        // The Sglang law E707 ported to this wire: the truncating end can
        // arrive on a frame carrying no content of its own. Swallowing that
        // frame is what made the old stream indistinguishable from a clean one.
        $sse = "data: " . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'Hi']]]]) . "\n\n"
            . "data: " . json_encode(['choices' => [['index' => 0, 'finish_reason' => 'length']]]) . "\n\n"
            . "data: [DONE]\n\n";

        $chunks = $this->customStream($sse);

        $this->assertCount(2, $chunks, 'the delta-less stop becomes its own flag-only frame');
        $this->assertSame('Hi', $chunks[0]->content);
        $this->assertFalse($chunks[0]->truncated);
        $this->assertSame('', $chunks[1]->content, 'a flag frame repeats no text');
        $this->assertTrue($chunks[1]->truncated);
    }

    // =========================================================================
    // Vertex — Anthropic arm (rawPredict + SSE) and Gemini arm
    // =========================================================================

    public function testTheVertexAnthropicBatchReadsMaxTokensAsACeilingStop(): void
    {
        $provider = $this->vertexPredictor([
            'content' => [['type' => 'text', 'text' => 'cut off early']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 9],
        ]);

        $response = $provider->complete(new CompleteRequest(model: 'claude-3-sonnet@20240229', messages: [new UserMessage('go')]));

        $this->assertTrue($response->truncated, 'stop_reason "max_tokens" is the Anthropic-family wire spelling of the output ceiling');
        $this->assertSame('cut off early', $response->content);

        $clean = $this->vertexPredictor([
            'content' => [['type' => 'text', 'text' => 'whole']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ])->complete(new CompleteRequest(model: 'claude-3-sonnet@20240229', messages: [new UserMessage('go')]));

        $this->assertFalse($clean->truncated, 'end_turn is the model finishing its thought - the notice must not fire on it');
    }

    public function testTheVertexGeminiBatchReadsTheScreamingFinishReason(): void
    {
        $provider = $this->vertexPredictor([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'half an answer']]],
                'finishReason' => 'MAX_TOKENS',
            ]],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 4, 'totalTokenCount' => 7],
        ]);

        $response = $provider->complete(new CompleteRequest(model: 'gemini-1.5-pro-002', messages: [new UserMessage('go')]));

        $this->assertTrue($response->truncated, 'MAX_TOKENS is the Gemini arm screaming the same verdict Anthropic spells in lower case');
        $this->assertSame('half an answer', $response->content);

        $clean = $this->vertexPredictor([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'whole']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 1, 'totalTokenCount' => 4],
        ])->complete(new CompleteRequest(model: 'gemini-1.5-pro-002', messages: [new UserMessage('go')]));

        $this->assertFalse($clean->truncated, 'STOP is the model finishing its thought - the notice must not fire on it');
    }

    public function testTheVertexAnthropicStreamFlagsTheTerminalDeltaAndAKnowsTheDifference(): void
    {
        // Two polarities of the SAME terminal event, one stream each: the
        // usage frame the stop rides on, and its clean counterpart.
        $ceiling = $this->vertexAnthropicStream([
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'max_tokens'], 'usage' => ['output_tokens' => 7]],
        ]);

        $flagged = array_values(array_filter($ceiling, static fn (CompleteResponse $c): bool => $c->truncated));
        $this->assertCount(1, $flagged, 'the verdict arrives exactly once, on the terminal frame');
        $this->assertSame(7, $flagged[0]->tokensUsed, 'the ceiling stop rides the SAME frame the bill lands on - both survive together');

        $clean = $this->vertexAnthropicStream([
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'done']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 4]],
        ]);

        foreach ($clean as $chunk) {
            $this->assertFalse($chunk->truncated);
        }
    }

    public function testTheVertexAnthropicStreamStillSpeaksWhenTheTerminalDeltaCarriesNoBill(): void
    {
        // A ceiling stop whose closing event reports zero billable output used
        // to parse to null - the verdict was thrown away with the frame. Now
        // it degrades to a flag-only frame instead of silence.
        $chunks = $this->vertexAnthropicStream([
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'x']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'max_tokens']],
        ]);

        $this->assertCount(2, $chunks, 'the stop with no usage must yield a frame, not the null it used to swallow');
        $this->assertTrue($chunks[1]->truncated);
        $this->assertSame('', $chunks[1]->content);
        $this->assertSame(0, $chunks[1]->tokensUsed, 'a flag frame bills nothing');
    }

    public function testTheVertexGeminiStreamParksAVerdictFromATextlessClosingCandidate(): void
    {
        $provider = VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'gemini-1.5-pro-002',
            predictor: static fn (): array => [],
            streamer: static function (): \Generator {
                yield ['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]]];
                yield [
                    'candidates' => [['finishReason' => 'MAX_TOKENS']],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 8, 'totalTokenCount' => 18],
                ];
            },
        );

        $chunks = iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'gemini-1.5-pro-002', messages: [new UserMessage('go')]),
        ));

        // Order is load-bearing: the parked flag frame rides BEFORE the
        // terminal usage emit (the same before-the-bill law as Sglang's §Q7).
        $this->assertSame('Hel', $chunks[0]->content);
        $this->assertFalse($chunks[0]->truncated);
        $this->assertSame('', $chunks[1]->content);
        $this->assertTrue($chunks[1]->truncated, 'a closing candidate with no text still delivers its verdict');
        $this->assertSame(18, $chunks[2]->tokensUsed, 'usage stays the stream-last event');
        $this->assertFalse($chunks[2]->truncated, 'the flag rides exactly one frame');
    }

    // =========================================================================
    // Bedrock — Converse and ConverseStream
    // =========================================================================

    public function testTheBedrockBatchReadsMaxTokensButNotTheContextWindowStop(): void
    {
        $this->assertTrue($this->bedrockBatch('max_tokens')->truncated);
        $this->assertFalse($this->bedrockBatch('end_turn')->truncated);
        $this->assertFalse(
            $this->bedrockBatch('model_context_window_exceeded')->truncated,
            'the context-window stop means the INPUT overflowed - raising maxOutputTokens cannot fix it, so it is not the ceiling this flag answers for',
        );
    }

    public function testTheBedrockStreamFlagsTheMessageStopEvent(): void
    {
        $handler = new AwsMockHandler();
        $handler->append(new AwsResult(['stream' => new \ArrayIterator([
            ['messageStart' => ['role' => 'assistant']],
            ['contentBlockDelta' => ['delta' => ['text' => 'cut'], 'contentBlockIndex' => 0]],
            ['messageStop' => ['stopReason' => 'max_tokens']],
            ['metadata' => ['usage' => ['inputTokens' => 6, 'outputTokens' => 9]]],
        ])]));

        $provider = new \SugarCraft\Crush\Providers\BedrockProvider(
            new BedrockRuntimeClient([
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
                'handler' => $handler,
            ]),
        );

        $chunks = iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'anthropic.claude-sonnet-4-6', messages: [new UserMessage('hi')]),
        ));

        $flagged = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->truncated));
        $this->assertCount(1, $flagged, 'the stop verdict attaches exactly to the messageStop event');
        $this->assertSame(0, $flagged[0]->tokensUsed, 'usage lands on its own metadata event, not the stop frame');

        $this->assertFalse($chunks[0]->truncated);
    }

    // =========================================================================
    // Claude Code — the shell-out envelope and the relayed SSE frame
    // =========================================================================

    public function testTheClaudeCodeBatchFlagsOnlyAnExplicitMaxTokensStop(): void
    {
        $stopped = $this->claudeJson('{"result":"cut","stop_reason":"max_tokens","usage":{"total_tokens":9}}');
        $this->assertTrue($stopped->truncated);

        $clean = $this->claudeJson('{"result":"done","stop_reason":"end_turn","usage":{"total_tokens":4}}');
        $this->assertFalse($clean->truncated);

        $mute = $this->claudeJson('{"result":"done","usage":{"total_tokens":4}}');
        $this->assertFalse($mute->truncated, 'a CLI build that never names an ending has not named a ceiling - absent is false, never a guess');
    }

    public function testTheClaudeCodeStreamFlagsTheRelayedMessageDeltaFrame(): void
    {
        $stop = $this->claudeChunk(['event' => ['delta' => ['type' => 'message_delta', 'stop_reason' => 'max_tokens']]]);
        $this->assertTrue($stop->truncated);
        $this->assertSame('', $stop->content);

        $text = $this->claudeChunk(['event' => ['delta' => ['type' => 'text_delta', 'text' => 'Hel']]]);
        $this->assertFalse($text->truncated);
        $this->assertSame('Hel', $text->content);

        $clean = $this->claudeChunk(['event' => ['delta' => ['type' => 'message_delta', 'stop_reason' => 'end_turn']]]);
        $this->assertFalse($clean->truncated);
    }

    // =========================================================================
    // Sglang — the two new stream legs alongside the pre-existing flush law
    // =========================================================================

    public function testTheSglangStreamSpeaksForALengthStopWithNothingLeftToFlush(): void
    {
        $chunks = $this->sglangStream(
            'data: {"choices":[{"delta":{"content":"par"}}]}' . "\n"
            . 'data: {"choices":[{"delta":{"content":"tial"},"finish_reason":"length"}]}' . "\n"
            . 'data: [DONE]' . "\n",
        );

        $flagged = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->truncated));
        $this->assertCount(1, $flagged, 'the pre-existing flush frame only exists WITH buffered fragments; this leg is the stop with none');
        $this->assertSame('', $flagged[0]->content, 'a flag-only frame repeats no text');
        $this->assertSame(0, $flagged[0]->tokensUsed, 'and bills nothing - usage stays the stream-last event');
        $this->assertSame('partial', $chunks[0]->content . $chunks[1]->content);
    }

    public function testTheSglangStreamDoesNotCallATransportDeathACeiling(): void
    {
        // No finish_reason frame at all: the stream dies mid-flight. The
        // truncation GUARD still classifies it for the tool-call flush (that
        // pre-existing law is untouched), but the E707 flag answers only what
        // the wire SAID, so no flag frame may appear.
        $chunks = $this->sglangStream(
            'data: {"choices":[{"delta":{"content":"par"}}]}' . "\n",
        );

        foreach ($chunks as $chunk) {
            $this->assertFalse($chunk->truncated, 'the flag is a stop-reason report, never a stream-integrity report');
        }
    }

    // =========================================================================
    // harnesses — one small double per wire, each mirroring its own suite's
    // established shape (renamed to stay clear of the sibling suites' helpers)
    // =========================================================================

    private function openAiBatch(?string $finishReason): CompleteResponse
    {
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);

        $choice = [
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'the text stops mid'],
        ];
        if ($finishReason !== null) {
            $choice['finish_reason'] = $finishReason;
        }

        $chat->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-e707',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [$choice],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 8, 'total_tokens' => 13],
        ], MetaInformation::from([])));

        return (new OpenAIProvider($client, 'gpt-4o'))
            ->complete(new CompleteRequest(model: 'gpt-4o', messages: [new UserMessage('go')]));
    }

    /** @param list<array<string, mixed>> $choices @return list<CompleteResponse> */
    private function openAiStream(array $choices): array
    {
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);

        $body = '';
        foreach ($choices as $choice) {
            $body .= 'data: ' . json_encode([
                'id' => 'chatcmpl-e707',
                'object' => 'chat.completion.chunk',
                'created' => 1,
                'model' => 'gpt-4o',
                'choices' => [$choice],
            ]) . "\n\n";
        }
        $body .= "data: [DONE]\n\n";

        $chat->method('createStreamed')->willReturn(new StreamResponse(
            CreateStreamedResponse::class,
            new Response(200, [], $body),
        ));

        return iterator_to_array((new OpenAIProvider($client, 'gpt-4o'))
            ->completeStream(new CompleteRequest(model: 'gpt-4o', messages: [new UserMessage('go')])));
    }

    private function customBatch(?string $finishReason): CompleteResponse
    {
        $choice = ['message' => ['content' => 'text']];
        if ($finishReason !== null) {
            $choice['finish_reason'] = $finishReason;
        }

        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], json_encode([
                    'choices' => [$choice],
                    'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
                ])),
            ])),
            'base_uri' => 'https://api.example.com',
        ]);

        return $this->customProvider($client)
            ->complete(new CompleteRequest(model: 'gpt-4', messages: [new UserMessage('go')]));
    }

    /** @return list<CompleteResponse> */
    private function customStream(string $sse): array
    {
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $sse)])),
            'base_uri' => 'https://api.example.com',
        ]);

        return iterator_to_array($this->customProvider($client)
            ->completeStream(new CompleteRequest(model: 'gpt-4', messages: [new UserMessage('go')])));
    }

    private function customProvider(Client $client): CustomProvider
    {
        return new CustomProvider('custom', 'https://api.example.com', 'gpt-4', null, $client, true, true);
    }

    private function vertexPredictor(array $return): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'claude-3-sonnet@20240229',
            predictor: static fn (): array => $return,
        );
    }

    /** @param list<array<string, mixed>> $events @return list<CompleteResponse> */
    private function vertexAnthropicStream(array $events): array
    {
        $provider = VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'claude-3-sonnet@20240229',
            predictor: static fn (): array => [],
            streamer: static function () use ($events): \Generator {
                yield from $events;
            },
        );

        return iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'claude-3-sonnet@20240229', messages: [new UserMessage('go')]),
        ));
    }

    private function bedrockBatch(string $stopReason): CompleteResponse
    {
        $handler = new AwsMockHandler();
        $handler->append(new AwsResult([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
            'stopReason' => $stopReason,
            'usage' => ['inputTokens' => 3, 'outputTokens' => 4],
        ]));

        return (new \SugarCraft\Crush\Providers\BedrockProvider(
            new BedrockRuntimeClient([
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
                'handler' => $handler,
            ]),
        ))->complete(new CompleteRequest(model: 'anthropic.claude-sonnet-4-6', messages: [new UserMessage('hi')]));
    }

    private function claudeJson(string $output): CompleteResponse
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $method = new \ReflectionMethod($provider, 'parseJsonResponse');

        return $method->invoke($provider, $output);
    }

    /** @param array<string, mixed> $data */
    private function claudeChunk(array $data): CompleteResponse
    {
        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());
        $method = new \ReflectionMethod($provider, 'parseChunk');

        return $method->invoke($provider, $data);
    }

    /** @return list<CompleteResponse> */
    private function sglangStream(string $sse): array
    {
        $client = $this->createMock(Client::class);

        $body = $this->getMockBuilder(\GuzzleHttp\Psr7\Stream::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['eof', 'read'])
            ->getMock();
        $body->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $body->method('read')->willReturn($sse);

        $client->method('post')->willReturn(new Response(200, [], $body));

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        return iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('go')]),
        ));
    }
}
