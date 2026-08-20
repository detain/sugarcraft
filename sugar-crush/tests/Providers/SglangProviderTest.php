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
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
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
        // The default is the id the confirmed deployment serves; MiniMax-M2.7
        // is GONE from that server, so a default naming it 404s on the model
        // name for every request. Both halves asserted: the new id is right AND
        // the retired one is not still in place.
        $this->assertSame('deepseek-ai/DeepSeek-V4-Flash-0731', $this->getPrivateProperty($provider, 'model'));
        $this->assertNotSame('MiniMax-M2.7', $this->getPrivateProperty($provider, 'model'));
        // The exported constant and the parameter default must be the same
        // string - ProviderFactory::defaultConfig() reads the constant, while
        // this factory method reads the default, and a drift between them would
        // give the two entry points different models.
        $this->assertSame(SglangProvider::DEFAULT_MODEL, $this->getPrivateProperty($provider, 'model'));
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
    // 7. supportsJsonSchema() returns true (§12 D4 - jsonSchema is now
    //    forwarded to SGLang's constrained decoding; see
    //    SglangProviderRequestBuildingTest for the request-body coverage)
    // -------------------------------------------------------------------------

    public function testSupportsJsonSchemaReturnsTrue(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertTrue($provider->supportsJsonSchema());
    }

    // -------------------------------------------------------------------------
    // 8. contextWindow() returns 196608
    // -------------------------------------------------------------------------

    /**
     * W1.A6 (§12 D8): the window must be the deployment's real
     * `--context-length 196608`, not the 128,000 that was hardcoded here.
     * Asserted as an exact value (and explicitly NOT the old one) because the
     * whole point of D8 is that the previous figure silently truncated history
     * ~68k tokens early.
     */
    public function testContextWindowReturns196608(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'MiniMax-M2.7', null, $client);

        $this->assertSame(196_608, $provider->contextWindow());
        $this->assertNotSame(128_000, $provider->contextWindow());
    }

    /**
     * The window is MODEL-AWARE now, and it had to become so: 196,608 is the
     * MiniMax-M2.7 deployment's `--context-length`, while the DeepSeek-V4-Flash
     * the server actually runs today accepts `max_req_input_len: 1048570` per
     * its own `/server_info` (read from skynet2 2026-08-20, after a same-day
     * relaunch moved it up from 393216 - see the constant's doc-block, which
     * also explains why this is `max_req_input_len` and not the `max_model_len:
     * 1048576` that `/v1/models` reports six tokens higher). Answering the
     * MiniMax figure for it would now put all four of Chat's context tiers at
     * under a fifth of the real budget.
     *
     * Asserted as the exact figure AND as not-the-other-one, because a
     * single-arm regression is the whole hazard here: both arms returning
     * 196,608 (the bug) or both returning 1,048,570 (handing MiniMax five times
     * the budget its server will hold) are equally wrong, and only a pair of
     * assertions distinguishes them. Note this test pins a TRANSCRIBED figure:
     * it will pass while the constant and the deployment disagree, which is
     * exactly how the 393216 survived its own obsolescence. It guards the
     * model-awareness, not the number's truth.
     */
    public function testContextWindowIsTheDeepSeekV4FigureForADeepSeekV4Model(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'deepseek-ai/DeepSeek-V4-Flash-0731', null, $client);

        $this->assertSame(1_048_570, $provider->contextWindow());
        $this->assertNotSame(196_608, $provider->contextWindow());
    }

    public function testContextWindowKeepsTheLegacyFigureForANonDeepSeekV4Model(): void
    {
        $client = $this->createMock(Client::class);

        // A third model gets the legacy arm too. That is a guess for anything
        // that is neither MiniMax nor DeepSeek-V4 and is documented as one on
        // LEGACY_DEFAULT_CONTEXT_WINDOW; what is pinned here is only that the
        // DeepSeek figure does not leak onto it.
        foreach (['MiniMax-M2.7', 'deepseek-ai/DeepSeek-V3', 'Qwen3-235B'] as $model) {
            $provider = new SglangProvider('https://api.example.com', $model, null, $client);
            $this->assertSame(196_608, $provider->contextWindow(), $model);
        }
    }

    /**
     * The window reads the CONFIGURED model (`$this->model`), which is the only
     * model this method has - it is handed no request. Stated as a test because
     * the sampling defaults in buildParams() read `$request->model` instead,
     * and the asymmetry is deliberate rather than an oversight: the sampling
     * has to match the id the body is addressed to, the window cannot know it.
     */
    public function testContextWindowFollowsTheConfiguredModelNotAnyRequest(): void
    {
        $client = $this->createMock(Client::class);
        $provider = new SglangProvider('https://api.example.com', 'deepseek-ai/DeepSeek-V4-Flash-0731', null, $client);

        $this->assertSame(1_048_570, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 8b. W1.A6 (§12 D6) - pluggable tool-call parser
    // -------------------------------------------------------------------------

    /**
     * The injected parser must be the one that decodes the response, not a
     * hardcoded `tool_calls[]` walk. Driven with a message that carries NO
     * `tool_calls` key at all, so a provider still walking that array inline
     * could only return null.
     */
    public function testCompleteDelegatesToolCallDecodingToTheInjectedParser(): void
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'no tool_calls key here']]],
            'usage' => ['total_tokens' => 3],
        ])));

        $parser = new class implements \SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface {
            public function parse(array $message): ?array
            {
                return [ToolCall::fromArray(['id' => 'stub_1', 'name' => 'stubbed', 'arguments' => []])];
            }
        };

        $provider = new SglangProvider(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            $httpClient,
            $parser,
        );

        $result = $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('Hello')],
        ));

        $this->assertIsArray($result->toolCalls);
        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('stubbed', $result->toolCalls[0]->name());
    }

    public function testOpenAiCompatibleForwardsTheToolCallParserToTheProvider(): void
    {
        $parser = MinimaxXmlFallbackToolCallParser::new(new OpenAiArrayToolCallParser());

        $provider = SglangProvider::openAiCompatible(
            'https://api.example.com',
            'MiniMax-M2.7',
            null,
            $parser,
        );

        $prop = (new \ReflectionClass(SglangProvider::class))->getProperty('toolCallParser');
        $prop->setAccessible(true);

        $this->assertSame($parser, $prop->getValue($provider));
    }

    /**
     * argumentDecoder() exists so a parser built before any provider instance
     * (i.e. by ProviderFactory) keeps the §12 D5 truncation-aware decoding.
     */
    public function testArgumentDecoderDecodesAJsonArgumentsPayload(): void
    {
        $decoder = SglangProvider::argumentDecoder();

        $this->assertSame(['city' => 'Tokyo'], $decoder('{"city":"Tokyo"}', 'get_weather'));
    }

    /**
     * A payload cut short by the MiniMax `</parameter>` bug decodes to no
     * arguments - the point of D5 is that it is *reported*, not that it
     * somehow decodes; the reporting itself is asserted in
     * SglangProviderTruncationGuardTest.
     */
    public function testArgumentDecoderYieldsNoArgumentsForATruncatedPayload(): void
    {
        $decoder = SglangProvider::argumentDecoder();

        // Diverted to a temp file so the (intentional) warning does not spray
        // the test runner's stderr - the assertion here is about the RETURN
        // value; the warning text itself is asserted elsewhere.
        $log = tempnam(sys_get_temp_dir(), 'sglang_log_');
        $previous = ini_set('error_log', $log);

        try {
            $this->assertSame([], $decoder('{"path":"/tmp/a.php","content":"<x', 'write_file'));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
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
            ->with('chat/completions')
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
            ->with('chat/completions')
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
                new Request('POST', 'chat/completions')
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

        // GuzzleHttp\Psr7\Stream only implements plain PSR-7 StreamInterface
        // (read()/eof(), no readLine()) - stub those two real methods so this
        // test exercises the same chunk-and-buffer path production hits,
        // rather than a fictional readLine() that would mask its absence.
        $streamContent = 'data: {"choices":[{"delta":{"content":"Hello"}}]}' . "\n"
            . 'data: {"choices":[{"delta":{"content":" world"}}]}' . "\n"
            . 'data: [DONE]' . "\n";

        $responseBody = $this->getMockBuilder(Stream::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['eof', 'read'])
            ->getMock();
        $responseBody->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $responseBody->method('read')->willReturn($streamContent);

        $response = new Response(200, [], $responseBody);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('chat/completions', $this->callback(static fn ($opts): bool => is_array($opts) && ($opts['stream'] ?? false) === true))
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
                new Request('POST', 'chat/completions')
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
            ->with('embeddings')
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
            ->with('embeddings')
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
                new Request('POST', 'embeddings')
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
    // Regression: a baseUrl with a path suffix (e.g. sglang's OpenAI-compatible
    // '/v1') must survive request-URI resolution instead of being dropped,
    // which previously 404'd every completion request.
    // -------------------------------------------------------------------------

    public function testOpenAiCompatibleWithPathSuffixResolvesRequestsUnderThatPath(): void
    {
        $provider = SglangProvider::openAiCompatible('https://skynet2.interserver.net/v1');

        /** @var Client $client */
        $client = $this->getPrivateProperty($provider, 'httpClient');
        $baseUri = $client->getConfig('base_uri');

        $resolved = \GuzzleHttp\Psr7\UriResolver::resolve($baseUri, \GuzzleHttp\Psr7\Utils::uriFor('chat/completions'));

        $this->assertSame('https://skynet2.interserver.net/v1/chat/completions', (string) $resolved);
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
