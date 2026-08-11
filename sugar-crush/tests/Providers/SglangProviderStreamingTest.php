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
}
