<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Regression coverage for §12 D2 of crush_feat.md: `CustomProvider::parseChunk()`
 * had the identical bug as `SglangProvider::parseChunk()` (byte-for-byte, per
 * the spec) - it hardcoded `toolCalls: null` on every chunk, so
 * `completeStream()` could never deliver a tool call. These tests assert the
 * delta.tool_calls[] fragment accumulation added to close that gap; every
 * "assembled" assertion below would fail against the old parseChunk().
 */
final class CustomProviderStreamingTest extends TestCase
{
    private function makeProvider(Client $client, bool $streaming = true): CustomProvider
    {
        return new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            $streaming,
            true,
        );
    }

    // -------------------------------------------------------------------------
    // parseChunk() accumulates tool_calls fragments across successive chunks
    // and only emits assembled ToolCall objects once finish_reason arrives.
    // -------------------------------------------------------------------------

    public function testParseChunkAccumulatesToolCallFragmentsAcrossChunks(): void
    {
        $provider = $this->makeProvider(new Client());

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];

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
        $this->assertSame([], $buffer);
    }

    public function testParseChunkHandlesMultipleConcurrentToolCallIndices(): void
    {
        $provider = $this->makeProvider(new Client());

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $buffer = [];

        $chunk = [
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
        $result = $method->invokeArgs($provider, [$chunk, &$buffer]);

        $this->assertNotNull($result->toolCalls);
        $this->assertCount(2, $result->toolCalls);
        $this->assertSame('call_1', $result->toolCalls[0]->id());
        $this->assertSame('call_2', $result->toolCalls[1]->id());
    }

    public function testParseChunkStillReturnsContentWhenNoToolCallsPresent(): void
    {
        $provider = $this->makeProvider(new Client());

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
        $provider = $this->makeProvider(new Client());

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
    // ProviderInterface::completeStream() -> CustomProvider::parseChunk().
    // -------------------------------------------------------------------------

    public function testCompleteStreamDeliversAssembledToolCallEndToEnd(): void
    {
        $sse = 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_xyz","function":{"name":"search","arguments":""}}]}}]}' . "\n"
            . 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"q\":\"php\"}"}}]},"finish_reason":"tool_calls"}]}' . "\n"
            . 'data: [DONE]' . "\n";

        $mock = new MockHandler([new Response(200, [], $sse)]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = $this->makeProvider($client);
        $request = new CompleteRequest(model: 'gpt-4', messages: [new UserMessage('search php')]);

        $chunks = iterator_to_array($provider->completeStream($request));

        $toolCallChunks = array_values(array_filter($chunks, fn ($c) => $c->toolCalls !== null));
        $this->assertNotEmpty($toolCallChunks, 'completeStream() must deliver at least one chunk carrying assembled tool calls');
        $this->assertCount(1, $toolCallChunks[0]->toolCalls);
        $this->assertSame('call_xyz', $toolCallChunks[0]->toolCalls[0]->id());
        $this->assertSame('search', $toolCallChunks[0]->toolCalls[0]->name());
        $this->assertSame(['q' => 'php'], $toolCallChunks[0]->toolCalls[0]->arguments());
    }
}
