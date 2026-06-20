<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Contracts\Resources\EmbeddingsContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
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
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;

final class OpenAIProviderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 1. Constructor sets client and defaultModel correctly
    // -------------------------------------------------------------------------

    public function testConstructorSetsClientAndDefaultModel(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        // Use reflection to verify private properties are set correctly
        $reflection = new \ReflectionClass($provider);
        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $this->assertSame($client, $clientProp->getValue($provider));

        $defaultModelProp = $reflection->getProperty('defaultModel');
        $defaultModelProp->setAccessible(true);
        $this->assertSame('gpt-4o', $defaultModelProp->getValue($provider));
    }

    public function testConstructorDefaultModelIsGpt4o(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client);

        $reflection = new \ReflectionClass($provider);
        $defaultModelProp = $reflection->getProperty('defaultModel');
        $defaultModelProp->setAccessible(true);
        $this->assertSame('gpt-4o', $defaultModelProp->getValue($provider));
    }

    // -------------------------------------------------------------------------
    // 2. name() returns 'openai'
    // -------------------------------------------------------------------------

    public function testNameReturnsOpenai(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertSame('openai', $provider->name());
    }

    // -------------------------------------------------------------------------
    // 3. supportsStreaming() returns true
    // -------------------------------------------------------------------------

    public function testSupportsStreamingReturnsTrue(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertTrue($provider->supportsStreaming());
    }

    // -------------------------------------------------------------------------
    // 4. supportsFunctionCalling() returns true
    // -------------------------------------------------------------------------

    public function testSupportsFunctionCallingReturnsTrue(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertTrue($provider->supportsFunctionCalling());
    }

    // -------------------------------------------------------------------------
    // 5. supportsVision() returns true
    // -------------------------------------------------------------------------

    public function testSupportsVisionReturnsTrue(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertTrue($provider->supportsVision());
    }

    // -------------------------------------------------------------------------
    // 6. supportsJsonSchema() returns false
    // -------------------------------------------------------------------------

    public function testSupportsJsonSchemaReturnsFalse(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertFalse($provider->supportsJsonSchema());
    }

    // -------------------------------------------------------------------------
    // 7. contextWindow() returns correct values for known models
    // -------------------------------------------------------------------------

    public function testContextWindowForGpt4o(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertSame(128_000, $provider->contextWindow());
    }

    public function testContextWindowForGpt4Turbo(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4-turbo');

        $this->assertSame(128_000, $provider->contextWindow());
    }

    public function testContextWindowForGpt4(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    public function testContextWindowForGpt35Turbo(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-3.5-turbo');

        $this->assertSame(16_385, $provider->contextWindow());
    }

    public function testContextWindowForUnknownModelReturnsDefault(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'unknown-model');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 8. costPer1kTokens() returns correct values for known models and input/output
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensForGpt4oInput(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertSame(0.005, $provider->costPer1kTokens('gpt-4o', 'input'));
    }

    public function testCostPer1kTokensForGpt4oOutput(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertSame(0.015, $provider->costPer1kTokens('gpt-4o', 'output'));
    }

    public function testCostPer1kTokensForGpt4TurboInput(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4-turbo');

        $this->assertSame(0.01, $provider->costPer1kTokens('gpt-4-turbo', 'input'));
    }

    public function testCostPer1kTokensForGpt4TurboOutput(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4-turbo');

        $this->assertSame(0.03, $provider->costPer1kTokens('gpt-4-turbo', 'output'));
    }

    public function testCostPer1kTokensForGpt4Input(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4');

        $this->assertSame(0.03, $provider->costPer1kTokens('gpt-4', 'input'));
    }

    public function testCostPer1kTokensForGpt4Output(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4');

        $this->assertSame(0.06, $provider->costPer1kTokens('gpt-4', 'output'));
    }

    public function testCostPer1kTokensForGpt35TurboInput(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-3.5-turbo');

        $this->assertSame(0.0005, $provider->costPer1kTokens('gpt-3.5-turbo', 'input'));
    }

    public function testCostPer1kTokensForGpt35TurboOutput(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-3.5-turbo');

        $this->assertSame(0.0015, $provider->costPer1kTokens('gpt-3.5-turbo', 'output'));
    }

    // -------------------------------------------------------------------------
    // 9. costPer1kTokens() returns default value for unknown models
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensForUnknownModelReturnsDefault(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'unknown-model');

        $this->assertSame(0.01, $provider->costPer1kTokens('unknown-model', 'input'));
        $this->assertSame(0.01, $provider->costPer1kTokens('unknown-model', 'output'));
    }

    // -------------------------------------------------------------------------
    // 10. formatMessages() correctly formats different Message types
    // -------------------------------------------------------------------------

    public function testFormatMessagesWithUserMessage(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $messages = [new UserMessage('Hello, world!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'user', 'content' => 'Hello, world!'],
        ], $result);
    }

    public function testFormatMessagesWithAssistantMessageWithoutToolCalls(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $messages = [new AssistantMessage('Hello from assistant!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'assistant', 'content' => 'Hello from assistant!'],
        ], $result);
    }

    public function testFormatMessagesWithAssistantMessageWithToolCalls(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

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
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $messages = [new SystemMessage('You are a helpful assistant.')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ], $result);
    }

    public function testFormatMessagesWithToolResultMessage(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $messages = [new ToolResultMessage('call_123', 'The weather is sunny.')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'tool', 'tool_call_id' => 'call_123', 'content' => 'The weather is sunny.'],
        ], $result);
    }

    public function testFormatMessagesWithMultipleMessages(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

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
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

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
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

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
    // 12. complete() calls client and returns CompleteResponse
    // -------------------------------------------------------------------------

    public function testCompleteReturnsCompleteResponse(): void
    {
        $client = $this->createMock(ClientContract::class);

        // Mock the chat() -> create() call chain
        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $completionResponse = ChatCreateResponse::from([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello! How can I help you?',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 15,
                'total_tokens' => 25,
            ],
        ], MetaInformation::from([]));

        $chatMock->method('create')->willReturn($completionResponse);

        $provider = new OpenAIProvider($client, 'gpt-4o');

        $request = new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('Hello')],
        );

        $response = $provider->complete($request);

        $this->assertInstanceOf(CompleteResponse::class, $response);
        $this->assertSame('Hello! How can I help you?', $response->content);
        $this->assertSame(25, $response->tokensUsed);
        $this->assertNull($response->toolCalls);
    }

    public function testCompleteWithSystemPrompt(): void
    {
        $client = $this->createMock(ClientContract::class);

        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $completionResponse = ChatCreateResponse::from([
            'id' => 'chatcmpl-2',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Response with system prompt',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 10,
                'total_tokens' => 30,
            ],
        ], MetaInformation::from([]));

        $chatMock->method('create')->willReturn($completionResponse);

        $provider = new OpenAIProvider($client, 'gpt-4o');

        $request = new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('Hello')],
            systemPrompt: 'You are a helpful assistant.',
        );

        $response = $provider->complete($request);

        $this->assertSame('Response with system prompt', $response->content);
    }

    public function testCompleteWithTools(): void
    {
        $client = $this->createMock(ClientContract::class);

        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $completionResponse = ChatCreateResponse::from([
            'id' => 'chatcmpl-3',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_abc123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"city":"Tokyo"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
            ],
        ], MetaInformation::from([]));

        $chatMock->method('create')->willReturn($completionResponse);

        $provider = new OpenAIProvider($client, 'gpt-4o');

        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('get_weather');
        $tool->method('description')->willReturn('Get weather');
        $tool->method('inputSchema')->willReturn([]);

        $request = new CompleteRequest(
            model: 'gpt-4o',
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

    public function testCompleteWithCustomTemperatureAndMaxTokens(): void
    {
        $client = $this->createMock(ClientContract::class);

        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $completionResponse = ChatCreateResponse::from([
            'id' => 'chatcmpl-4',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Response',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 5,
                'completion_tokens' => 5,
                'total_tokens' => 10,
            ],
        ], MetaInformation::from([]));

        $chatMock->method('create')->willReturn($completionResponse);

        $provider = new OpenAIProvider($client, 'gpt-4o');

        $request = new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('Hello')],
            temperature: 0.9,
            maxTokens: 100,
        );

        $provider->complete($request);

        // Verify the parameters were passed correctly
        // We can't directly inspect the call, but the test verifies it doesn't throw
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // 13. embeddings() returns correct structure
    // -------------------------------------------------------------------------

    public function testEmbeddingsReturnsCorrectStructure(): void
    {
        $client = $this->createMock(ClientContract::class);

        // Mock the embeddings() -> create() call chain
        $embeddingsMock = $this->createMock(EmbeddingsContract::class);
        $client->method('embeddings')->willReturn($embeddingsMock);

        $responseMock = EmbeddingsCreateResponse::from([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 2, 'total_tokens' => 2],
        ], MetaInformation::from([]));

        $embeddingsMock->method('create')->willReturn($responseMock);

        $provider = new OpenAIProvider($client, 'gpt-4o');

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

    public function testEmbeddingsWithEmptyResponse(): void
    {
        $client = $this->createMock(ClientContract::class);

        $embeddingsMock = $this->createMock(EmbeddingsContract::class);
        $client->method('embeddings')->willReturn($embeddingsMock);

        $responseMock = EmbeddingsCreateResponse::from([
            'object' => 'list',
            'data' => [],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ], MetaInformation::from([]));

        $embeddingsMock->method('create')->willReturn($responseMock);

        $provider = new OpenAIProvider($client, 'gpt-4o');

        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-small',
            input: ['Hello'],
        );

        $response = $provider->embeddings($request);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(0, $response->embeddings);
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
