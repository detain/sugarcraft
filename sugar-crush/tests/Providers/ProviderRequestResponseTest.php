<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\AssistantMessage;

/**
 * @see CompleteRequest
 * @see CompleteResponse
 * @see EmbeddingsRequest
 * @see EmbeddingsResponse
 */
final class ProviderRequestResponseTest extends TestCase
{
    // =========================================================================
    // CompleteRequest Tests
    // =========================================================================

    public function testCompleteRequestWithRequiredFieldsOnly(): void
    {
        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [['role' => 'user', 'content' => 'Hello']],
        );

        $this->assertSame('gpt-4', $request->model);
        $this->assertIsArray($request->messages);
        $this->assertCount(1, $request->messages);
        $this->assertNull($request->tools);
        $this->assertNull($request->systemPrompt);
        $this->assertNull($request->temperature);
        $this->assertNull($request->maxTokens);
        $this->assertNull($request->jsonSchema);
    }

    public function testCompleteRequestWithAllFields(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Hello'],
        ];
        $tools = [['type' => 'function', 'function' => ['name' => 'test', 'parameters' => []]]];
        $systemPrompt = 'You are a coding assistant';
        $temperature = 0.7;
        $maxTokens = 1000;
        $jsonSchema = ['type' => 'object', 'properties' => []];

        $request = new CompleteRequest(
            model: 'gpt-4-turbo',
            messages: $messages,
            tools: $tools,
            systemPrompt: $systemPrompt,
            temperature: $temperature,
            maxTokens: $maxTokens,
            jsonSchema: $jsonSchema,
        );

        $this->assertSame('gpt-4-turbo', $request->model);
        $this->assertSame($messages, $request->messages);
        $this->assertSame($tools, $request->tools);
        $this->assertSame($systemPrompt, $request->systemPrompt);
        $this->assertSame($temperature, $request->temperature);
        $this->assertSame($maxTokens, $request->maxTokens);
        $this->assertSame($jsonSchema, $request->jsonSchema);
    }

    public function testCompleteRequestReadonlyProperties(): void
    {
        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [],
        );

        $reflection = new \ReflectionClass($request);
        $modelProperty = $reflection->getProperty('model');
        $this->assertTrue($modelProperty->isReadOnly());
    }

    public function testCompleteRequestWithMessageObjects(): void
    {
        $messages = [
            new UserMessage('Hello'),
            new AssistantMessage('Hi there!'),
        ];

        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: $messages,
        );

        $this->assertCount(2, $request->messages);
        $this->assertInstanceOf(UserMessage::class, $request->messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $request->messages[1]);
    }

    public function testCompleteRequestWithEmptyMessagesArray(): void
    {
        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [],
        );

        $this->assertIsArray($request->messages);
        $this->assertCount(0, $request->messages);
    }

    // =========================================================================
    // CompleteResponse Tests
    // =========================================================================

    public function testCompleteResponseWithRequiredFieldsOnly(): void
    {
        $response = new CompleteResponse(
            content: 'Hello, how can I help you?',
        );

        $this->assertSame('Hello, how can I help you?', $response->content);
        $this->assertNull($response->reasoning);
        $this->assertNull($response->toolCalls);
        $this->assertSame(0, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);
    }

    public function testCompleteResponseWithAllFields(): void
    {
        $content = 'AI response';
        $reasoning = 'I think this is the best answer';
        $toolCalls = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{}']]];
        $tokensUsed = 150;
        $costUsd = 0.003;

        $response = new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: $tokensUsed,
            costUsd: $costUsd,
        );

        $this->assertSame($content, $response->content);
        $this->assertSame($reasoning, $response->reasoning);
        $this->assertSame($toolCalls, $response->toolCalls);
        $this->assertSame($tokensUsed, $response->tokensUsed);
        $this->assertSame($costUsd, $response->costUsd);
    }

    public function testCompleteResponseReadonlyProperties(): void
    {
        $response = new CompleteResponse(content: 'Test');

        $reflection = new \ReflectionClass($response);
        $contentProperty = $reflection->getProperty('content');
        $this->assertTrue($contentProperty->isReadOnly());
    }

    public function testCompleteResponseWithEmptyToolCallsArray(): void
    {
        $response = new CompleteResponse(
            content: 'Response',
            toolCalls: [],
        );

        $this->assertSame([], $response->toolCalls);
    }

    public function testCompleteResponseWithZeroTokensAndCost(): void
    {
        $response = new CompleteResponse(content: 'Minimal');

        $this->assertSame(0, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);
    }

    public function testCompleteResponseWithHighTokenCount(): void
    {
        $response = new CompleteResponse(
            content: 'Long response...',
            tokensUsed: 50000,
            costUsd: 1.25,
        );

        $this->assertSame(50000, $response->tokensUsed);
        $this->assertSame(1.25, $response->costUsd);
    }

    // =========================================================================
    // EmbeddingsRequest Tests
    // =========================================================================

    public function testEmbeddingsRequestWithRequiredFields(): void
    {
        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-small',
            input: 'The quick brown fox jumps over the lazy dog',
        );

        $this->assertSame('text-embedding-3-small', $request->model);
        $this->assertSame('The quick brown fox jumps over the lazy dog', $request->input);
    }

    public function testEmbeddingsRequestWithArrayInput(): void
    {
        $input = [
            'First text to embed',
            'Second text to embed',
            'Third text to embed',
        ];

        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-large',
            input: $input,
        );

        $this->assertSame($input, $request->input);
        $this->assertCount(3, $request->input);
    }

    public function testEmbeddingsRequestReadonlyProperties(): void
    {
        $request = new EmbeddingsRequest(
            model: 'test-model',
            input: 'test',
        );

        $reflection = new \ReflectionClass($request);
        $modelProperty = $reflection->getProperty('model');
        $this->assertTrue($modelProperty->isReadOnly());
    }

    public function testEmbeddingsRequestWithEmptyStringInput(): void
    {
        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-small',
            input: '',
        );

        $this->assertSame('', $request->input);
    }

    public function testEmbeddingsRequestWithEmptyArrayInput(): void
    {
        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-small',
            input: [],
        );

        $this->assertIsArray($request->input);
        $this->assertCount(0, $request->input);
    }

    // =========================================================================
    // EmbeddingsResponse Tests
    // =========================================================================

    public function testEmbeddingsResponseWithSingleEmbedding(): void
    {
        $embeddings = [
            [0.123456789, 0.234567890, 0.345678901],
        ];

        $response = new EmbeddingsResponse(embeddings: $embeddings);

        $this->assertSame($embeddings, $response->embeddings);
        $this->assertCount(1, $response->embeddings);
    }

    public function testEmbeddingsResponseWithMultipleEmbeddings(): void
    {
        $embeddings = [
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6],
            [0.7, 0.8, 0.9],
        ];

        $response = new EmbeddingsResponse(embeddings: $embeddings);

        $this->assertCount(3, $response->embeddings);
        $this->assertSame($embeddings, $response->embeddings);
    }

    public function testEmbeddingsResponseReadonlyProperties(): void
    {
        $response = new EmbeddingsResponse(embeddings: []);

        $reflection = new \ReflectionClass($response);
        $embeddingsProperty = $reflection->getProperty('embeddings');
        $this->assertTrue($embeddingsProperty->isReadOnly());
    }

    public function testEmbeddingsResponseWithEmptyEmbeddingsArray(): void
    {
        $response = new EmbeddingsResponse(embeddings: []);

        $this->assertIsArray($response->embeddings);
        $this->assertCount(0, $response->embeddings);
    }

    public function testEmbeddingsResponseEmbeddingDimensions(): void
    {
        // 1536 dimensions is typical for OpenAI's text-embedding-3-small
        $dimensions = 1536;
        $embedding = array_fill(0, $dimensions, 0.0123456789);

        $response = new EmbeddingsResponse(embeddings: [$embedding]);

        $this->assertCount(1, $response->embeddings);
        $this->assertCount($dimensions, $response->embeddings[0]);
    }

    // =========================================================================
    // Edge Cases and Error Handling
    // =========================================================================

    public function testCompleteRequestWithNullOptionalFields(): void
    {
        $request = new CompleteRequest(
            model: 'gpt-4',
            messages: [],
            tools: null,
            systemPrompt: null,
            temperature: null,
            maxTokens: null,
            jsonSchema: null,
        );

        $this->assertNull($request->tools);
        $this->assertNull($request->systemPrompt);
        $this->assertNull($request->temperature);
        $this->assertNull($request->maxTokens);
        $this->assertNull($request->jsonSchema);
    }

    public function testCompleteResponseWithNullOptionalFields(): void
    {
        $response = new CompleteResponse(
            content: 'Content',
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );

        $this->assertNull($response->reasoning);
        $this->assertNull($response->toolCalls);
    }

    public function testEmbeddingsRequestWithLongText(): void
    {
        $longText = str_repeat('Lorem ipsum dolor sit amet. ', 1000);

        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-small',
            input: $longText,
        );

        $this->assertSame($longText, $request->input);
        $this->assertGreaterThan(10000, strlen($request->input));
    }

    public function testEmbeddingsRequestWithSpecialCharacters(): void
    {
        $specialInput = "Hello! 🌍✨\n\tSpecial chars: @#$%^&*()\nNewlines\n";

        $request = new EmbeddingsRequest(
            model: 'text-embedding-3-small',
            input: $specialInput,
        );

        $this->assertSame($specialInput, $request->input);
    }
}
