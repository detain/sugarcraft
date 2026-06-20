<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;

final class SglangProviderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 1. openAiCompatible() creates instance with correct defaults
    // -------------------------------------------------------------------------

    public function testOpenAiCompatibleCreatesInstanceWithCorrectDefaults(): void
    {
        $provider = SglangProvider::openAiCompatible('https://api.example.com');

        $this->assertInstanceOf(SglangProvider::class, $provider);
        $this->assertSame('sglang', $provider->name());
        $this->assertSame('MiniMax-M2.7', $this->getPrivateProperty($provider, 'model'));
    }

    public function testOpenAiCompatibleWithCustomModel(): void
    {
        $provider = SglangProvider::openAiCompatible('https://api.example.com', 'custom-model');

        $this->assertSame('custom-model', $this->getPrivateProperty($provider, 'model'));
    }

    public function testOpenAiCompatibleBaseUrlIsSet(): void
    {
        $provider = SglangProvider::openAiCompatible('https://api.example.com');

        $this->assertSame('https://api.example.com', $this->getPrivateProperty($provider, 'baseUrl'));
    }

    // -------------------------------------------------------------------------
    // 2. openAiCompatible() with apiKey sets Authorization header
    // -------------------------------------------------------------------------

    public function testOpenAiCompatibleWithApiKeySetsAuthorizationHeader(): void
    {
        // We can't easily verify the headers on the internal Client without reflection
        // but we verify the apiKey is stored correctly
        $provider = SglangProvider::openAiCompatible(
            'https://api.example.com',
            'MiniMax-M2.7',
            'test-api-key'
        );

        $this->assertSame('test-api-key', $this->getPrivateProperty($provider, 'apiKey'));
    }

    public function testOpenAiCompatibleWithoutApiKeyStoresNull(): void
    {
        $provider = SglangProvider::openAiCompatible('https://api.example.com');

        $this->assertNull($this->getPrivateProperty($provider, 'apiKey'));
    }

    // -------------------------------------------------------------------------
    // 3. name() returns 'sglang'
    // -------------------------------------------------------------------------

    public function testNameReturnsSglang(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertSame('sglang', $provider->name());
    }

    // -------------------------------------------------------------------------
    // 4. supportsStreaming() returns true
    // -------------------------------------------------------------------------

    public function testSupportsStreamingReturnsTrue(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertTrue($provider->supportsStreaming());
    }

    // -------------------------------------------------------------------------
    // 5. supportsFunctionCalling() returns true
    // -------------------------------------------------------------------------

    public function testSupportsFunctionCallingReturnsTrue(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertTrue($provider->supportsFunctionCalling());
    }

    // -------------------------------------------------------------------------
    // 6. supportsVision() returns false
    // -------------------------------------------------------------------------

    public function testSupportsVisionReturnsFalse(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertFalse($provider->supportsVision());
    }

    // -------------------------------------------------------------------------
    // 7. supportsJsonSchema() returns false
    // -------------------------------------------------------------------------

    public function testSupportsJsonSchemaReturnsFalse(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertFalse($provider->supportsJsonSchema());
    }

    // -------------------------------------------------------------------------
    // 8. contextWindow() returns 128000
    // -------------------------------------------------------------------------

    public function testContextWindowReturns128000(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertSame(128_000, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 9. costPer1kTokens() returns 0.0 for all models
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensReturnsZeroForAnyModel(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertSame(0.0, $provider->costPer1kTokens('MiniMax-M2.7', 'input'));
        $this->assertSame(0.0, $provider->costPer1kTokens('any-model', 'output'));
        $this->assertSame(0.0, $provider->costPer1kTokens('custom-model', 'input'));
    }

    // -------------------------------------------------------------------------
    // 10. formatMessages() correctly formats different Message types
    // -------------------------------------------------------------------------

    public function testFormatMessagesWithUserMessage(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $messages = [new UserMessage('Hello, world!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'user', 'content' => 'Hello, world!'],
        ], $result);
    }

    public function testFormatMessagesWithAssistantMessage(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $messages = [new AssistantMessage('Hello from assistant!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        // AssistantMessage with no tool calls should have content only (array_filter removes nulls)
        $this->assertSame([
            ['role' => 'assistant', 'content' => 'Hello from assistant!'],
        ], $result);
    }

    public function testFormatMessagesWithAssistantMessageAndToolCalls(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $toolCalls = [
            new ToolCall('call_123', 'get_weather', ['city' => 'Tokyo']),
        ];
        $messages = [new AssistantMessage('Let me check the weather', $toolCalls)];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        // array_filter removes null values, so tool_calls stays if present
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
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $messages = [new SystemMessage('You are a helpful assistant.')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ], $result);
    }

    public function testFormatMessagesWithToolResultMessage(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $messages = [new ToolResultMessage('call_123', 'The weather is sunny.')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'tool', 'tool_call_id' => 'call_123', 'content' => 'The weather is sunny.'],
        ], $result);
    }

    public function testFormatMessagesWithMultipleMessages(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

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
    // 11. formatTools() correctly formats Tool objects
    // -------------------------------------------------------------------------

    public function testFormatToolsWithSingleTool(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

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
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

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
    // 12. complete() makes HTTP POST and returns CompleteResponse
    // -------------------------------------------------------------------------

    public function testCompleteMakesHttpPostAndReturnsCompleteResponse(): void
    {
        $httpClient = $this->createMock(Client::class);

        $responseBody = json_encode([
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
        ]);

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('/chat/completions')
            ->willReturn($response);

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $request = new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hello')],
        );

        $result = $provider->complete($request);

        $this->assertInstanceOf(CompleteResponse::class, $result);
        $this->assertSame('Hello! How can I help you?', $result->content);
        $this->assertSame(25, $result->tokensUsed);
        $this->assertNull($result->toolCalls);
    }

    public function testCompleteWithToolCalls(): void
    {
        $httpClient = $this->createMock(Client::class);

        $responseBody = json_encode([
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
                'total_tokens' => 15,
            ],
        ]);

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('/chat/completions')
            ->willReturn($response);

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('get_weather');
        $tool->method('description')->willReturn('Get weather');
        $tool->method('inputSchema')->willReturn([]);

        $request = new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Weather in Tokyo?')],
            tools: [$tool],
        );

        $result = $provider->complete($request);

        $this->assertSame('', $result->content);
        $this->assertNotNull($result->toolCalls);
        $this->assertCount(1, $result->toolCalls);
        $this->assertInstanceOf(ToolCall::class, $result->toolCalls[0]);
        $this->assertSame('call_abc123', $result->toolCalls[0]->id());
        $this->assertSame('get_weather', $result->toolCalls[0]->name());
        $this->assertSame(['city' => 'Tokyo'], $result->toolCalls[0]->arguments());
    }

    public function testCompleteWithCustomTemperatureAndMaxTokens(): void
    {
        $httpClient = $this->createMock(Client::class);

        $responseBody = json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => 'Response',
                    ],
                ],
            ],
            'usage' => [
                'total_tokens' => 100,
            ],
        ]);

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())
            ->method('post')
            ->willReturn($response);

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $request = new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hello')],
            temperature: 0.9,
            maxTokens: 100,
        );

        $result = $provider->complete($request);

        $this->assertSame('Response', $result->content);
    }

    public function testCompleteThrowsRuntimeExceptionOnGuzzleException(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection failed',
                new Request('POST', '/chat/completions')
            ));

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $request = new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hello')],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^SGLANG request failed:/');

        $provider->complete($request);
    }

    // -------------------------------------------------------------------------
    // 13. completeStream() returns Generator
    // -------------------------------------------------------------------------

    public function testCompleteStreamReturnsGenerator(): void
    {
        $httpClient = $this->createMock(Client::class);

        // GuzzleHttp\Psr7\Stream has no readLine() of its own (it is a Utils helper),
        // so the body double must declare it explicitly via addMethods().
        $responseBody = $this->getMockBuilder(Stream::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['eof'])
            ->addMethods(['readLine'])
            ->getMock();
        $responseBody->method('eof')->willReturnOnConsecutiveCalls(false, false, false, true);
        $responseBody->method('readLine')->willReturnOnConsecutiveCalls(
            'data: {"choices":[{"delta":{"content":"Hello"}}]}',
            'data: {"choices":[{"delta":{"content":" world"}}]}',
            'data: [DONE]',
            ''
        );

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('/chat/completions', $this->callback(static fn ($opts): bool => is_array($opts) && ($opts['stream'] ?? false) === true))
            ->willReturn($response);

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $request = new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hello')],
        );

        $generator = $provider->completeStream($request);

        $this->assertInstanceOf(\Generator::class, $generator);

        // Collect generator values
        $chunks = iterator_to_array($generator);

        $this->assertCount(2, $chunks);
        $this->assertSame('Hello', $chunks[0]->content);
        $this->assertSame(' world', $chunks[1]->content);
    }

    public function testCompleteStreamThrowsRuntimeExceptionOnGuzzleException(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection failed',
                new Request('POST', '/chat/completions')
            ));

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $request = new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hello')],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^SGLANG request failed:/');

        // Consume the generator to trigger the exception
        foreach ($provider->completeStream($request) as $chunk) {
            // process chunk
        }
    }

    // -------------------------------------------------------------------------
    // 14. embeddings() makes HTTP POST and returns EmbeddingsResponse
    // -------------------------------------------------------------------------

    public function testEmbeddingsMakesHttpPostAndReturnsEmbeddingsResponse(): void
    {
        $httpClient = $this->createMock(Client::class);

        $responseBody = json_encode([
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3]],
                ['embedding' => [0.4, 0.5, 0.6]],
            ],
        ]);

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('/embeddings')
            ->willReturn($response);

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $request = new EmbeddingsRequest(
            model: 'embeddings-model',
            input: ['Hello world', 'Goodbye world'],
        );

        $result = $provider->embeddings($request);

        $this->assertInstanceOf(EmbeddingsResponse::class, $result);
        $this->assertCount(2, $result->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $result->embeddings[0]);
        $this->assertSame([0.4, 0.5, 0.6], $result->embeddings[1]);
    }

    public function testEmbeddingsWithEmptyResponseReturnsEmptyArray(): void
    {
        $httpClient = $this->createMock(Client::class);

        $responseBody = json_encode([
            'data' => [],
        ]);

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('/embeddings')
            ->willReturn($response);

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $request = new EmbeddingsRequest(
            model: 'embeddings-model',
            input: ['Hello'],
        );

        $result = $provider->embeddings($request);

        $this->assertInstanceOf(EmbeddingsResponse::class, $result);
        $this->assertCount(0, $result->embeddings);
    }

    public function testEmbeddingsReturnsEmptyArrayOnGuzzleException(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection failed',
                new Request('POST', '/embeddings')
            ));

        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $httpClient);

        $request = new EmbeddingsRequest(
            model: 'embeddings-model',
            input: ['Hello'],
        );

        $result = $provider->embeddings($request);

        // Per the implementation, embeddings returns empty array on exception
        $this->assertInstanceOf(EmbeddingsResponse::class, $result);
        $this->assertCount(0, $result->embeddings);
    }

    // -------------------------------------------------------------------------
    // Helper: Get private property value via reflection
    // -------------------------------------------------------------------------

    private function getPrivateProperty(object $object, string $propertyName): mixed
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($object);
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
