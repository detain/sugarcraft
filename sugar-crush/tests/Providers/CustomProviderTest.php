<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;

final class CustomProviderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 1. openAiCompatible() factory creates instance with correct defaults
    // -------------------------------------------------------------------------

    public function testOpenAiCompatibleFactoryWithDefaults(): void
    {
        $provider = CustomProvider::openAiCompatible(
            'custom',
            'https://api.example.com',
            'gpt-4'
        );

        $this->assertInstanceOf(CustomProvider::class, $provider);
        $this->assertSame('custom', $provider->name());
        $this->assertTrue($provider->supportsStreaming());
        $this->assertTrue($provider->supportsFunctionCalling());
    }

    public function testOpenAiCompatibleFactoryWithCustomName(): void
    {
        $provider = CustomProvider::openAiCompatible(
            'my-provider',
            'https://api.example.com',
            'gpt-4'
        );

        $this->assertSame('my-provider', $provider->name());
    }

    public function testOpenAiCompatibleFactoryWithApiKey(): void
    {
        $provider = CustomProvider::openAiCompatible(
            'custom',
            'https://api.example.com',
            'gpt-4',
            'sk-secret-key'
        );

        $this->assertInstanceOf(CustomProvider::class, $provider);
    }

    public function testOpenAiCompatibleFactoryWithStreamingDisabled(): void
    {
        $provider = CustomProvider::openAiCompatible(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            false,
            true
        );

        $this->assertFalse($provider->supportsStreaming());
        $this->assertTrue($provider->supportsFunctionCalling());
    }

    public function testOpenAiCompatibleFactoryWithFunctionCallingDisabled(): void
    {
        $provider = CustomProvider::openAiCompatible(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            true,
            false
        );

        $this->assertTrue($provider->supportsStreaming());
        $this->assertFalse($provider->supportsFunctionCalling());
    }

    // -------------------------------------------------------------------------
    // 2. name() returns configured name
    // -------------------------------------------------------------------------

    public function testNameReturnsConfiguredName(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'test-provider',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $this->assertSame('test-provider', $provider->name());
    }

    // -------------------------------------------------------------------------
    // 3. supportsStreaming() returns configured value
    // -------------------------------------------------------------------------

    public function testSupportsStreamingReturnsTrue(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $this->assertTrue($provider->supportsStreaming());
    }

    public function testSupportsStreamingReturnsFalse(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            false,
            true
        );

        $this->assertFalse($provider->supportsStreaming());
    }

    // -------------------------------------------------------------------------
    // 4. supportsFunctionCalling() returns configured value
    // -------------------------------------------------------------------------

    public function testSupportsFunctionCallingReturnsTrue(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $this->assertTrue($provider->supportsFunctionCalling());
    }

    public function testSupportsFunctionCallingReturnsFalse(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            false
        );

        $this->assertFalse($provider->supportsFunctionCalling());
    }

    // -------------------------------------------------------------------------
    // 5. supportsVision() returns false
    // -------------------------------------------------------------------------

    public function testSupportsVisionReturnsFalse(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $this->assertFalse($provider->supportsVision());
    }

    // -------------------------------------------------------------------------
    // 6. supportsJsonSchema() returns false
    // -------------------------------------------------------------------------

    public function testSupportsJsonSchemaReturnsFalse(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $this->assertFalse($provider->supportsJsonSchema());
    }

    // -------------------------------------------------------------------------
    // 7. contextWindow() returns 128000
    // -------------------------------------------------------------------------

    public function testContextWindowReturns128000(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $this->assertSame(128_000, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 8. costPer1kTokens() returns 0.0 (self-hosted, no cost)
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensReturnsZero(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $this->assertSame(0.0, $provider->costPer1kTokens('gpt-4', 'input'));
        $this->assertSame(0.0, $provider->costPer1kTokens('gpt-4', 'output'));
    }

    // -------------------------------------------------------------------------
    // 9. complete() returns CompleteResponse on success
    // -------------------------------------------------------------------------

    public function testCompleteReturnsCompleteResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Hello! How can I help you?',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 15,
                    'total_tokens' => 25,
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('Hello')],
        );

        $response = $provider->complete($request);

        $this->assertInstanceOf(CompleteResponse::class, $response);
        $this->assertSame('Hello! How can I help you?', $response->content);
        $this->assertSame(25, $response->tokensUsed);
        $this->assertNull($response->toolCalls);
    }

    // -------------------------------------------------------------------------
    // 10. complete() returns error response on exception
    // -------------------------------------------------------------------------

    public function testCompleteReturnsErrorResponseOnGuzzleException(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection failed',
                new Request('POST', 'https://api.example.com/chat/completions')
            ),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('Hello')],
        );

        $response = $provider->complete($request);

        $this->assertInstanceOf(CompleteResponse::class, $response);
        $this->assertSame('', $response->content);
        $this->assertTrue($response->isError ?? true);
        $this->assertSame('Connection failed', $response->errorMessage);
    }

    // -------------------------------------------------------------------------
    // 11. complete() with tools returns tool calls
    // -------------------------------------------------------------------------

    public function testCompleteWithToolsReturnsToolCalls(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => '',
                            'tool_calls' => [
                                [
                                    'id' => 'call_abc123',
                                    'function' => [
                                        'name' => 'get_weather',
                                        'arguments' => '{"city":"Tokyo"}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'total_tokens' => 50,
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('get_weather');
        $tool->method('description')->willReturn('Get weather');
        $tool->method('inputSchema')->willReturn([]);

        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('Weather in Tokyo?')],
            tools: [$tool],
        );

        $response = $provider->complete($request);

        $this->assertSame('', $response->content);
        $this->assertNotNull($response->toolCalls);
        $this->assertCount(1, $response->toolCalls);
        $this->assertInstanceOf(ToolCall::class, $response->toolCalls[0]);
        $this->assertSame('call_abc123', $response->toolCalls[0]->id());
        $this->assertSame('get_weather', $response->toolCalls[0]->name());
        $this->assertSame(['city' => 'Tokyo'], $response->toolCalls[0]->arguments());
    }

    // -------------------------------------------------------------------------
    // 12. complete() with tools when function calling disabled - tools not sent
    // -------------------------------------------------------------------------

    public function testCompleteWithToolsWhenFunctionCallingDisabled(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Response without tools',
                        ],
                    ],
                ],
                'usage' => [
                    'total_tokens' => 20,
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            false // Function calling disabled
        );

        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('get_weather');
        $tool->method('description')->willReturn('Get weather');
        $tool->method('inputSchema')->willReturn([]);

        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('Weather in Tokyo?')],
            tools: [$tool],
        );

        $response = $provider->complete($request);

        $this->assertSame('Response without tools', $response->content);
    }

    // -------------------------------------------------------------------------
    // 13. completeStream() yields Generator when streaming enabled
    // -------------------------------------------------------------------------

    public function testCompleteStreamReturnsGeneratorWhenStreamingEnabled(): void
    {
        $mock = new MockHandler([
            new Response(200, [], "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\ndata: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\ndata: [DONE]\n"),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true, // Streaming enabled
            true
        );

        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('Hello')],
        );

        $generator = $provider->completeStream($request);
        $this->assertInstanceOf(\Generator::class, $generator);

        $responses = iterator_to_array($generator);
        $this->assertGreaterThan(0, count($responses));
    }

    // -------------------------------------------------------------------------
    // 14. completeStream() falls back to complete() when streaming disabled
    // -------------------------------------------------------------------------

    public function testCompleteStreamFallsBackToCompleteWhenStreamingDisabled(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Non-streaming response',
                        ],
                    ],
                ],
                'usage' => [
                    'total_tokens' => 10,
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            false, // Streaming disabled
            true
        );

        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('Hello')],
        );

        $generator = $provider->completeStream($request);
        $responses = iterator_to_array($generator);

        $this->assertCount(1, $responses);
        $this->assertSame('Non-streaming response', $responses[0]->content);
    }

    // -------------------------------------------------------------------------
    // 15. completeStream() yields error response on exception
    // -------------------------------------------------------------------------

    public function testCompleteStreamYieldsErrorResponseOnException(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection failed',
                new Request('POST', 'https://api.example.com/chat/completions')
            ),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('Hello')],
        );

        $generator = $provider->completeStream($request);
        $responses = iterator_to_array($generator);

        $this->assertCount(1, $responses);
        $this->assertTrue($responses[0]->isError ?? true);
        $this->assertSame('Connection failed', $responses[0]->errorMessage);
    }

    // -------------------------------------------------------------------------
    // 16. embeddings() returns EmbeddingsResponse on success
    // -------------------------------------------------------------------------

    public function testEmbeddingsReturnsEmbeddingsResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                    ['embedding' => [0.4, 0.5, 0.6]],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-small',
            input: ['Hello world', 'Goodbye world'],
        );

        $response = $provider->embeddings($request);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(2, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame([0.4, 0.5, 0.6], $response->embeddings[1]);
    }

    // -------------------------------------------------------------------------
    // 17. embeddings() returns empty EmbeddingsResponse on exception
    // -------------------------------------------------------------------------

    public function testEmbeddingsReturnsEmptyOnException(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection failed',
                new Request('POST', 'https://api.example.com/embeddings')
            ),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-small',
            input: ['Hello'],
        );

        $response = $provider->embeddings($request);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(0, $response->embeddings);
    }

    // -------------------------------------------------------------------------
    // 18. formatMessages() correctly formats different Message types
    // -------------------------------------------------------------------------

    public function testFormatMessagesWithUserMessage(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $messages = [new UserMessage('Hello, world!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'user', 'content' => 'Hello, world!'],
        ], $result);
    }

    public function testFormatMessagesWithAssistantMessage(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $messages = [new AssistantMessage('Hello from assistant!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'assistant', 'content' => 'Hello from assistant!'],
        ], $result);
    }

    public function testFormatMessagesWithAssistantMessageWithToolCalls(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $toolCalls = [
            new ToolCall('call_123', 'get_weather', ['city' => 'Tokyo']),
        ];
        $messages = [new AssistantMessage('Let me check the weather', $toolCalls)];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            [
                'role' => 'assistant',
                'content' => 'Let me check the weather',
                'tool_calls' => $toolCalls,
            ],
        ], $result);
    }

    public function testFormatMessagesWithSystemMessage(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $messages = [new SystemMessage('You are a helpful assistant.')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ], $result);
    }

    public function testFormatMessagesWithToolResultMessage(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $messages = [new ToolResultMessage('call_123', 'The weather is sunny.')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'tool', 'tool_call_id' => 'call_123', 'content' => 'The weather is sunny.'],
        ], $result);
    }

    public function testFormatMessagesWithMultipleMessages(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $messages = [
            new SystemMessage('You are a helpful assistant.'),
            new UserMessage('What is the weather in Tokyo?'),
            new AssistantMessage('Let me check that for you.'),
            new ToolResultMessage('call_123', 'Sunny, 72°F'),
            new AssistantMessage('The weather in Tokyo is sunny with 72°F.'),
        ];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'What is the weather in Tokyo?'],
            ['role' => 'assistant', 'content' => 'Let me check that for you.'],
            ['role' => 'tool', 'tool_call_id' => 'call_123', 'content' => 'Sunny, 72°F'],
            ['role' => 'assistant', 'content' => 'The weather in Tokyo is sunny with 72°F.'],
        ], $result);
    }

    // -------------------------------------------------------------------------
    // 19. formatTools() correctly formats Tool objects
    // -------------------------------------------------------------------------

    public function testFormatToolsWithSingleTool(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('get_weather');
        $tool->method('description')->willReturn('Get the current weather for a city');
        $tool->method('inputSchema')->willReturn([
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string', 'description' => 'The city name'],
            ],
            'required' => ['city'],
        ]);

        $result = $this->invokePrivateMethod($provider, 'formatTools', [[$tool]]);

        $this->assertSame([
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get the current weather for a city',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string', 'description' => 'The city name'],
                        ],
                        'required' => ['city'],
                    ],
                ],
            ],
        ], $result);
    }

    public function testFormatToolsWithMultipleTools(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $tool1 = $this->createMock(Tool::class);
        $tool1->method('name')->willReturn('get_weather');
        $tool1->method('description')->willReturn('Get weather');
        $tool1->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);

        $tool2 = $this->createMock(Tool::class);
        $tool2->method('name')->willReturn('search');
        $tool2->method('description')->willReturn('Search the web');
        $tool2->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);

        $result = $this->invokePrivateMethod($provider, 'formatTools', [[$tool1, $tool2]]);

        $this->assertCount(2, $result);
        $this->assertSame('get_weather', $result[0]['function']['name']);
        $this->assertSame('search', $result[1]['function']['name']);
    }

    // -------------------------------------------------------------------------
    // 20. parseChunk() correctly parses streaming chunks
    // -------------------------------------------------------------------------

    public function testParseChunkWithContent(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $data = [
            'choices' => [
                ['delta' => ['content' => 'Hello']],
            ],
        ];

        $result = $this->invokePrivateMethod($provider, 'parseChunk', [$data]);

        $this->assertSame('Hello', $result->content);
        $this->assertNull($result->reasoning);
        $this->assertNull($result->toolCalls);
    }

    public function testParseChunkWithEmptyDelta(): void
    {
        $client = new Client();
        $provider = new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            $client,
            true,
            true
        );

        $data = [
            'choices' => [
                ['delta' => []],
            ],
        ];

        $result = $this->invokePrivateMethod($provider, 'parseChunk', [$data]);

        $this->assertSame('', $result->content);
    }

    // -------------------------------------------------------------------------
    // Helper: Invoke private method using reflection
    // -------------------------------------------------------------------------

    private function invokePrivateMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
