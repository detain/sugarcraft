<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use Aws\Bedrock\BedrockClient;
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
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame('bedrock', $provider->name());
    }

    public function testNameReturnsBedrockWithCustomModel(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-opus-4-6');

        $this->assertSame('bedrock', $provider->name());
    }

    // -------------------------------------------------------------------------
    // 3. supportsStreaming() returns true
    // -------------------------------------------------------------------------

    public function testSupportsStreamingReturnsTrue(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertTrue($provider->supportsStreaming());
    }

    // -------------------------------------------------------------------------
    // 4. supportsFunctionCalling() returns false
    // -------------------------------------------------------------------------

    public function testSupportsFunctionCallingReturnsFalse(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertFalse($provider->supportsFunctionCalling());
    }

    // -------------------------------------------------------------------------
    // 5. supportsVision() returns false
    // -------------------------------------------------------------------------

    public function testSupportsVisionReturnsFalse(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertFalse($provider->supportsVision());
    }

    // -------------------------------------------------------------------------
    // 6. supportsJsonSchema() returns false
    // -------------------------------------------------------------------------

    public function testSupportsJsonSchemaReturnsFalse(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertFalse($provider->supportsJsonSchema());
    }

    // -------------------------------------------------------------------------
    // 7. contextWindow() returns correct values for known models
    // -------------------------------------------------------------------------

    public function testContextWindowForClaudeOpus(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-opus-4-6');

        $this->assertSame(200_000, $provider->contextWindow());
    }

    public function testContextWindowForClaudeSonnet(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $this->assertSame(200_000, $provider->contextWindow());
    }

    public function testContextWindowForClaudeHaiku(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'anthropic.claude-haiku-4-7');

        $this->assertSame(200_000, $provider->contextWindow());
    }

    public function testContextWindowForLlama70B(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'meta.llama3-70b-instruct');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    public function testContextWindowForLlama8B(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'meta.llama3-8b-instruct');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 8. contextWindow() returns default value 8192 for unknown models
    // -------------------------------------------------------------------------

    public function testContextWindowForUnknownModelReturnsDefault(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'unknown-model');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    public function testContextWindowForUnknownModelReturnsDefault8192(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client, 'us-east-1', 'completely-fake-model');

        $this->assertSame(8_192, $provider->contextWindow());
    }

    // -------------------------------------------------------------------------
    // 9. costPer1kTokens() returns correct values for known models
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensForClaudeOpusInput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.015, $provider->costPer1kTokens('anthropic.claude-opus-4-6', 'input'));
    }

    public function testCostPer1kTokensForClaudeOpusOutput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.075, $provider->costPer1kTokens('anthropic.claude-opus-4-6', 'output'));
    }

    public function testCostPer1kTokensForClaudeSonnetInput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.003, $provider->costPer1kTokens('anthropic.claude-sonnet-4-6', 'input'));
    }

    public function testCostPer1kTokensForClaudeSonnetOutput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.015, $provider->costPer1kTokens('anthropic.claude-sonnet-4-6', 'output'));
    }

    public function testCostPer1kTokensForClaudeHaikuInput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00025, $provider->costPer1kTokens('anthropic.claude-haiku-4-7', 'input'));
    }

    public function testCostPer1kTokensForClaudeHaikuOutput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00125, $provider->costPer1kTokens('anthropic.claude-haiku-4-7', 'output'));
    }

    public function testCostPer1kTokensForLlama70BInput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00065, $provider->costPer1kTokens('meta.llama3-70b-instruct', 'input'));
    }

    public function testCostPer1kTokensForLlama70BOutput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00275, $provider->costPer1kTokens('meta.llama3-70b-instruct', 'output'));
    }

    public function testCostPer1kTokensForLlama8BInput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00022, $provider->costPer1kTokens('meta.llama3-8b-instruct', 'input'));
    }

    public function testCostPer1kTokensForLlama8BOutput(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.00088, $provider->costPer1kTokens('meta.llama3-8b-instruct', 'output'));
    }

    // -------------------------------------------------------------------------
    // 10. costPer1kTokens() returns default value for unknown models
    // -------------------------------------------------------------------------

    public function testCostPer1kTokensForUnknownModelInputReturnsDefault(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.01, $provider->costPer1kTokens('unknown-model', 'input'));
    }

    public function testCostPer1kTokensForUnknownModelOutputReturnsDefault(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $this->assertSame(0.01, $provider->costPer1kTokens('unknown-model', 'output'));
    }

    // -------------------------------------------------------------------------
    // 11. formatMessages() correctly formats different Message types to Bedrock format
    // -------------------------------------------------------------------------

    public function testFormatMessagesWithUserMessage(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $messages = [new UserMessage('Hello, world!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'user', 'content' => [['text' => 'Hello, world!']]],
        ], $result);
    }

    public function testFormatMessagesWithAssistantMessage(): void
    {
        $client = $this->createMock(BedrockClient::class);
        $provider = new BedrockProvider($client);

        $messages = [new AssistantMessage('Hello from assistant!')];

        $result = $this->invokePrivateMethod($provider, 'formatMessages', [$messages]);

        $this->assertSame([
            ['role' => 'assistant', 'content' => [['text' => 'Hello from assistant!']]],
        ], $result);
    }

    public function testFormatMessagesWithSystemMessage(): void
    {
        $client = $this->createMock(BedrockClient::class);
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
        $client = $this->createMock(BedrockClient::class);
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
        $client = $this->createMock(BedrockClient::class);
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
        $client = $this->createMock(BedrockClient::class);
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
        $client = $this->createMock(BedrockClient::class);
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
