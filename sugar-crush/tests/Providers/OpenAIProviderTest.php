<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Contracts\Resources\EmbeddingsContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Chat\CreateStreamedResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsCreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Responses\StreamResponse;
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

    public function testContextWindowForUnknownModelReturnsZeroForUnknown(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'unknown-model');

        // Audit A13: this pinned an invented 8,192 default. 0 is the
        // ProviderInterface "unknown" answer ContextWindow::resolve() maps
        // to its one named fallback.
        $this->assertSame(0, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 8. costPer1kTokens() returns correct values for known models and input/output
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensForGpt4oInput(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertSame(0.0025, $provider->costPer1kTokens('gpt-4o', 'input'));
    }

    public function testCostPer1kTokensForGpt4oOutput(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o');

        $this->assertSame(0.01, $provider->costPer1kTokens('gpt-4o', 'output'));
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
    // 9. costPer1kTokens() returns NULL for unknown models (billing fix:
    //    no fabricated default — an unknown rate is a loud unknown)
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensForUnknownModelReturnsNull(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'unknown-model');

        $this->assertNull($provider->costPer1kTokens('unknown-model', 'input'));
        $this->assertNull($provider->costPer1kTokens('unknown-model', 'output'));
    }

    // 9b. The config seam: modelPrices overrides/extends the built-in table
    //     (operator-declared rates are USD-per-1M; the provider divides).
    public function testModelPricesOverridesBuiltInRate(): void
    {
        $client = $this->createMock(ClientContract::class);
        // 7.5/30 USD per 1M → 0.0075/0.03 per 1K, replacing gpt-4o's table row.
        $provider = new OpenAIProvider($client, 'gpt-4o', [
            'gpt-4o' => ['input' => 7.5, 'output' => 30.0],
        ]);

        $this->assertSame(0.0075, $provider->costPer1kTokens('gpt-4o', 'input'));
        $this->assertSame(0.03, $provider->costPer1kTokens('gpt-4o', 'output'));
    }

    public function testModelPricesPricesAnUnknownModel(): void
    {
        $client = $this->createMock(ClientContract::class);
        $provider = new OpenAIProvider($client, 'gpt-4o', [
            'my-router-model' => ['input' => 1.0, 'output' => 2.0],
        ]);

        $this->assertSame(0.001, $provider->costPer1kTokens('my-router-model', 'input'));
        $this->assertSame(0.002, $provider->costPer1kTokens('my-router-model', 'output'));
    }

    // 9c. Per-model pricing: the SAME usage document priced at two different
    //     models yields different dollars, priced at the REQUEST model rather
    //     than the provider default (audit finding 1's core defect).
    public function testCompletePricesAtRequestedModelNotDefault(): void
    {
        $client = $this->createMock(ClientContract::class);
        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $chatMock->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 1000, 'total_tokens' => 2000],
        ], MetaInformation::from([])));

        // Default is the cheap gpt-4o-mini; the request asks for gpt-4. Pricing
        // gpt-4's usage at gpt-4o-mini's rate would be the bug: 1000/1000 at
        // gpt-4 (0.03/0.06) is $0.09, at gpt-4o-mini (0.00015/0.0006) it is
        // $0.00075. The response must carry the gpt-4 figure.
        $provider = new OpenAIProvider($client, 'gpt-4o-mini');
        $response = $provider->complete(new CompleteRequest(
            model: 'gpt-4',
            messages: [new UserMessage('Hello')],
        ));

        $this->assertSame(0.09, $response->costUsd);
        $this->assertNotNull($response->usage);
        $this->assertNull($response->usage->unpricedModel);
    }

    // 9d. An unknown requested model: cost is the 0.0 lower bound AND the
    //     name rides out on the carrier so accounting can tell it from free.
    public function testCompleteUnknownRequestedModelSignalsUnpriced(): void
    {
        $client = $this->createMock(ClientContract::class);
        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $chatMock->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'never-seen-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 250, 'total_tokens' => 750],
        ], MetaInformation::from([])));

        $provider = new OpenAIProvider($client, 'gpt-4o');
        $response = $provider->complete(new CompleteRequest(
            model: 'never-seen-model',
            messages: [new UserMessage('Hello')],
        ));

        $this->assertSame(0.0, $response->costUsd);
        $this->assertNotNull($response->usage);
        $this->assertSame(750, $response->usage->totalTokens);
        $this->assertSame('never-seen-model', $response->usage->unpricedModel);
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
                // The OpenAI wire shape, NOT the raw ToolCall objects this
                // once asserted: ToolCall's state is private, so json_encode()
                // rendered each call as `{}` and the server 400'd the whole
                // request with "Field required" for the missing `function`.
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Tokyo"}'],
                ]],
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

    public function testCompleteStreamPayloadLeadsWithSystemPrompt(): void
    {
        $client = $this->createMock(ClientContract::class);

        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $captured = null;
        $chatMock->method('createStreamed')->willReturnCallback(function (array $params) use (&$captured) {
            $captured = $params;

            $body = "data: " . json_encode([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion.chunk',
                'created' => 1,
                'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'delta' => ['content' => 'Hello']]],
            ]) . "\n\ndata: [DONE]\n\n";

            return new StreamResponse(
                CreateStreamedResponse::class,
                new Response(200, [], Utils::streamFor($body)),
            );
        });

        $provider = new OpenAIProvider($client, 'gpt-4o');

        $request = new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('Hello')],
            systemPrompt: 'You are a helpful assistant.',
        );

        $chunks = iterator_to_array($provider->completeStream($request));

        $this->assertSame([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello'],
        ], $captured['messages']);
        $this->assertSame('gpt-4o', $captured['model']);
        $this->assertTrue($captured['stream']);
        $this->assertCount(1, $chunks);
    }

    public function testCompleteStreamWithNullSystemPromptPrependsNothing(): void
    {
        $client = $this->createMock(ClientContract::class);

        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $captured = null;
        $chatMock->method('createStreamed')->willReturnCallback(function (array $params) use (&$captured) {
            $captured = $params;

            $body = "data: " . json_encode([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion.chunk',
                'created' => 1,
                'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'delta' => ['content' => 'Hello']]],
            ]) . "\n\ndata: [DONE]\n\n";

            return new StreamResponse(
                CreateStreamedResponse::class,
                new Response(200, [], Utils::streamFor($body)),
            );
        });

        $provider = new OpenAIProvider($client, 'gpt-4o');

        $request = new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('Hello')],
        );

        iterator_to_array($provider->completeStream($request));

        $this->assertSame([
            ['role' => 'user', 'content' => 'Hello'],
        ], $captured['messages']);
    }

    // -------------------------------------------------------------------------
    // 12b. include_usage streamed billing (audit finding 1's stream half):
    //      the request must set stream_options.include_usage, and the terminal
    //      zero-choice usage frame must fold into a cost-bearing CompleteResponse.
    // -------------------------------------------------------------------------

    public function testCompleteStreamRequestsIncludeUsageAndBillsTerminalFrame(): void
    {
        $client = $this->createMock(ClientContract::class);
        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $captured = null;
        $chatMock->method('createStreamed')->willReturnCallback(function (array $params) use (&$captured) {
            $captured = $params;

            // A real OpenAI stream with the flag on: content chunks, a closing
            // finish chunk, THEN a standalone usage frame with empty choices,
            // then [DONE] (mirrors tests/fixtures/qwen-usage-stream.txt shape).
            $frames = [
                ['id' => 'c1', 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'gpt-4o',
                    'choices' => [['index' => 0, 'delta' => ['content' => 'Hel']]]],
                ['id' => 'c1', 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'gpt-4o',
                    'choices' => [['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => 'stop']]],
                ['id' => 'c1', 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'gpt-4o',
                    'choices' => [],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30]],
            ];
            $body = '';
            foreach ($frames as $f) {
                $body .= 'data: ' . json_encode($f) . "\n\n";
            }
            $body .= "data: [DONE]\n\n";

            return new StreamResponse(
                CreateStreamedResponse::class,
                new Response(200, [], Utils::streamFor($body)),
            );
        });

        $provider = new OpenAIProvider($client, 'gpt-4o');
        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('Hello')],
        )));

        // (1) the flag rode the request,
        $this->assertSame(['include_usage' => true], $captured['stream_options']);

        // (2) content deltas are the first two frames;
        $this->assertSame('Hel', $chunks[0]->content);
        $this->assertSame('lo', $chunks[1]->content);

        // (3) the empty-choices usage frame is NOT yielded as an empty chunk.
        //     It becomes exactly one terminal carrier: two content chunks + one
        //     usage carrier, never a third content-less delta.
        $this->assertCount(3, $chunks);
        $terminal = $chunks[2];
        $this->assertSame('', $terminal->content);

        // (4) the terminal carrier billed the whole stream: 20/10 at gpt-4o
        //     (0.0025/0.01 per 1K) = 0.00005 + 0.0001. Asserted via the same
        //     arithmetic the provider runs, not a rounded literal, so float
        //     representation is not the thing under test.
        $this->assertSame(30, $terminal->tokensUsed);
        $this->assertSame((20 * 0.0025 + 10 * 0.01) / 1000, $terminal->costUsd);
        $this->assertNotNull($terminal->usage);
        $this->assertSame(20, $terminal->usage->inputTokens);
        $this->assertSame(10, $terminal->usage->outputTokens);
    }

    public function testCompleteStreamTerminalUnpricedFrameSignalsBlindness(): void
    {
        $client = $this->createMock(ClientContract::class);
        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        $chatMock->method('createStreamed')->willReturnCallback(function (array $params) {
            $frames = [
                ['id' => 'c1', 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'zzz',
                    'choices' => [['index' => 0, 'delta' => ['content' => 'x'], 'finish_reason' => 'stop']]],
                ['id' => 'c1', 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'zzz',
                    'choices' => [],
                    'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10]],
            ];
            $body = '';
            foreach ($frames as $f) {
                $body .= 'data: ' . json_encode($f) . "\n\n";
            }
            $body .= "data: [DONE]\n\n";

            return new StreamResponse(
                CreateStreamedResponse::class,
                new Response(200, [], Utils::streamFor($body)),
            );
        });

        // 'mystery-model' is unknown AND is what the request billed, so the
        // terminal frame's Usage must carry the name with a 0.0 bound.
        $provider = new OpenAIProvider($client, 'gpt-4o');
        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'mystery-model',
            messages: [new UserMessage('Hello')],
        )));

        $terminal = $chunks[count($chunks) - 1];
        $this->assertSame(0.0, $terminal->costUsd);
        $this->assertSame(10, $terminal->tokensUsed);
        $this->assertSame('mystery-model', $terminal->usage?->unpricedModel);
    }

    public function testCompleteStreamWithoutUsageFrameStaysUnreported(): void
    {
        $client = $this->createMock(ClientContract::class);
        $chatMock = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chatMock);

        // A server that ignores stream_options and sends no usage frame: the
        // honest answer is "nothing reported", so NO terminal carrier is
        // invented and the content chunks sum to a null Usage upstream.
        $chatMock->method('createStreamed')->willReturnCallback(function (array $params) {
            $body = 'data: ' . json_encode([
                'id' => 'c1', 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'delta' => ['content' => 'Hi'], 'finish_reason' => 'stop']],
            ]) . "\n\ndata: [DONE]\n\n";

            return new StreamResponse(
                CreateStreamedResponse::class,
                new Response(200, [], Utils::streamFor($body)),
            );
        });

        $provider = new OpenAIProvider($client, 'gpt-4o');
        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('Hello')],
        )));

        $this->assertCount(1, $chunks);
        $this->assertSame('Hi', $chunks[0]->content);
        $this->assertSame(0, $chunks[0]->tokensUsed);
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
    // Declared-rate validation (billing wave, r90 review pin)
    // -------------------------------------------------------------------------

    public function testDeclaredRatesAreAuthoritativeAndMustValidate(): void
    {
        /** @var ClientContract $client */
        $client = $this->createMock(ClientContract::class);

        // Naming a model makes the operator's map authoritative for it: a
        // rate that fails validation answers UNPRICED (null) and never falls
        // back to the built-in row the override replaced — a silent re-price
        // at a number the operator did not choose is exactly the defect this
        // seam exists to prevent (review r90 / D1).
        $bad = new OpenAIProvider($client, 'gpt-4o', [
            'gpt-4o' => ['input' => -5, 'output' => 10],
            'gpt-4.1' => ['input' => 'banana', 'output' => 8],
            'gpt-4-turbo' => 'not-an-entry',
        ]);
        $this->assertNull($bad->costPer1kTokens('gpt-4o', 'input'), 'a negative declared rate must not bill in the red');
        $this->assertSame(0.01, $bad->costPer1kTokens('gpt-4o', 'output'), 'validation is per direction; the turn still bills null because calculateCost refuses when EITHER side is unpriced');
        $this->assertNull($bad->costPer1kTokens('gpt-4.1', 'input'), 'a non-numeric declared rate falls to unpriced, NOT to the stale built-in row');
        $this->assertNull($bad->costPer1kTokens('gpt-4-turbo', 'input'), 'a malformed entry shape (not an array) reads as declared-but-broken');

        // Non-finite floats cannot arrive through JSON, but the gate refuses
        // them for every caller all the same.
        $nan = new OpenAIProvider($client, 'gpt-4o', ['x' => ['input' => NAN, 'output' => INF]]);
        $this->assertNull($nan->costPer1kTokens('x', 'input'));
        $this->assertNull($nan->costPer1kTokens('x', 'output'));

        // A valid declaration still wins, per direction, zero included.
        $good = new OpenAIProvider($client, 'gpt-4o', [
            'gpt-4o' => ['input' => 1, 'output' => 0],
            'brand-new-model' => ['input' => 2500, 'output' => 10000],
        ]);
        $this->assertSame(0.001, $good->costPer1kTokens('gpt-4o', 'input'), 'USD-per-1M divided once to per-1K');
        $this->assertSame(0.0, $good->costPer1kTokens('gpt-4o', 'output'), 'a declared zero is a legitimate free price');
        $this->assertSame(2.5, $good->costPer1kTokens('brand-new-model', 'input'));

        // Models the operator did NOT name keep the built-in row untouched.
        $this->assertSame(0.002, $good->costPer1kTokens('gpt-4.1', 'input'));

        // The loud-path end-to-end: an unpriceable named model puts its NAME
        // on the Usage (the unpriced signal), with 0.0 as bound — never a
        // fake-free silent zero.
        $usage = $bad->parseUsage(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], 'gpt-4o');
        $this->assertSame(0.0, $usage->costUsd);
        $this->assertSame('gpt-4o', $usage->unpricedModel);
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
