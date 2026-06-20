<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * VertexProvider tests.
 *
 * VertexProvider's only network dependency is an injectable "predictor" closure
 * (Google's PredictionServiceClient is `final` and credential-bound, so it
 * cannot be mocked directly). Every test below injects a fake predictor and
 * asserts the real request-building / parsing / capability behaviour. No live
 * Google Cloud call is made.
 */
final class VertexProviderTest extends TestCase
{
    /**
     * Builds a provider whose network seam records its inputs and returns a
     * canned prediction struct.
     *
     * @param array<string, mixed> $return
     * @param-out array<string, mixed> $captured
     */
    private function providerWithPredictor(array $return = [], ?array &$captured = null): VertexProvider
    {
        $captured = ['called' => false];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'claude-3-sonnet@20240229',
            predictor: function (string $endpoint, array $instances) use ($return, &$captured): array {
                $captured = [
                    'called' => true,
                    'endpoint' => $endpoint,
                    'instances' => $instances,
                ];

                return $return;
            },
        );
    }

    // -------------------------------------------------------------------------
    // Factory / construction
    // -------------------------------------------------------------------------

    public function testCreateFactoryWithDefaults(): void
    {
        // Default location templates into the endpoint, verified via a
        // complete() round-trip through the seam.
        $captured = null;
        $provider = VertexProvider::create(
            projectId: 'proj-default',
            predictor: function (string $endpoint, array $instances) use (&$captured): array {
                $captured = $endpoint;

                return [];
            },
        );

        $provider->complete(new CompleteRequest(model: 'claude-3-sonnet@20240229', messages: []));

        $this->assertSame(
            'projects/proj-default/locations/us-central1/publishers/anthropic/models/claude-3-sonnet@20240229',
            $captured,
        );
    }

    public function testCreateFactoryWithCustomLocation(): void
    {
        $provider = VertexProvider::create(
            projectId: 'proj-1',
            location: 'europe-west4',
        );

        $this->assertStringContainsString('/locations/europe-west4/', $provider->endpointFor('claude-3-opus@20240229'));
    }

    public function testCreateFactoryWithCustomModel(): void
    {
        $captured = null;
        $provider = VertexProvider::create(
            projectId: 'proj-2',
            location: 'us-central1',
            model: 'ignored-default',
            predictor: function (string $endpoint, array $instances) use (&$captured): array {
                $captured = $endpoint;

                return [];
            },
        );

        // The per-request model wins over the constructor default.
        $provider->complete(new CompleteRequest(model: 'claude-3-opus@20240229', messages: []));

        $this->assertStringEndsWith('/models/claude-3-opus@20240229', $captured);
    }

    // -------------------------------------------------------------------------
    // Capability flags + metadata
    // -------------------------------------------------------------------------

    public function testNameReturnsVertex(): void
    {
        $this->assertSame('vertex', $this->providerWithPredictor()->name());
    }

    public function testSupportsStreamingReturnsFalse(): void
    {
        $this->assertFalse($this->providerWithPredictor()->supportsStreaming());
    }

    public function testSupportsFunctionCallingReturnsFalse(): void
    {
        $this->assertFalse($this->providerWithPredictor()->supportsFunctionCalling());
    }

    public function testSupportsVisionReturnsFalse(): void
    {
        $this->assertFalse($this->providerWithPredictor()->supportsVision());
    }

    public function testSupportsJsonSchemaReturnsFalse(): void
    {
        $this->assertFalse($this->providerWithPredictor()->supportsJsonSchema());
    }

    public function testContextWindowReturns200000(): void
    {
        $this->assertSame(200_000, $this->providerWithPredictor()->contextWindow());
    }

    public function testCostPer1kTokensReturnsZero(): void
    {
        $provider = $this->providerWithPredictor();

        $this->assertSame(0.0, $provider->costPer1kTokens('claude-3-sonnet@20240229', 'input'));
        $this->assertSame(0.0, $provider->costPer1kTokens('claude-3-opus@20240229', 'output'));
    }

    // -------------------------------------------------------------------------
    // complete()
    // -------------------------------------------------------------------------

    public function testCompleteReturnsCompleteResponse(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor(
            ['content' => 'Hello from Vertex', 'reasoning' => 'because'],
            $captured,
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('Hi')],
            temperature: 0.2,
            maxTokens: 256,
        ));

        $this->assertInstanceOf(CompleteResponse::class, $response);
        $this->assertSame('Hello from Vertex', $response->content);
        $this->assertSame('because', $response->reasoning);
        $this->assertFalse($response->isError);

        // The request the provider built reached the seam intact.
        $this->assertTrue($captured['called']);
        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/anthropic/models/claude-3-sonnet@20240229',
            $captured['endpoint'],
        );
        $this->assertSame(0.2, $captured['instances'][0]['temperature']);
        $this->assertSame(256, $captured['instances'][0]['max_tokens']);
        $this->assertSame(
            [['role' => 'user', 'content' => 'Hi']],
            $captured['instances'][0]['messages'],
        );
    }

    public function testCompleteAppliesTemperatureAndTokenDefaults(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor(['content' => 'ok'], $captured);

        $provider->complete(new CompleteRequest(model: 'claude-3-sonnet@20240229', messages: []));

        $this->assertSame(0.7, $captured['instances'][0]['temperature']);
        $this->assertSame(4096, $captured['instances'][0]['max_tokens']);
    }

    public function testCompleteReturnsErrorResponseOnException(): void
    {
        $provider = VertexProvider::create(
            projectId: 'my-project',
            predictor: function (): array {
                throw new \RuntimeException('prediction boom');
            },
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('Hi')],
        ));

        $this->assertTrue($response->isError);
        $this->assertSame('prediction boom', $response->errorMessage);
        $this->assertSame('', $response->content);
    }

    public function testCompleteStreamReturnsGenerator(): void
    {
        $provider = $this->providerWithPredictor();

        $gen = $provider->completeStream(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('Hi')],
        ));

        $this->assertInstanceOf(\Generator::class, $gen);

        $chunks = iterator_to_array($gen);
        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(CompleteResponse::class, $chunks[0]);
        $this->assertSame('', $chunks[0]->content);
    }

    public function testEmbeddingsReturnsEmptyEmbeddingsResponse(): void
    {
        $provider = $this->providerWithPredictor();

        $response = $provider->embeddings(new EmbeddingsRequest(model: 'textembedding-gecko', input: 'hi'));

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertSame([], $response->embeddings);
    }

    // -------------------------------------------------------------------------
    // formatMessages() (private, exercised via reflection)
    // -------------------------------------------------------------------------

    /**
     * @param array<Message> $messages
     * @return array<array{role: string, content: string}>
     */
    private function formatMessages(VertexProvider $provider, array $messages): array
    {
        $method = (new ReflectionClass(VertexProvider::class))->getMethod('formatMessages');
        $method->setAccessible(true);

        /** @var array<array{role: string, content: string}> $result */
        $result = $method->invoke($provider, $messages);

        return $result;
    }

    public function testFormatMessagesWithUserMessage(): void
    {
        $result = $this->formatMessages($this->providerWithPredictor(), [new UserMessage('hello')]);

        $this->assertSame([['role' => 'user', 'content' => 'hello']], $result);
    }

    public function testFormatMessagesWithAssistantMessage(): void
    {
        $result = $this->formatMessages($this->providerWithPredictor(), [new AssistantMessage('sure thing')]);

        $this->assertSame([['role' => 'assistant', 'content' => 'sure thing']], $result);
    }

    public function testFormatMessagesWithSystemMessage(): void
    {
        // SystemMessage is not user/assistant, so it falls through to 'user'.
        $result = $this->formatMessages($this->providerWithPredictor(), [new SystemMessage('be terse')]);

        $this->assertSame([['role' => 'user', 'content' => 'be terse']], $result);
    }

    public function testFormatMessagesWithToolResultMessage(): void
    {
        // ToolResultMessage also falls through to the default 'user' role.
        $result = $this->formatMessages(
            $this->providerWithPredictor(),
            [new ToolResultMessage('call-1', 'tool output')],
        );

        $this->assertSame([['role' => 'user', 'content' => 'tool output']], $result);
    }

    public function testFormatMessagesWithMultipleMessages(): void
    {
        $result = $this->formatMessages($this->providerWithPredictor(), [
            new UserMessage('q1'),
            new AssistantMessage('a1'),
            new UserMessage('q2'),
        ]);

        $this->assertSame([
            ['role' => 'user', 'content' => 'q1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'q2'],
        ], $result);
    }

    // -------------------------------------------------------------------------
    // parseResponse() (private, exercised via reflection)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(VertexProvider $provider, array $data): CompleteResponse
    {
        $method = (new ReflectionClass(VertexProvider::class))->getMethod('parseResponse');
        $method->setAccessible(true);

        /** @var CompleteResponse $result */
        $result = $method->invoke($provider, $data);

        return $result;
    }

    public function testParseResponseWithContentField(): void
    {
        $response = $this->parseResponse($this->providerWithPredictor(), ['content' => 'parsed content']);

        $this->assertSame('parsed content', $response->content);
        $this->assertNull($response->reasoning);
        $this->assertNull($response->toolCalls);
        $this->assertSame(0, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);
    }

    public function testParseResponseWithTextField(): void
    {
        // Falls back to the 'text' key when 'content' is absent.
        $response = $this->parseResponse($this->providerWithPredictor(), ['text' => 'text content']);

        $this->assertSame('text content', $response->content);
    }

    public function testParseResponseWithReasoning(): void
    {
        $response = $this->parseResponse(
            $this->providerWithPredictor(),
            ['content' => 'answer', 'reasoning' => 'step-by-step'],
        );

        $this->assertSame('answer', $response->content);
        $this->assertSame('step-by-step', $response->reasoning);
    }

    public function testParseResponseWithEmptyData(): void
    {
        $response = $this->parseResponse($this->providerWithPredictor(), []);

        $this->assertSame('', $response->content);
        $this->assertNull($response->reasoning);
    }
}
