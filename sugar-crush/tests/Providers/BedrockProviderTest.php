<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\Bedrock\BedrockClient;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;

final class BedrockProviderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 1. create() factory creates instance with correct defaults
    // -------------------------------------------------------------------------

    public function testCreateFactoryWithDefaults(): void
    {
        $provider = BedrockProvider::create();

        $this->assertInstanceOf(BedrockProvider::class, $provider);
        $this->assertSame('bedrock', $provider->name());
    }

    public function testCreateFactoryWithCustomRegion(): void
    {
        $provider = BedrockProvider::create('eu-west-1');

        $this->assertInstanceOf(BedrockProvider::class, $provider);
    }

    public function testCreateFactoryWithCustomModel(): void
    {
        $provider = BedrockProvider::create('us-east-1', 'meta.llama3-70b-instruct');

        $this->assertInstanceOf(BedrockProvider::class, $provider);
        $this->assertSame(8_192, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 2. name() returns 'bedrock'
    // -------------------------------------------------------------------------

    public function testNameReturnsBedrock(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame('bedrock', $provider->name());
    }

    public function testNameReturnsBedrockWithCustomModel(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-opus-4-6');

        $this->assertSame('bedrock', $provider->name());
    }

    // -------------------------------------------------------------------------
    // 3. supportsStreaming() returns true
    // -------------------------------------------------------------------------

    public function testSupportsStreamingReturnsTrue(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertTrue($provider->supportsStreaming());
    }

    // -------------------------------------------------------------------------
    // 4. supportsFunctionCalling() returns false
    // -------------------------------------------------------------------------

    public function testSupportsFunctionCallingReturnsFalse(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertFalse($provider->supportsFunctionCalling());
    }

    // -------------------------------------------------------------------------
    // 5. supportsVision() returns false
    // -------------------------------------------------------------------------

    public function testSupportsVisionReturnsFalse(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertFalse($provider->supportsVision());
    }

    // -------------------------------------------------------------------------
    // 6. supportsJsonSchema() returns false
    // -------------------------------------------------------------------------

    public function testSupportsJsonSchemaReturnsFalse(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertFalse($provider->supportsJsonSchema());
    }

    // -------------------------------------------------------------------------
    // 7. contextWindow() returns correct values for known models
    // -------------------------------------------------------------------------

    public function testContextWindowForClaudeOpus(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-opus-4-6');

        $this->assertSame(200_000, $provider->contextWindow());
    }

    public function testContextWindowForClaudeSonnet(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $this->assertSame(200_000, $provider->contextWindow());
    }

    public function testContextWindowForClaudeHaiku(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-haiku-4-7');

        $this->assertSame(200_000, $provider->contextWindow());
    }

    public function testContextWindowForLlama70B(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'meta.llama3-70b-instruct');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    public function testContextWindowForLlama8B(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'meta.llama3-8b-instruct');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 8. contextWindow() returns default value 8192 for unknown models
    // -------------------------------------------------------------------------

    public function testContextWindowForUnknownModelReturnsDefault(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'unknown-model');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    public function testContextWindowForUnknownModelReturnsDefault8192(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'completely-fake-model');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 9. costPer1kTokens() returns correct values for known models
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensForClaudeOpusInput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.015, $provider->costPer1kTokens('anthropic.claude-opus-4-6', 'input'));
    }

    public function testCostPer1kTokensForClaudeOpusOutput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.075, $provider->costPer1kTokens('anthropic.claude-opus-4-6', 'output'));
    }

    public function testCostPer1kTokensForClaudeSonnetInput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.003, $provider->costPer1kTokens('anthropic.claude-sonnet-4-6', 'input'));
    }

    public function testCostPer1kTokensForClaudeSonnetOutput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.015, $provider->costPer1kTokens('anthropic.claude-sonnet-4-6', 'output'));
    }

    public function testCostPer1kTokensForClaudeHaikuInput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00025, $provider->costPer1kTokens('anthropic.claude-haiku-4-7', 'input'));
    }

    public function testCostPer1kTokensForClaudeHaikuOutput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00125, $provider->costPer1kTokens('anthropic.claude-haiku-4-7', 'output'));
    }

    public function testCostPer1kTokensForLlama70BInput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00065, $provider->costPer1kTokens('meta.llama3-70b-instruct', 'input'));
    }

    public function testCostPer1kTokensForLlama70BOutput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00275, $provider->costPer1kTokens('meta.llama3-70b-instruct', 'output'));
    }

    public function testCostPer1kTokensForLlama8BInput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00022, $provider->costPer1kTokens('meta.llama3-8b-instruct', 'input'));
    }

    public function testCostPer1kTokensForLlama8BOutput(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00088, $provider->costPer1kTokens('meta.llama3-8b-instruct', 'output'));
    }

    // -------------------------------------------------------------------------
    // 10. costPer1kTokens() returns default value for unknown models
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensForUnknownModelInputReturnsDefault(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.01, $provider->costPer1kTokens('unknown-model', 'input'));
    }

    public function testCostPer1kTokensForUnknownModelOutputReturnsDefault(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.01, $provider->costPer1kTokens('unknown-model', 'output'));
    }

    // -------------------------------------------------------------------------
    // 11. formatMessages() correctly formats different Message types to Bedrock format
    // -------------------------------------------------------------------------

    public function testFormatMessagesWithUserMessage(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $messages = [new UserMessage('Hello, world!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'Hello, world!']]],
        ], $result);
    }

    public function testFormatMessagesWithAssistantMessage(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $messages = [new AssistantMessage('Hello from assistant!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'assistant', 'content' => [['text' => 'Hello from assistant!']]],
        ], $result);
    }

    public function testFormatMessagesWithSystemMessage(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $messages = [new SystemMessage('You are a helpful assistant.')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        // System messages are wrapped as user role in Bedrock format
        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'You are a helpful assistant.']]],
        ], $result);
    }

    public function testFormatMessagesWithToolResultMessage(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $messages = [new ToolResultMessage('call_123', 'The weather is sunny.')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        // Tool results are wrapped as user role in Bedrock format
        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'The weather is sunny.']]],
        ], $result);
    }

    public function testFormatMessagesWithMultipleMessages(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $messages = [
            new SystemMessage('You are a helpful assistant.'),
            new UserMessage('What is the weather in Tokyo?'),
            new AssistantMessage('Let me check that for you.'),
            new ToolResultMessage('call_123', 'Sunny, 72°F'),
            new AssistantMessage('The weather in Tokyo is sunny with 72°F.'),
        ];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'You are a helpful assistant.']]],
            ['role' => 'user', 'content' => [['text' => 'What is the weather in Tokyo?']]],
            ['role' => 'assistant', 'content' => [['text' => 'Let me check that for you.']]],
            ['role' => 'user', 'content' => [['text' => 'Sunny, 72°F']]],
            ['role' => 'assistant', 'content' => [['text' => 'The weather in Tokyo is sunny with 72°F.']]],
        ], $result);
    }

    // -------------------------------------------------------------------------
    // 12. embeddings() returns empty EmbeddingsResponse
    // -------------------------------------------------------------------------

    public function testEmbeddingsReturnsEmptyEmbeddingsResponse(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $request = new EmbeddingsRequest(
            model: 'amazon.titan-embed-text-v1',
            input: ['Test input'],
        );

        $response = $provider->embeddings($request);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertIsArray($response->embeddings);
        $this->assertCount(0, $response->embeddings);
    }

    public function testEmbeddingsWithMultipleInputsReturnsEmpty(): void
    {
        $client = $this->createMock(BedrockRuntimeClient::class);
        $provider = new BedrockProvider($client);

        $request = new EmbeddingsRequest(
            model: 'cohere.embed-english-v3',
            input: ['First text', 'Second text'],
        );

        $response = $provider->embeddings($request);

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertSame([], $response->embeddings);
    }

    // -------------------------------------------------------------------------
    // 13. The provider is wired to the plane that actually defines Converse
    //
    // These are the regression guards for the defect this suite previously
    // missed entirely: the provider held an `Aws\Bedrock\BedrockClient` (the
    // CONTROL plane) and called `converse()` on it. That call goes through
    // `AwsClient::__call()`, so nothing about it is a compile-time or
    // mock-time error - a `createMock()` of an AWS client answers any magic
    // method happily. Only the real service model settles it, so these tests
    // interrogate the model itself rather than a double.
    // -------------------------------------------------------------------------

    public function testTheRuntimeServiceModelDefinesConverseAndConverseStream(): void
    {
        $api = $this->offlineRuntimeClient(new MockHandler())->getApi();

        $this->assertTrue($api->hasOperation('Converse'), 'bedrock-runtime must define Converse');
        $this->assertTrue($api->hasOperation('ConverseStream'), 'bedrock-runtime must define ConverseStream');
    }

    /**
     * The other half of the guard: naming the class that does NOT define the
     * operation, so a future "simplification" back to the control-plane
     * client fails here with an explanation instead of failing in production.
     */
    public function testTheControlPlaneServiceModelDefinesNoConverseOperation(): void
    {
        $control = new BedrockClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        ]);

        $this->assertFalse(
            $control->getApi()->hasOperation('Converse'),
            'Aws\Bedrock\BedrockClient is the control plane and has no inference operations',
        );
    }

    public function testTheProviderOnlyAcceptsTheRuntimeClient(): void
    {
        $type = (new \ReflectionClass(BedrockProvider::class))
            ->getConstructor()
            ?->getParameters()[0]
            ->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(BedrockRuntimeClient::class, $type->getName());
    }

    public function testCompleteSendsAConverseCommandAndParsesTheReply(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'output' => ['message' => [
                'role' => 'assistant',
                // Reasoning block first, exactly as a thinking-capable model
                // returns it - the old parser read only $content[0]['text']
                // and would have returned the empty string here.
                'content' => [
                    ['reasoningContent' => ['reasoningText' => ['text' => 'weighing it up']]],
                    ['text' => 'Hello'],
                    ['text' => ' there'],
                ],
            ]],
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
        ]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $response = $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
            systemPrompt: 'be brief',
            temperature: 0.2,
            maxTokens: 99,
        ));

        $this->assertSame('Converse', $mock->getLastCommand()->getName());
        $this->assertStringEndsWith(
            '/model/anthropic.claude-sonnet-4-6/converse',
            $mock->getLastRequest()->getUri()->getPath(),
        );

        $sent = $mock->getLastCommand()->toArray();
        $this->assertSame([['text' => 'be brief']], $sent['system']);
        $this->assertSame(['maxTokens' => 99, 'temperature' => 0.2], $sent['inferenceConfig']);

        $this->assertSame('Hello there', $response->content);
        $this->assertSame('weighing it up', $response->reasoning);
        $this->assertSame(15, $response->tokensUsed);
        $this->assertEqualsWithDelta((10 * 0.003 + 5 * 0.015) / 1000, $response->costUsd, 1e-12);
    }

    /**
     * Temperature used to be gated behind maxTokens - the whole
     * inferenceConfig block was only built when maxTokens was non-null - so a
     * temperature-only request silently sampled at the model default.
     */
    public function testCompleteSendsTemperatureEvenWithoutMaxTokens(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]]]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock));
        $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
            temperature: 0.1,
        ));

        $this->assertSame(['temperature' => 0.1], $mock->getLastCommand()->toArray()['inferenceConfig']);
    }

    public function testCompleteFallsBackToTheConfiguredModelWhenTheRequestNamesNone(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]]]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock), 'us-east-1', 'meta.llama3-8b-instruct');
        $provider->complete(new CompleteRequest(model: '', messages: [new UserMessage('hi')]));

        $this->assertSame('meta.llama3-8b-instruct', $mock->getLastCommand()->toArray()['modelId']);
    }

    public function testCompleteWrapsAwsFailuresWithTheModelAndRegion(): void
    {
        $mock = new MockHandler();
        $mock->append(new AwsException(
            'The provided model identifier is invalid.',
            new Command('Converse'),
            ['code' => 'ValidationException'],
        ));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock), 'eu-west-1', 'anthropic.claude-sonnet-4-6');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/anthropic\.claude-sonnet-4-6.*eu-west-1/');

        $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
        ));
    }

    public function testCompleteStreamSendsAConverseStreamCommandAndYieldsDeltas(): void
    {
        $mock = new MockHandler();
        // Shaped exactly as Aws\Api\Parser\EventParsingIterator yields them:
        // one [<eventName> => <payload>] pair per event.
        $mock->append(new Result(['stream' => new \ArrayIterator([
            ['messageStart' => ['role' => 'assistant']],
            ['contentBlockDelta' => ['delta' => ['reasoningContent' => ['text' => 'think']], 'contentBlockIndex' => 0]],
            ['contentBlockDelta' => ['delta' => ['text' => 'Hel'], 'contentBlockIndex' => 1]],
            ['contentBlockDelta' => ['delta' => ['text' => 'lo'], 'contentBlockIndex' => 1]],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => ['inputTokens' => 7, 'outputTokens' => 3]]],
        ])]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $content = '';
        $reasoning = '';
        $tokens = 0;
        foreach ($provider->completeStream(new CompleteRequest(model: 'anthropic.claude-sonnet-4-6', messages: [new UserMessage('hi')])) as $chunk) {
            $content .= $chunk->content;
            $reasoning .= (string) $chunk->reasoning;
            $tokens += $chunk->tokensUsed;
        }

        $this->assertSame('ConverseStream', $mock->getLastCommand()->getName());
        $this->assertStringEndsWith(
            '/model/anthropic.claude-sonnet-4-6/converse-stream',
            $mock->getLastRequest()->getUri()->getPath(),
        );
        $this->assertSame('Hello', $content);
        $this->assertSame('think', $reasoning);
        $this->assertSame(10, $tokens, 'usage arrives once, on the terminal metadata event');
    }

    public function testCompleteStreamDefaultsTheInferenceCeilingButLetsTheRequestWin(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['stream' => new \ArrayIterator([])]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock));
        iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
            maxTokens: 128,
        )));

        $this->assertSame(
            ['maxTokens' => 128, 'temperature' => 0.7],
            $mock->getLastCommand()->toArray()['inferenceConfig'],
        );
    }

    // -------------------------------------------------------------------------
    // 14. Region wiring
    // -------------------------------------------------------------------------

    public function testCreateUsesTheGivenRegionForBothTheClientAndTheProvider(): void
    {
        $provider = BedrockProvider::create('eu-west-1');

        $this->assertSame('eu-west-1', $provider->region());
        $this->assertSame('eu-west-1', (string) self::readClient($provider)->getRegion());
    }

    public function testCreateFallsBackToTheAmbientAwsRegionWhenNoneIsGiven(): void
    {
        $previous = getenv('AWS_REGION');
        putenv('AWS_REGION=ap-southeast-2');

        try {
            $provider = BedrockProvider::create('');

            $this->assertSame('ap-southeast-2', $provider->region());
            $this->assertSame('ap-southeast-2', (string) self::readClient($provider)->getRegion());
        } finally {
            $previous === false ? putenv('AWS_REGION') : putenv('AWS_REGION=' . $previous);
        }
    }

    public function testCreateBuildsARuntimeClientNotAControlPlaneClient(): void
    {
        $this->assertInstanceOf(BedrockRuntimeClient::class, self::readClient(BedrockProvider::create()));
    }

    // -------------------------------------------------------------------------
    // 15. History SystemMessages are hoisted into the Converse `system` array
    //
    // Converse has no per-message `system` role: system text lives in the
    // top-level `system` block list, and `messages` must alternate
    // user/assistant turns. formatMessages() maps a SystemMessage to `user`
    // (a total contract the tests in section 11 pin), so without hoisting a
    // history SystemMessage would sit on the wire as a same-role neighbour
    // of a real user turn - the consecutive-user shape backlog E19 measured.
    // These tests assert the BUILT PAYLOAD directly, through the real SDK
    // serialisation pipeline (offlineRuntimeClient + MockHandler).
    // -------------------------------------------------------------------------

    public function testCompleteHoistsHistorySystemMessagesIntoTheSystemArray(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]]]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock));
        $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new SystemMessage('notice: verify the change'), new UserMessage('hi')],
            systemPrompt: 'be brief',
        ));

        $sent = $mock->getLastCommand()->toArray();
        $this->assertSame(
            [['text' => 'be brief'], ['text' => 'notice: verify the change']],
            $sent['system'],
        );
        $this->assertSame(
            [['role' => 'user', 'content' => [['text' => 'hi']]]],
            $sent['messages'],
        );
    }

    public function testCompleteStreamHoistsHistorySystemMessagesIntoTheSystemArray(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['stream' => new \ArrayIterator([])]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock));
        iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new SystemMessage('notice: verify the change'), new UserMessage('hi')],
            systemPrompt: 'be brief',
        )));

        $sent = $mock->getLastCommand()->toArray();
        $this->assertSame(
            [['text' => 'be brief'], ['text' => 'notice: verify the change']],
            $sent['system'],
        );
        $this->assertSame(
            [['role' => 'user', 'content' => [['text' => 'hi']]]],
            $sent['messages'],
        );
    }

    public function testCompleteSetsSystemFromHistoryAloneWhenNoSystemPrompt(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]]]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock));
        $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new SystemMessage('notice: verify the change'), new UserMessage('hi')],
        ));

        $sent = $mock->getLastCommand()->toArray();
        $this->assertSame(
            [['text' => 'notice: verify the change']],
            $sent['system'],
        );
        $this->assertSame(
            [['role' => 'user', 'content' => [['text' => 'hi']]]],
            $sent['messages'],
        );
    }

    public function testCompleteKeepsSystemAbsentWithoutPromptOrSystemMessages(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]]]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock));
        $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
        ));

        $sent = $mock->getLastCommand()->toArray();
        $this->assertArrayNotHasKey('system', $sent);
        $this->assertSame(
            [['role' => 'user', 'content' => [['text' => 'hi']]]],
            $sent['messages'],
        );
    }

    public function testCompleteHoistsAdjacentSystemMessagesInHistoryOrder(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]]]));

        $provider = new BedrockProvider($this->offlineRuntimeClient($mock));
        $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [
                new SystemMessage('park notice'),
                new UserMessage('the prompt'),
                new SystemMessage('70% reminder'),
                new SystemMessage('compact report'),
            ],
        ));

        $sent = $mock->getLastCommand()->toArray();
        // The measured tail `system user system system` collapses to one user
        // turn; all three SystemMessages ride in `system` in history order.
        $this->assertSame(
            [['text' => 'park notice'], ['text' => '70% reminder'], ['text' => 'compact report']],
            $sent['system'],
        );
        $this->assertSame(
            [['role' => 'user', 'content' => [['text' => 'the prompt']]]],
            $sent['messages'],
        );
    }

    // -------------------------------------------------------------------------
    // Helper: a real runtime client that cannot reach the network
    //
    // Deliberately NOT a mock of the client: a PHPUnit double answers
    // `converse()` through the mocked `__call()` whether or not the operation
    // exists, which is precisely how the original defect survived a 30-test
    // suite. A real client with a MockHandler still runs the SDK's own
    // operation lookup, parameter validation and serialisation, so a call to
    // an operation the service does not define throws.
    // -------------------------------------------------------------------------

    private function offlineRuntimeClient(MockHandler $handler): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $handler,
        ]);
    }

    private static function readClient(BedrockProvider $provider): object
    {
        $property = new \ReflectionProperty(BedrockProvider::class, 'client');

        return $property->getValue($provider);
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
