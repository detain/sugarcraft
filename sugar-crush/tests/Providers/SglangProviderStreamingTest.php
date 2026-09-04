<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Regression coverage for §12 D2 of crush_feat.md: `SglangProvider::parseChunk()`
 * used to hardcode `toolCalls: null` on every chunk, so `completeStream()`
 * could never deliver a tool call - only `complete()` (non-streaming) could.
 * These tests assert the delta.tool_calls[] fragment accumulation added to
 * close that gap; every "assembled" assertion below would fail against the
 * old parseChunk(), which never read delta.tool_calls at all.
 */
final class SglangProviderStreamingTest extends TestCase
{
    // -------------------------------------------------------------------------
    // parseChunk() accumulates tool_calls fragments across successive chunks
    // and only emits assembled ToolCall objects once finish_reason arrives.
    // -------------------------------------------------------------------------

    public function testParseChunkAccumulatesToolCallFragmentsAcrossChunks(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];

        // Chunk 1: id + name, empty arguments fragment - the shape SGLang's
        // OpenAI-compatible streaming route sends for the first delta of a
        // tool call.
        $chunk1 = [
            'choices' => [[
                'delta' => [
                    'tool_calls' => [
                        ['index' => 0, 'id' => 'call_abc123', 'function' => ['name' => 'get_weather', 'arguments' => '']],
                    ],
                ],
            ]],
        ];
        $result1 = $method->invokeArgs($provider, [$chunk1, &$buffer]);
        $this->assertNull($result1->toolCalls, 'tool call is incomplete mid-stream, must not fire early');

        // Chunk 2: argument fragment, no id/name (only sent once per call).
        $chunk2 = [
            'choices' => [[
                'delta' => [
                    'tool_calls' => [
                        ['index' => 0, 'function' => ['arguments' => '{"city":']],
                    ],
                ],
            ]],
        ];
        $result2 = $method->invokeArgs($provider, [$chunk2, &$buffer]);
        $this->assertNull($result2->toolCalls);

        // Chunk 3: final argument fragment + finish_reason - this is where
        // accumulated fragments must be assembled and emitted.
        $chunk3 = [
            'choices' => [[
                'delta' => [
                    'tool_calls' => [
                        ['index' => 0, 'function' => ['arguments' => '"Tokyo"}']],
                    ],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ];
        $result3 = $method->invokeArgs($provider, [$chunk3, &$buffer]);

        $this->assertNotNull($result3->toolCalls);
        $this->assertCount(1, $result3->toolCalls);
        $this->assertInstanceOf(ToolCall::class, $result3->toolCalls[0]);
        $this->assertSame('call_abc123', $result3->toolCalls[0]->id());
        $this->assertSame('get_weather', $result3->toolCalls[0]->name());
        $this->assertSame(['city' => 'Tokyo'], $result3->toolCalls[0]->arguments());

        // Buffer must be drained after assembly so a second tool call in the
        // same stream doesn't inherit stale fragments.
        $this->assertSame([], $buffer);
    }

    public function testParseChunkHandlesMultipleConcurrentToolCallIndices(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];

        $chunk1 = [
            'choices' => [[
                'delta' => [
                    'tool_calls' => [
                        ['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '{}']],
                        ['index' => 1, 'id' => 'call_2', 'function' => ['name' => 'get_time', 'arguments' => '{}']],
                    ],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ];
        $result = $method->invokeArgs($provider, [$chunk1, &$buffer]);

        $this->assertNotNull($result->toolCalls);
        $this->assertCount(2, $result->toolCalls);
        $this->assertSame('call_1', $result->toolCalls[0]->id());
        $this->assertSame('get_weather', $result->toolCalls[0]->name());
        $this->assertSame('call_2', $result->toolCalls[1]->id());
        $this->assertSame('get_time', $result->toolCalls[1]->name());
    }

    public function testParseChunkStillReturnsContentWhenNoToolCallsPresent(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];
        $result = $method->invokeArgs($provider, [
            ['choices' => [['delta' => ['content' => 'Hello']]]],
            &$buffer,
        ]);

        $this->assertSame('Hello', $result->content);
        $this->assertNull($result->toolCalls);
    }

    public function testParseChunkPassesThroughReasoningContent(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];
        $result = $method->invokeArgs($provider, [
            ['choices' => [['delta' => ['reasoning_content' => 'thinking...']]]],
            &$buffer,
        ]);

        $this->assertSame('thinking...', $result->reasoning);
    }

    // -------------------------------------------------------------------------
    // End-to-end: completeStream() over a mocked SSE body with a tool call
    // split across chunks - the same code path Runtime::runStreaming() drives
    // in production (src/Runtime.php merges $response->toolCalls across every
    // yielded chunk). This is the real call chain: bin/sugarcrush ->
    // EngineBackend -> Runtime::run() -> runStreaming() ->
    // ProviderInterface::completeStream() -> SglangProvider::parseChunk().
    // -------------------------------------------------------------------------

    public function testCompleteStreamDeliversAssembledToolCallEndToEnd(): void
    {
        $httpClient = $this->createMock(Client::class);

        $sse = 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_xyz","function":{"name":"search","arguments":""}}]}}]}' . "\n"
            . 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"q\":\"php\"}"}}]},"finish_reason":"tool_calls"}]}' . "\n"
            . 'data: [DONE]' . "\n";

        $responseBody = $this->getMockBuilder(Stream::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['eof', 'read'])
            ->getMock();
        $responseBody->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $responseBody->method('read')->willReturn($sse);

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())->method('post')->willReturn($response);

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
        $request = new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('search php')]);

        $chunks = iterator_to_array($provider->completeStream($request));

        $toolCallChunks = array_values(array_filter($chunks, fn ($c) => $c->toolCalls !== null));
        $this->assertNotEmpty($toolCallChunks, 'completeStream() must deliver at least one chunk carrying assembled tool calls');
        $this->assertCount(1, $toolCallChunks[0]->toolCalls);
        $this->assertSame('call_xyz', $toolCallChunks[0]->toolCalls[0]->id());
        $this->assertSame('search', $toolCallChunks[0]->toolCalls[0]->name());
        $this->assertSame(['q' => 'php'], $toolCallChunks[0]->toolCalls[0]->arguments());
    }

    /**
     * The SAME stream, but replayed from bytes a real server actually sent.
     *
     * Every `data:` line below is verbatim from a live capture: POST
     * `/v1/chat/completions` to skynet2 on 2026-08-20 with
     * `model: deepseek-ai/DeepSeek-V4-Flash-0731`, `stream: true`,
     * `reasoning_effort: "max"`, one `get_weather` tool, and the prompt "What
     * is the weather in Paris and in Tokyo? Call the tool once per city." 21
     * `data:` frames came back, including `[DONE]`.
     *
     * What makes it worth having alongside the hand-written stream above:
     *
     * 1. TWO PARALLEL CALLS at `index` 0 and 1, interleaved, each with its own
     *    `call_…` id, which is the shape the hand-written test cannot exercise
     *    with one index.
     * 2. Every unused delta key is explicitly `null` rather than absent -
     *    `"content":null`, `"tool_calls":null`, `"name":null`, `"id":null`.
     *    That is what the `?? []` in resolveStreamedToolCalls()'s foreach is
     *    for: a reassembly that decided what to iterate with
     *    `array_key_exists` would try to iterate null on every content-only
     *    chunk. The hand-written stream above omits those keys entirely and so
     *    cannot exercise the difference.
     * 3. The FINAL frame carries `finish_reason: "tool_calls"` with a delta of
     *    `{"reasoning_content":null}` and NO `tool_calls` key at all - so the
     *    flush must be driven by finish_reason, never by the last fragment.
     * 4. Leading prose ("I'll get the weather for both cities.") arrives in
     *    `content` BEFORE the calls, so content and tool calls coexist in one
     *    stream.
     *
     * DOMAIN NOTE: this is the DeepSeek-V4 deployment's streaming shape. It is
     * structured OpenAI `tool_calls`, not MiniMax's XML envelope - the XML
     * fallback path is covered by
     * {@see ToolCallParser\MinimaxXmlFallbackToolCallParserTest} and by
     * SglangProviderTruncationGuardTest, and remains reachable.
     */
    public function testCompleteStreamAccumulatesTwoParallelToolCallsFromACapturedLiveStream(): void
    {
        $httpClient = $this->createMock(Client::class);

        $sse = ''
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195762,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":null,"role":"assistant","content":""},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195762,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":"The"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195762,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":" user wants weather"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195762,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":" in Paris and Tokyo"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195762,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":". The"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195762,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":" instructions say to call the tool"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195763,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":" once per city, and"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195763,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":" these are independent calls, so"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195763,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":" I should make them in the"},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195763,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":" same block."},"logprobs":null,"finish_reason":null,"matched_stop":null}]}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195763,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":"I","reasoning_content":null,"tool_calls":null},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195763,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":"\'ll get","reasoning_content":null,"tool_calls":null},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195763,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":" the weather for both cities.\\n\\n","reasoning_content":null,"tool_calls":null},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195764,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":null,"reasoning_content":null,"tool_calls":[{"id":"call_361011ce08d84cc6971c6d8a","index":0,"type":"function","function":{"name":"get_weather","arguments":""}}]},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195764,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":null,"reasoning_content":null,"tool_calls":[{"id":null,"index":0,"type":"function","function":{"name":null,"arguments":"{"}}]},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195764,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":null,"reasoning_content":null,"tool_calls":[{"id":null,"index":0,"type":"function","function":{"name":null,"arguments":"\\"city\\": \\"Paris\\"}"}}]},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195764,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":null,"reasoning_content":null,"tool_calls":[{"id":"call_3aaf5236eecc4614aba70f6b","index":1,"type":"function","function":{"name":"get_weather","arguments":""}}]},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195764,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":null,"reasoning_content":null,"tool_calls":[{"id":null,"index":1,"type":"function","function":{"name":null,"arguments":"{"}}]},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195765,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"role":null,"content":null,"reasoning_content":null,"tool_calls":[{"id":null,"index":1,"type":"function","function":{"name":null,"arguments":"\\"city\\": \\"Tokyo\\"}"}}]},"logprobs":null,"finish_reason":null,"matched_stop":null}],"usage":null}' . "\n"
            . 'data: {"id":"669d24a3423f4012bece9b51f2028500","object":"chat.completion.chunk","created":1787195765,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"delta":{"reasoning_content":null},"logprobs":null,"finish_reason":"tool_calls","matched_stop":1}]}' . "\n"
            . 'data: [DONE]' . "\n";

        $responseBody = $this->getMockBuilder(Stream::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['eof', 'read'])
            ->getMock();
        $responseBody->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $responseBody->method('read')->willReturn($sse);

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())->method('post')->willReturn($response);

        $model = 'deepseek-ai/DeepSeek-V4-Flash-0731';
        $provider = new SglangProvider('https://api.example.com', $model, null, $httpClient);
        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: $model,
            messages: [new UserMessage('What is the weather in Paris and in Tokyo? Call the tool once per city.')],
        )));

        $withCalls = array_values(array_filter($chunks, static fn ($c) => $c->toolCalls !== null));
        $this->assertCount(1, $withCalls, 'the buffer must flush exactly once, on finish_reason');

        $calls = array_values($withCalls[0]->toolCalls);
        $this->assertCount(2, $calls, 'both parallel calls must survive accumulation');

        // Indices kept distinct: same tool name, different arguments, different
        // ids. If the two indices had been merged, this is where it shows -
        // one call with "Tokyo" overwriting "Paris".
        $this->assertSame(['get_weather', 'get_weather'], array_map(static fn (ToolCall $c) => $c->name(), $calls));
        $this->assertSame(
            [['city' => 'Paris'], ['city' => 'Tokyo']],
            array_map(static fn (ToolCall $c) => $c->arguments(), $calls),
        );
        $this->assertSame('call_361011ce08d84cc6971c6d8a', $calls[0]->id());
        $this->assertSame('call_3aaf5236eecc4614aba70f6b', $calls[1]->id());
        $this->assertNotSame($calls[0]->id(), $calls[1]->id());

        // The prose that preceded the calls is still delivered as content, on
        // its own chunks - a stream is not "either text or tools".
        $text = implode('', array_map(static fn ($c) => $c->content, $chunks));
        $this->assertSame("I'll get the weather for both cities.\n\n", $text);
    }

    // -------------------------------------------------------------------------
    // §Q6 (qwen.md) — streamed usage revival. These four cases live beside the
    // tool-call cases above because they extend the SAME generator loop:
    // the request arm now asks for usage (`stream_options.include_usage`,
    // E-30), and the zero-choice terminal usage chunk that flag produces —
    // which the `delta` gate used to drop (E-27/E-55) — now surfaces as a
    // final usage-carrying chunk. DESIGN, recorded because the shape is a
    // judgement call: the streamed result carries the usage through the
    // EXISTING `tokensUsed`/`costUsd` fields of a terminal `CompleteResponse`,
    // the same channel Vertex's `message_start`/`message_delta` pair already
    // bills through and the only one `Runtime::runStreaming()` reads
    // (src/Runtime.php folds every chunk via `Usage::reported(
    // $response->tokensUsed, $response->costUsd)` into `Usage::sum()`).
    // Widening `CompleteResponse` with a full `Usage` carrier - the step that
    // would lift the bucket fields, incl. reasoning_tokens, above the
    // provider seam - is the later seam Usage.php's class docblock already
    // declares; see its "The split now lives here" section. The fixture
    // below is a verbatim live capture (§13 cat.8); the SYNTHETIC streams are
    // hand-written shapes the capture does not cover, labelled per case.
    // -------------------------------------------------------------------------

    /** Pins §Q6 part 1: `stream_options` rides the STREAM body, never the batch one. */
    public function testStreamOptionsAreRequestedOnTheStreamArmAndNeverOnBatch(): void
    {
        $captured = [];
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturnCallback(
            static function (string $uri, array $options) use (&$captured): Response {
                $captured[] = ['uri' => $uri, 'json' => $options['json']];

                if (isset($options['json']['stream'])) {
                    // SYNTHETIC body: one delta, then the usage chunk shape E-30
                    // measures (`choices: []` beside the usage document), then
                    // the sentinel. Nothing here is a captured response.
                    return new Response(200, [], 'data: {"choices":[{"delta":{"content":"ok"}}]}' . "\n"
                        . 'data: {"choices":[],"usage":{"total_tokens":5}}' . "\n"
                        . "data: [DONE]\n");
                }

                // The batch leg answers with a batch-shaped body.
                return new Response(200, [], '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":5}}');
            }
        );
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);
        $request = new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('Hi')]);

        iterator_to_array($provider->completeStream($request));
        $provider->complete($request);

        $this->assertCount(2, $captured, 'the stream leg then the batch leg each post once');
        $this->assertSame('chat/completions', $captured[0]['uri']);
        $this->assertTrue($captured[0]['json']['stream'], 'the stream arm still sets stream:true');
        $this->assertSame(
            ['include_usage' => true],
            $captured[0]['json']['stream_options'],
            '§Q6: the stream arm asks for the terminal usage chunk (E-30) - emitted alongside stream:true'
        );
        $this->assertArrayNotHasKey('stream', $captured[1]['json'], 'the batch body never claims to stream');
        $this->assertArrayNotHasKey('stream_options', $captured[1]['json'], 'stream_options is stream-only - the batch body stays byte-identical to pre-§Q6');
    }

    /**
     * Pins §Q6 part 2: the zero-choice terminal usage chunk yields a final
     * usage-carrying chunk instead of being dropped, and everything before it
     * rides through untouched. SYNTHETIC stream (two prose deltas + the E-30
     * shape) - the live capture exercises the same path case 4 below.
     */
    public function testZeroChoiceUsageChunkBecomesTerminalUsageBearingFinalChunk(): void
    {
        $usageChunk = '{"choices":[],"usage":{"prompt_tokens":60,"total_tokens":72,"completion_tokens":12,"prompt_tokens_details":null,"reasoning_tokens":13}}';
        $sse = 'data: {"choices":[{"delta":{"content":"Hel"}}]}' . "\n"
            . 'data: {"choices":[{"delta":{"content":"lo"}}]}' . "\n"
            . 'data: ' . $usageChunk . "\n"
            . "data: [DONE]\n";

        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], $sse));
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('hi')],
        )));

        $this->assertCount(3, $chunks, 'the usage chunk now yields exactly one final chunk; [DONE] still terminates and yields nothing');
        $this->assertSame(
            ['Hel', 'lo', ''],
            array_map(static fn (CompleteResponse $c): string => $c->content, $chunks),
            'the deltas ride through in wire order, and the usage chunk carries no content to double-bill the transcript'
        );
        $this->assertSame(
            [0, 0, 72],
            array_map(static fn (CompleteResponse $c): int => $c->tokensUsed, $chunks),
            'the stream bills its total exactly once, on the terminal chunk - per-delta chunks stay zero'
        );
        $this->assertSame(0.0, $chunks[2]->costUsd, 'self-hosted: cost stays the structural 0.0 (E-55 pricing follow-up)');
        $this->assertNull($chunks[2]->toolCalls);
        $this->assertNull($chunks[2]->reasoning);

        // §Q6 part 3's streamed leg: the terminal document above, parsed at the
        // same public seam the terminal-chunk handler uses, lands reasoning
        // (E-31, flat key) in Usage's bucket — tokensUsed alone cannot carry it.
        $terminalUsageDocument = json_decode(substr($usageChunk, strpos($usageChunk, '{"choices":[],"usage":')), true)['usage'];
        $this->assertSame(
            13,
            $provider->parseUsage($terminalUsageDocument)->reasoningTokens,
            'reasoning_tokens survives the streamed usage document through the shared parseUsage seam'
        );
    }

    /**
     * The anti-phantom half of the §Q6 gate change: zero-choice lines that
     * report NO usage document must still yield nothing — the review-5
     * finding 7 phantom-empty-chunk is only tolerated for the one line that
     * actually bills. SYNTHETIC shapes (keepalive without usage; explicit
     * "usage":null, which the live DeepSeek capture emits mid-stream).
     */
    public function testZeroChoiceLinesWithoutUsageNeverYieldAPhantomChunk(): void
    {
        $sse = 'data: {"choices":[{"delta":{"content":"Hi"}}]}' . "\n"
            . 'data: {"id":"k1","choices":[]}' . "\n"
            . 'data: {"id":"k2","choices":[],"usage":null}' . "\n"
            . "data: [DONE]\n";

        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], $sse));
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('hi')],
        )));

        $this->assertCount(1, $chunks, 'a zero-choice line that reports no usage document still yields NOTHING - §Q6 accepts usage, not every empty-choices frame');
        $this->assertSame('Hi', $chunks[0]->content);
        $this->assertSame(0, $chunks[0]->tokensUsed);
    }

    /**
     * The integration-flavoured Done-when of §Q6, on bytes a real server
     * actually sent. The fixture `tests/fixtures/qwen-usage-stream.txt` is a
     * verbatim live capture (§13 cat.8 — never reconstruct it): POST
     * `/v1/chat/completions` to skynet2 on 2026-09-04, model
     * `Qwen/Qwen3.8-Flash-Next`, `stream: true` + `stream_options:
     * {include_usage: true}`, one prompt line, max_tokens 64, default effort.
     * 2953 bytes, sha256 4df01cc2816b62a4395bc03fdec5512337981c72ae657da449cd1c03566e9c30.
     * 12 `data:` frames: 10 deltas, then the zero-choice terminal usage line
     * (fixture line 21: "prompt_tokens":57,"total_tokens":85,
     * "completion_tokens":28,...,"reasoning_tokens":25), then [DONE].
     */
    public function testCompleteStreamSurfacesUsageFromTheCapturedLiveStreamFixture(): void
    {
        $fixture = (string) file_get_contents(__DIR__ . '/../fixtures/qwen-usage-stream.txt');

        $captured = [];
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturnCallback(
            static function (string $uri, array $options) use (&$captured, $fixture): Response {
                $captured = ['uri' => $uri, 'json' => $options['json']];

                return new Response(200, [], $fixture);
            }
        );
        $provider = new SglangProvider('https://skynet2.interserver.net/v1', 'Qwen/Qwen3.8-Flash-Next', null, $httpClient);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'Qwen/Qwen3.8-Flash-Next',
            messages: [new UserMessage('Reply with exactly: OK')],
        )));

        $this->assertSame(['include_usage' => true], $captured['json']['stream_options'], 'the request arm that produced this capture is the one the provider now sends');
        $this->assertCount(11, $chunks, 'ten captured deltas plus ONE terminal usage chunk - [DONE] inert as ever');
        $this->assertSame("\n\nOK", implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)), 'the captured reply assembles unchanged');

        $final = $chunks[10];
        $this->assertSame('', $final->content, 'the usage chunk bills, it does not repeat text');
        $this->assertSame(85, $final->tokensUsed, 'fixture line 21 reports "total_tokens":85 - the stream readout sees the provider count, not a hardcoded 0 (E-55)');
        $this->assertSame(0.0, $final->costUsd);

        // The captured terminal document through the shared parse seam: every
        // figure the line reports, in the bucket it belongs to (fixture line 21).
        $usage = $provider->parseUsage([
            'prompt_tokens' => 57,
            'total_tokens' => 85,
            'completion_tokens' => 28,
            'prompt_tokens_details' => null,
            'reasoning_tokens' => 25,
        ]);
        $this->assertSame(85, $usage->totalTokens, 'fixture line 21 total');
        $this->assertSame(57, $usage->inputTokens, 'fixture line 21 prompt, cached details null - the whole prompt is fresh');
        $this->assertSame(28, $usage->outputTokens, 'fixture line 21 completion');
        $this->assertSame(25, $usage->reasoningTokens, 'fixture line 21 flat reasoning_tokens lands in Usage\'s reasoning bucket (§Q6 part 3, E-31)');
    }

    /**
     * §Q7 x §Q6 seam ordering. SYNTHETIC stream (spec qwen.md:207 records
     * synthetic as the sanctioned shape for this leg - §13 cat.8 allows it
     * here precisely because the point is the ASSEMBLY ORDER the provider
     * imposes, which no single live capture of a clean turn can show: a
     * `length` finish that also carries a usage frame and a flushable
     * buffer is the truncation case, whose bytes a probe cannot deterministically
     * reproduce).
     *
     * Pins the decision recorded in completeStream(): the §Q7 flush frame
     * rides the SAME final-yield seam as the §Q6 terminal usage chunk, but
     * BEFORE it, so finish-ordered events keep wire order (E-26:
     * tool_calls -> finish -> usage) and the bill stays the stream's last
     * event - the invariant §Q6's own yield docblock states.
     */
    public function testTruncatedStreamYieldsTheFlushedToolCallFrameBeforeTheTerminalUsageChunk(): void
    {
        $model = 'Qwen/Qwen3.8-Flash-Next';
        $usageChunk = '{"choices":[],"usage":{"prompt_tokens":60,"total_tokens":72,"completion_tokens":12,"prompt_tokens_details":null,"reasoning_tokens":13}}';
        $sse = 'data: {"choices":[{"delta":{"content":"Sure"}}]}' . "\n"
            . 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_ok","type":"function","function":{"name":"write_file","arguments":""}}]}}]}' . "\n"
            . 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"path\":\"a.php\",\"content\":\"ok\"}"}}]}}]}' . "\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"length","matched_stop":null}]}' . "\n"
            . 'data: ' . $usageChunk . "\n"
            . "data: [DONE]\n";

        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], $sse));
        $provider = new SglangProvider('https://api.example.com', $model, null, $httpClient);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: $model,
            messages: [new UserMessage('write it')],
        )));

        $this->assertCount(6, $chunks, 'the four streamed deltas and the finish frame yield as always, THEN the flushed tool-call frame, THEN the terminal usage chunk - [DONE] inert');
        $this->assertSame('Sure', $chunks[0]->content);
        $this->assertNull($chunks[0]->toolCalls);
        $callFrames = array_keys(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->toolCalls !== null));
        $this->assertSame([4], $callFrames, 'exactly ONE tool-call frame - the flush - and it sits before the usage terminal at index 5');
        $flush = $chunks[4];
        $this->assertCount(1, $flush->toolCalls);
        $this->assertSame('write_file', $flush->toolCalls[0]->name());
        $this->assertSame(['path' => 'a.php', 'content' => 'ok'], $flush->toolCalls[0]->arguments());
        $this->assertTrue($flush->truncated);
        $this->assertSame(0, $flush->tokensUsed, 'the flush executes, it does not bill');
        $usage = $chunks[5];
        $this->assertSame('', $usage->content);
        $this->assertSame(72, $usage->tokensUsed, 'the §Q6 usage terminal still closes the stream');
        $this->assertFalse($usage->truncated, 'the flag belongs to the flush frame only');
    }
}
