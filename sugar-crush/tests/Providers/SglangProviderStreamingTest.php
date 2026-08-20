<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CompleteRequest;
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
}
