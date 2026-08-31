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
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * VertexProvider tests.
 *
 * VertexProvider's only network dependencies are two injectable closures -
 * a unary "predictor" and a "streamer" (Google's PredictionServiceClient is
 * `final` and credential-bound, so it cannot be mocked directly). Every test
 * below injects fakes and asserts the real method selection / request shaping
 * / response parsing behaviour. No live Google Cloud call is made.
 *
 * The central regression these cover: an Anthropic publisher model must be
 * invoked through `rawPredict` (`streamRawPredict` when streaming) carrying a
 * native Anthropic Messages document, NOT through `predict` with Google's
 * `instances` envelope.
 */
final class VertexProviderTest extends TestCase
{
    private const ANTHROPIC_MODEL = 'claude-3-sonnet@20240229';

    /**
     * WAS `GOOGLE_MODEL = 'gemini-1.5-pro-002'`, AND THAT ID WAS WRONG FOR
     * EVERY TEST THAT USED IT.
     *
     * `publishers/google` is two protocols. The `instances`/`context`
     * envelope those tests pin is the PaLM 2 `chat-bison` one; Gemini is not
     * served by it at all and now routes to `:generateContent`
     * ({@see VertexProvider::isGeminiModel()}). So the legacy-arm tests below
     * are re-pointed at a model that really does take the envelope they
     * assert, and every one of their assertions is unchanged - the id was the
     * only thing that moved. The Gemini id keeps its own constant and its own
     * tests.
     */
    private const LEGACY_GOOGLE_MODEL = 'chat-bison@002';

    private const GEMINI_MODEL = 'gemini-1.5-pro-002';

    /**
     * Builds a provider whose unary seam records its inputs and returns a
     * canned response document.
     *
     * @param array<string, mixed> $return
     * @param-out array<string, mixed> $captured
     */
    private function providerWithPredictor(
        array $return = [],
        ?array &$captured = null,
        string $model = self::ANTHROPIC_MODEL,
    ): VertexProvider {
        $captured = ['called' => false];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: function (string $endpoint, string $method, array $body) use ($return, &$captured): array {
                $captured = [
                    'called' => true,
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'body' => $body,
                ];

                return $return;
            },
        );
    }

    /**
     * Builds a provider whose streaming seam records its inputs and replays a
     * canned list of decoded Anthropic SSE events.
     *
     * @param array<int, array<string, mixed>> $events
     * @param-out array<string, mixed> $captured
     */
    private function providerWithStreamer(
        array $events,
        ?array &$captured = null,
        string $model = self::ANTHROPIC_MODEL,
    ): VertexProvider {
        $captured = ['called' => false];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: fn (): array => [],
            streamer: function (string $endpoint, string $method, array $body) use ($events, &$captured): \Generator {
                $captured = [
                    'called' => true,
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'body' => $body,
                ];

                yield from $events;
            },
        );
    }

    // -------------------------------------------------------------------------
    // Factory / construction
    // -------------------------------------------------------------------------

    public function testCreateFactoryWithDefaults(): void
    {
        $captured = null;
        $provider = VertexProvider::create(
            projectId: 'proj-default',
            predictor: function (string $endpoint) use (&$captured): array {
                $captured = $endpoint;

                return [];
            },
        );

        $provider->complete(new CompleteRequest(model: self::ANTHROPIC_MODEL, messages: [new UserMessage('Hi')]));

        $this->assertSame(
            'projects/proj-default/locations/us-central1/publishers/anthropic/models/claude-3-sonnet@20240229',
            $captured,
        );
    }

    public function testCreateFactoryWithCustomLocation(): void
    {
        $provider = VertexProvider::create(projectId: 'proj-1', location: 'europe-west4');

        $this->assertStringContainsString('/locations/europe-west4/', $provider->endpointFor('claude-3-opus@20240229'));
    }

    public function testConstructionDoesNotTouchTheGoogleSdk(): void
    {
        // The default seams are lazy: building a provider with no injected
        // closures must not construct a credential-bound SDK client.
        $provider = VertexProvider::create(projectId: 'proj-lazy');

        $this->assertSame('vertex', $provider->name());
        $this->assertSame('claude-3-sonnet@20240229', $provider->model());
    }

    public function testCreateFactoryWithCustomModel(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, 'ignored-default-claude');

        // The per-request model wins over the constructor default.
        $provider->complete(new CompleteRequest(model: 'claude-3-opus@20240229', messages: [new UserMessage('Hi')]));

        $this->assertStringEndsWith('/models/claude-3-opus@20240229', $captured['endpoint']);
    }

    public function testEmptyRequestModelFallsBackToTheConfiguredModel(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, 'claude-3-haiku@20240307');

        $provider->complete(new CompleteRequest(model: '', messages: [new UserMessage('Hi')]));

        $this->assertStringEndsWith('/models/claude-3-haiku@20240307', $captured['endpoint']);
    }

    // -------------------------------------------------------------------------
    // Endpoint / publisher routing
    // -------------------------------------------------------------------------

    public function testEndpointForAnthropicModelUsesTheAnthropicPublisher(): void
    {
        $provider = $this->providerWithPredictor();

        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/anthropic/models/claude-3-5-sonnet-v2@20241022',
            $provider->endpointFor('claude-3-5-sonnet-v2@20241022'),
        );
    }

    public function testEndpointForNonAnthropicModelUsesTheGooglePublisher(): void
    {
        $provider = $this->providerWithPredictor();

        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/google/models/chat-bison@002',
            $provider->endpointFor(self::LEGACY_GOOGLE_MODEL),
        );
    }

    public function testEndpointForAGeminiModelIsUNCHANGEDByTheGeminiSplit(): void
    {
        // The Gemini arm changes the RPC and the request document, NOT the
        // resource name: Gemini is `publishers/google` exactly as PaLM 2 is.
        // This is the byte-identical string the old
        // testEndpointForNonAnthropicModelUsesTheGooglePublisher asserted for
        // this id before the split, kept so the split cannot silently have
        // moved the publisher segment as well.
        $provider = $this->providerWithPredictor();

        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/google/models/gemini-1.5-pro-002',
            $provider->endpointFor(self::GEMINI_MODEL),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableModelIdProvider(): array
    {
        return [
            // A tuned model's own resource name double-nests: it would be
            // templated as `.../publishers/anthropic/models/projects/...`.
            'tuned model resource name' => ['projects/p/locations/us-central1/models/my-claude-tuned'],
            // So does a model id someone spelled as a publisher path.
            'publisher-qualified id' => ['publishers/anthropic/models/claude-3-5-sonnet'],
            'bare slash' => ['a/b'],
        ];
    }

    /**
     * @dataProvider unusableModelIdProvider
     */
    public function testEndpointForRejectsAModelIdThatIsAResourcePath(string $model): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is a resource path, not a model id');

        $this->providerWithPredictor()->endpointFor($model);
    }

    public function testEndpointForRejectsAnEmptyModelId(): void
    {
        // modelId() guards an empty REQUEST model by falling back to the
        // configured one - but an empty CONFIGURED model reaches here and would
        // template a resource name ending in a bare `/models/`.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Vertex model id is empty');

        VertexProvider::create(projectId: 'p', model: '')->endpointFor('');
    }

    public function testCompleteSurfacesAnUnusableModelIdAsAnErrorResponse(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $response = $provider->complete(new CompleteRequest(
            model: 'publishers/anthropic/models/claude-3-5-sonnet',
            messages: [new UserMessage('Hi')],
        ));

        $this->assertTrue($response->isError);
        $this->assertStringContainsString('is a resource path, not a model id', (string) $response->errorMessage);
        $this->assertFalse($captured['called'], 'a malformed resource name must never reach the wire');
    }

    public function testCompleteStreamSurfacesAnUnusableModelIdAsAnErrorChunk(): void
    {
        $captured = null;
        $provider = $this->providerWithStreamer([], $captured);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'projects/p/locations/us-central1/models/my-claude-tuned',
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertStringContainsString('is a resource path, not a model id', (string) $chunks[0]->errorMessage);
        $this->assertFalse($captured['called']);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function modelFamilyProvider(): array
    {
        return [
            'versioned claude' => ['claude-3-5-sonnet-v2@20241022', true],
            'unversioned claude' => ['claude-sonnet-4-5', true],
            'mixed case claude' => ['Claude-3-Opus@20240229', true],
            'gemini' => ['gemini-1.5-pro-002', false],
            'text-bison' => ['text-bison@002', false],
        ];
    }

    /**
     * @dataProvider modelFamilyProvider
     */
    public function testIsAnthropicModel(string $model, bool $expected): void
    {
        $this->assertSame($expected, $this->providerWithPredictor()->isAnthropicModel($model));
    }

    // -------------------------------------------------------------------------
    // Capability flags + metadata
    // -------------------------------------------------------------------------

    public function testNameReturnsVertex(): void
    {
        $this->assertSame('vertex', $this->providerWithPredictor()->name());
    }

    public function testAnthropicDefaultModelReportsStreamingAndToolSupport(): void
    {
        $provider = $this->providerWithPredictor();

        $this->assertTrue($provider->supportsStreaming());
        $this->assertTrue($provider->supportsFunctionCalling());
    }

    public function testGoogleDefaultModelReportsNoStreamingOrToolSupport(): void
    {
        $provider = $this->providerWithPredictor([], $unused, self::LEGACY_GOOGLE_MODEL);

        $this->assertFalse($provider->supportsStreaming());
        $this->assertFalse($provider->supportsFunctionCalling());
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

        $this->assertSame(0.0, $provider->costPer1kTokens(self::ANTHROPIC_MODEL, 'input'));
        $this->assertSame(0.0, $provider->costPer1kTokens('claude-3-opus@20240229', 'output'));
    }

    // -------------------------------------------------------------------------
    // complete() - Anthropic publisher models go through rawPredict
    // -------------------------------------------------------------------------

    public function testCompleteSelectsRawPredictForAnthropicModels(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(model: self::ANTHROPIC_MODEL, messages: [new UserMessage('Hi')]));

        $this->assertTrue($captured['called']);
        $this->assertSame('rawPredict', $captured['method']);
        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/anthropic/models/claude-3-sonnet@20240229',
            $captured['endpoint'],
        );
    }

    public function testCompleteSendsTheNativeAnthropicBodyNotTheGoogleEnvelope(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
            temperature: 0.2,
            maxTokens: 256,
        ));

        $body = $captured['body'];

        $this->assertSame('vertex-2023-10-16', $body['anthropic_version']);
        $this->assertSame(256, $body['max_tokens']);
        $this->assertSame(0.2, $body['temperature']);
        $this->assertSame(
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
            $body['messages'],
        );

        // The Google `predict` envelope must be entirely absent...
        $this->assertArrayNotHasKey('instances', $body);
        $this->assertArrayNotHasKey('parameters', $body);
        // ...and the model belongs in the URL, never in the body.
        $this->assertArrayNotHasKey('model', $body);
        // A non-streaming rawPredict must not ask for SSE.
        $this->assertArrayNotHasKey('stream', $body);
    }

    public function testCompleteAppliesTemperatureAndTokenDefaults(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(model: self::ANTHROPIC_MODEL, messages: [new UserMessage('Hi')]));

        $this->assertSame(0.7, $captured['body']['temperature']);
        $this->assertSame(4096, $captured['body']['max_tokens']);
    }

    public function testCompleteHoistsSystemPromptAndSystemMessagesOutOfMessages(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new SystemMessage('be terse'), new UserMessage('Hi')],
            systemPrompt: 'you are a bot',
        ));

        $body = $captured['body'];

        $this->assertSame("you are a bot\n\nbe terse", $body['system']);
        $this->assertSame(
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
            $body['messages'],
            'a `system` role inside messages is a 400 on the Anthropic API',
        );
    }

    public function testCompleteOmitsSystemWhenThereIsNone(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(model: self::ANTHROPIC_MODEL, messages: [new UserMessage('Hi')]));

        $this->assertArrayNotHasKey('system', $captured['body']);
    }

    public function testCompleteSendsSamplingAndStopSequences(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
            topP: 0.9,
            topK: 40,
            stop: ['STOP', ''],
        ));

        $this->assertSame(0.9, $captured['body']['top_p']);
        $this->assertSame(40, $captured['body']['top_k']);
        $this->assertSame(['STOP'], $captured['body']['stop_sequences']);
    }

    public function testCompleteSendsToolsInTheFlatAnthropicShape(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
            tools: [$this->fakeTool()],
        ));

        $tools = $captured['body']['tools'];

        $this->assertCount(1, $tools);
        $this->assertSame('reader', $tools[0]['name']);
        $this->assertSame('reads a file', $tools[0]['description']);
        // Anthropic spells it `input_schema`, not OpenAI's
        // `{type: function, function: {parameters: …}}` wrapper.
        $this->assertArrayHasKey('input_schema', $tools[0]);
        $this->assertArrayNotHasKey('function', $tools[0]);
        $this->assertSame('object', $tools[0]['input_schema']['type']);
        // An empty `properties` map must survive json_encode() as an object.
        $this->assertInstanceOf(\stdClass::class, $tools[0]['input_schema']['properties']);
    }

    public function testCompleteReplaysAssistantToolCallsAndToolResults(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [
                new UserMessage('read it'),
                new AssistantMessage('on it', [new ToolCall('call-1', 'reader', ['path' => 'a.txt'])]),
                new ToolResultMessage('call-1', 'file body'),
            ],
        ));

        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'read it']]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'on it'],
                ['type' => 'tool_use', 'id' => 'call-1', 'name' => 'reader', 'input' => ['path' => 'a.txt']],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'call-1', 'content' => 'file body'],
            ]],
        ], $captured['body']['messages']);
    }

    public function testCompleteMarksFailedToolResults(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new ToolResultMessage('call-9', 'boom', isError: true)],
        ));

        $this->assertTrue($captured['body']['messages'][0]['content'][0]['is_error']);
    }

    public function testCompleteMergesConsecutiveSameRoleTurns(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        // Two tool results in a row are two `user` turns, which Anthropic
        // rejects unless they are merged into one.
        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [
                new ToolResultMessage('call-1', 'first'),
                new ToolResultMessage('call-2', 'second'),
            ],
        ));

        $messages = $captured['body']['messages'];

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertCount(2, $messages[0]['content']);
    }

    public function testCompleteDropsEmptyTurns(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage(''), new UserMessage('real')],
        ));

        $this->assertSame(
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'real']]]],
            $captured['body']['messages'],
        );
    }

    public function testCompleteEncodesArgumentlessToolCallInputAsAnObject(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new AssistantMessage('', [new ToolCall('call-1', 'ping', [])])],
        ));

        $block = $captured['body']['messages'][0]['content'][0];

        $this->assertInstanceOf(\stdClass::class, $block['input']);
        $this->assertStringContainsString('"input":{}', (string) json_encode($block));
    }

    public function testCompleteTranslatesOpenAiShapedToolCallArrays(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        // A transcript replayed from disk carries plain arrays, not objects.
        $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new AssistantMessage('', [[
                'id' => 'call-7',
                'type' => 'function',
                'function' => ['name' => 'reader', 'arguments' => '{"path":"b.txt"}'],
            ]])],
        ));

        $this->assertSame(
            ['type' => 'tool_use', 'id' => 'call-7', 'name' => 'reader', 'input' => ['path' => 'b.txt']],
            $captured['body']['messages'][0]['content'][0],
        );
    }

    // -------------------------------------------------------------------------
    // complete() - Anthropic response parsing
    // -------------------------------------------------------------------------

    public function testCompleteParsesAnthropicContentBlocks(): void
    {
        $provider = $this->providerWithPredictor([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'step-by-step'],
                ['type' => 'text', 'text' => 'Hello from '],
                ['type' => 'text', 'text' => 'Vertex'],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $response = $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertInstanceOf(CompleteResponse::class, $response);
        $this->assertSame('Hello from Vertex', $response->content);
        $this->assertSame('step-by-step', $response->reasoning);
        $this->assertSame(15, $response->tokensUsed);
        $this->assertFalse($response->isError);
    }

    public function testCompleteParsesToolUseBlocks(): void
    {
        $provider = $this->providerWithPredictor([
            'content' => [
                ['type' => 'text', 'text' => 'calling'],
                ['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'reader', 'input' => ['path' => 'a.txt']],
            ],
        ]);

        $response = $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertCount(1, $response->toolCalls);
        $this->assertInstanceOf(ToolCall::class, $response->toolCalls[0]);
        $this->assertSame('tu-1', $response->toolCalls[0]->id());
        $this->assertSame('reader', $response->toolCalls[0]->name());
        $this->assertSame(['path' => 'a.txt'], $response->toolCalls[0]->arguments());
    }

    public function testCompleteReturnsNullToolCallsWhenThereAreNone(): void
    {
        $provider = $this->providerWithPredictor(['content' => [['type' => 'text', 'text' => 'hi']]]);

        $response = $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertNull($response->toolCalls);
        $this->assertNull($response->reasoning);
    }

    public function testCompleteSurfacesAnAnthropicErrorEnvelope(): void
    {
        $provider = $this->providerWithPredictor([
            'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens is required'],
        ]);

        $response = $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertTrue($response->isError);
        $this->assertSame('max_tokens is required', $response->errorMessage);
    }

    /**
     * A rawPredict failure arrives as a 200 carrying an error OBJECT, so the
     * decoded `type` is the only place its transience is visible — no HTTP
     * status, no exception (crush_code.md Phase 5 item 8).
     *
     * `TransientFailure::anthropicErrorIsTransient()` is unit-tested on its own;
     * what this pins is that `parseAnthropicResponse()` calls it. With the
     * argument dropped the flag stays null, `responseIsTransient()` reads null as
     * permanent, and an overloaded backend stops being retried through this seam
     * without a single other test noticing. Both verdicts asserted so a
     * hardcoded `true` dies here too.
     */
    public function testARawPredictErrorObjectCarriesItsTransientVerdict(): void
    {
        $overloaded = $this->providerWithPredictor([
            'error' => ['type' => 'overloaded_error', 'message' => 'overloaded'],
        ]);
        $malformed = $this->providerWithPredictor([
            'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens is required'],
        ]);

        $request = new CompleteRequest(model: self::ANTHROPIC_MODEL, messages: [new UserMessage('Hi')]);

        $this->assertTrue($overloaded->complete($request)->errorTransient);
        $this->assertFalse($malformed->complete($request)->errorTransient);
    }

    public function testCompleteRejectsAnEmptyTranscriptLocally(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $response = $provider->complete(new CompleteRequest(model: self::ANTHROPIC_MODEL, messages: []));

        $this->assertTrue($response->isError);
        $this->assertStringContainsString('at least one user or assistant turn', (string) $response->errorMessage);
        $this->assertFalse($captured['called'], 'an empty `messages` list is a 400 - do not spend a round trip on it');
    }

    public function testCompleteRejectsASystemOnlyTranscriptLocally(): void
    {
        // Every SystemMessage is hoisted into the top-level `system` field, so
        // a system-only transcript still leaves `messages` empty.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured);

        $response = $provider->complete(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new SystemMessage('be terse')],
        ));

        $this->assertTrue($response->isError);
        $this->assertStringContainsString('at least one user or assistant turn', (string) $response->errorMessage);
        $this->assertFalse($captured['called']);
    }

    public function testCompleteStreamRejectsAnEmptyTranscriptAsAnErrorChunk(): void
    {
        $captured = null;
        $provider = $this->providerWithStreamer([], $captured);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertStringContainsString('at least one user or assistant turn', (string) $chunks[0]->errorMessage);
        $this->assertFalse($captured['called']);
    }

    public function testGoogleModelsStillAcceptAnEmptyTranscript(): void
    {
        // The `instances` envelope has no such requirement, so the legacy
        // Google path must not have picked the guard up.
        $captured = null;
        $provider = $this->providerWithPredictor(['content' => 'ok'], $captured, self::LEGACY_GOOGLE_MODEL);

        $response = $provider->complete(new CompleteRequest(model: self::LEGACY_GOOGLE_MODEL, messages: []));

        $this->assertFalse($response->isError);
        $this->assertTrue($captured['called']);
        $this->assertSame([], $captured['body']['instances'][0]['messages']);
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
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertTrue($response->isError);
        $this->assertSame('prediction boom', $response->errorMessage);
        $this->assertSame('', $response->content);
    }

    // -------------------------------------------------------------------------
    // complete() - the legacy Google `predict` path is still served
    // -------------------------------------------------------------------------

    public function testCompleteSelectsPredictAndTheInstancesEnvelopeForGoogleModels(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::LEGACY_GOOGLE_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new UserMessage('Hi')],
            temperature: 0.3,
            maxTokens: 512,
        ));

        $this->assertSame('predict', $captured['method']);
        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/google/models/chat-bison@002',
            $captured['endpoint'],
        );
        $this->assertSame(
            [['messages' => [['role' => 'user', 'content' => 'Hi']]]],
            $captured['body']['instances'],
        );
        $this->assertSame(0.3, $captured['body']['parameters']['temperature']);
        $this->assertSame(512, $captured['body']['parameters']['maxOutputTokens']);
        $this->assertArrayNotHasKey('anthropic_version', $captured['body']);
    }

    // -------------------------------------------------------------------------
    // The Google `instances` envelope carries the assembled system prompt in
    // `instances[0].context`. Until this was fixed, googleBody() never read
    // CompleteRequest::$systemPrompt at all, so every publishers/google model
    // was answered a prompt-less turn - on the unary path and, because
    // completeStream() delegates to complete() for a non-Anthropic model, on
    // the streaming path too.
    // -------------------------------------------------------------------------

    public function testCompleteHoistsTheAssembledSystemPromptIntoTheGoogleInstanceContext(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::LEGACY_GOOGLE_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new SystemMessage('be terse'), new UserMessage('Hi')],
            systemPrompt: 'you are a bot',
        ));

        $this->assertSame(
            [[
                'messages' => [['role' => 'user', 'content' => 'Hi']],
                'context' => "you are a bot\n\nbe terse",
            ]],
            $captured['body']['instances'],
            'the Google predict envelope carries the system instruction in '
            . 'instances[0].context; leaving it in messages renders it as a user turn',
        );
    }

    public function testTheGooglePromptRidesContextAndNowhereElseInTheBody(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::LEGACY_GOOGLE_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new SystemMessage('SENTINEL-be-terse'), new UserMessage('Hi')],
        ));

        // The name's promise, asserted: the sentinel is IN `context`. Without
        // this line the only assertion below is a COUNT, and master's unfixed
        // body satisfies that count too - formatMessages()'s
        // `default => 'user'` arm renders the SystemMessage as exactly one
        // user turn, so the sentinel still appears exactly once. MEASURED: on
        // a full revert of googleBody() to the master body this line reds and
        // the count below does not.
        $this->assertSame('SENTINEL-be-terse', $captured['body']['instances'][0]['context']);

        // A hoist that forgets to drop the SystemMessage from `messages`
        // transmits it twice - once as context, once as a `user` turn, which
        // is exactly what formatMessages()'s `default => 'user'` arm does.
        $this->assertSame(
            1,
            substr_count((string) json_encode($captured['body']), 'SENTINEL-be-terse'),
        );
    }

    public function testCompleteStreamHoistsTheAssembledSystemPromptIntoTheGoogleInstanceContext(): void
    {
        // completeStream() yields complete() for a Google model, so the
        // capture happens on the PREDICTOR seam, not the streamer - which is
        // the whole reason the unary path passing is not evidence here.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::LEGACY_GOOGLE_MODEL);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: 'you are a bot',
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertSame('predict', $captured['method']);
        $this->assertSame('you are a bot', $captured['body']['instances'][0]['context']);
    }

    /**
     * NEGATIVE-POLARITY CONTROL, AND IT IS NOT EVIDENCE OF THE FIX.
     *
     * Stated plainly because the honest reading is not obvious: this test is
     * GREEN against the unfixed master body. Master's googleBody() never
     * emits `context` at all, so "no context key" is trivially true there.
     * MEASURED - a full revert of googleBody() to the master body reds six
     * tests in this file and leaves this one and its streaming sibling
     * {@see testCompleteStreamGoogleInstanceHasNoContextKeyWithoutASystemPrompt()}
     * green.
     *
     * What it DOES catch is the opposite mutation, which no positive test
     * can: dropping the `if ($context !== null)` guard in googleBody() so the
     * key is written unconditionally. MEASURED - that mutation reds this test
     * and its streaming sibling. Rule 16: an instrument that only ever says
     * "found" is indistinguishable from a dead one, so the absence is pinned
     * deliberately rather than left to chance.
     */
    public function testGoogleInstanceHasNoContextKeyWithoutASystemPrompt(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::LEGACY_GOOGLE_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertArrayNotHasKey('context', $captured['body']['instances'][0]);
    }

    public function testCompleteStreamGoogleInstanceHasNoContextKeyWithoutASystemPrompt(): void
    {
        // The NEGATIVE polarity on the STREAMING path. Its sibling above
        // drives complete(); this whole change exists because "complete()
        // passing" is not evidence about completeStream(), so the absent-prompt
        // case is pinned independently on both paths rather than once.
        //
        // The method assertion is not decoration: completeStream() reaches
        // this body only by delegating to complete() for a non-Anthropic model
        // (VertexProvider.php:290-298). Without it, a regression that stopped
        // delegating would leave $captured untouched from a seam that was
        // never called, and an assertArrayNotHasKey on a body nobody built
        // would pass for the wrong reason.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::LEGACY_GOOGLE_MODEL);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertSame('predict', $captured['method']);
        $this->assertArrayNotHasKey('context', $captured['body']['instances'][0]);
        $this->assertSame(
            [['role' => 'user', 'content' => 'Hi']],
            $captured['body']['instances'][0]['messages'],
        );
    }

    public function testGoogleInstanceHasNoContextKeyForAnEmptySystemPrompt(): void
    {
        // The empty string is the pathological input the `!== null` guard the
        // OpenAI and Bedrock arms use would let through as an empty context.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::LEGACY_GOOGLE_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new UserMessage('Hi'), new SystemMessage('')],
            systemPrompt: '',
        ));

        $this->assertArrayNotHasKey('context', $captured['body']['instances'][0]);
        $this->assertSame(
            [['role' => 'user', 'content' => 'Hi']],
            $captured['body']['instances'][0]['messages'],
        );
    }

    public function testGoogleInstanceContextJoinsEveryHistorySystemMessageInMessageOrder(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::LEGACY_GOOGLE_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [
                new SystemMessage('first'),
                new SystemMessage('second'),
                new UserMessage('Hi'),
                new SystemMessage('third'),
            ],
        ));

        $this->assertSame(
            "first\n\nsecond\n\nthird",
            $captured['body']['instances'][0]['context'],
        );
        $this->assertSame(
            [['role' => 'user', 'content' => 'Hi']],
            $captured['body']['instances'][0]['messages'],
            'every hoisted SystemMessage must leave `messages`, or each one returns as a user turn',
        );
    }

    public function testAGoogleSystemMessageOnlyTranscriptYieldsAnEmptyMessagesList(): void
    {
        // A NEW ROUTE TO `messages: []`, PINNED AS A DECISION RATHER THAN LEFT
        // AS AN ACCIDENT (16.2 "pin absences deliberately"). Hoisting every
        // SystemMessage out of `messages` means a transcript that is NOTHING
        // BUT SystemMessages - non-empty on the way in - leaves an EMPTY
        // `messages` list on the way out. Before the hoist the same input
        // produced two `role: user` turns carrying the instruction text.
        //
        // The Anthropic arm rejects exactly this input with a named
        // InvalidArgumentException (VertexProvider.php:435-446), pinned by
        // {@see testCompleteRejectsASystemOnlyTranscriptLocally()}. This arm
        // does not, matching the no-guard position
        // {@see testGoogleModelsStillAcceptAnEmptyTranscript()} already
        // records for the empty transcript: the `instances` envelope states no
        // minimum-turn requirement. Both polarities of that asymmetry are now
        // pinned, so flipping either one is a visible red rather than a quiet
        // behaviour change.
        $captured = null;
        $provider = $this->providerWithPredictor(['content' => 'ok'], $captured, self::LEGACY_GOOGLE_MODEL);

        $response = $provider->complete(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new SystemMessage('only'), new SystemMessage('this')],
            systemPrompt: 'asm',
        ));

        // No throw, no error response: the Google arm accepts it.
        $this->assertFalse($response->isError);
        $this->assertTrue($captured['called']);

        // The exact body, not a shape assertion on part of it.
        $this->assertSame(
            [
                'instances' => [[
                    'messages' => [],
                    'context' => "asm\n\nonly\n\nthis",
                ]],
                'parameters' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 4096,
                ],
            ],
            $captured['body'],
        );

        // The instruction text rides `context` once and is not also replayed
        // as a user turn - the pre-hoist behaviour this route replaced.
        $this->assertSame(1, substr_count((string) json_encode($captured['body']), 'only'));
        $this->assertSame(1, substr_count((string) json_encode($captured['body']), 'this'));
    }

    public function testCompleteParsesTheLegacyGooglePredictionShape(): void
    {
        $provider = $this->providerWithPredictor(
            ['content' => 'legacy content', 'reasoning' => 'because'],
            $unused,
            self::LEGACY_GOOGLE_MODEL,
        );

        $response = $provider->complete(new CompleteRequest(model: self::LEGACY_GOOGLE_MODEL, messages: []));

        $this->assertSame('legacy content', $response->content);
        $this->assertSame('because', $response->reasoning);
    }

    public function testCompleteUnwrapsAPredictionsEnvelope(): void
    {
        $provider = $this->providerWithPredictor(
            ['predictions' => [['text' => 'wrapped']]],
            $unused,
            self::LEGACY_GOOGLE_MODEL,
        );

        $response = $provider->complete(new CompleteRequest(model: self::LEGACY_GOOGLE_MODEL, messages: []));

        $this->assertSame('wrapped', $response->content);
    }

    // =========================================================================
    // Gemini — `:generateContent` / `:streamGenerateContent`
    //
    // `publishers/google` is TWO protocols. Everything above this line is the
    // PaLM 2 `chat-bison` `instances`/`context` envelope, which Gemini does
    // not read. These drive the third arm.
    //
    // EPISTEMIC STATUS, once for the section: no test here has Vertex
    // credentials, so every assertion is about the DOCUMENT THIS CLASS BUILDS
    // (and, in the call-site tests further down, about the protobuf request
    // and the serialized HTTP body the vendored transport is handed). That
    // the deployed service accepts that document is UNVERIFIED here.
    // =========================================================================

    public function testGeminiDefaultModelReportsStreamingButNoToolSupport(): void
    {
        $provider = $this->providerWithPredictor([], $unused, self::GEMINI_MODEL);

        // Streaming is TRUE and that is a deliberate move from the flag this
        // id used to report: `:streamGenerateContent` is a real route now, so
        // reporting false would be a lie about a path that works.
        $this->assertTrue($provider->supportsStreaming());

        // Function calling stays FALSE, and that is not a lie: Gemini supports
        // it, this class has no shaper for it. See supportsFunctionCalling().
        $this->assertFalse($provider->supportsFunctionCalling());
    }

    public function testCompleteSelectsGenerateContentAndTheContentsEnvelopeForGeminiModels(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
            temperature: 0.3,
            maxTokens: 512,
        ));

        $this->assertSame('generateContent', $captured['method']);
        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/google/models/gemini-1.5-pro-002',
            $captured['endpoint'],
        );

        // THE WHOLE BODY, not a shape assertion on part of it. The `instances`
        // wrapper and the `parameters` map must both be ABSENT: this arm
        // exists precisely because a Gemini id used to be handed them.
        $this->assertSame(
            [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
                'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 512],
            ],
            $captured['body'],
        );
        $this->assertArrayNotHasKey('instances', $captured['body']);
        $this->assertArrayNotHasKey('parameters', $captured['body']);
        $this->assertArrayNotHasKey('anthropic_version', $captured['body']);
    }

    public function testGeminiRoleVocabularyIsUserAndModelNeverAssistant(): void
    {
        // THE ONE WORD THAT FORCED A SEPARATE FORMATTER. formatMessages(),
        // which the legacy arm still uses unchanged, emits `assistant`;
        // Gemini does not know that role. A regression that reused the legacy
        // formatter here would put `assistant` in `contents` and reds this.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [
                new UserMessage('one'),
                new AssistantMessage('two'),
                new UserMessage('three'),
            ],
        ));

        $this->assertSame(
            [
                ['role' => 'user', 'parts' => [['text' => 'one']]],
                ['role' => 'model', 'parts' => [['text' => 'two']]],
                ['role' => 'user', 'parts' => [['text' => 'three']]],
            ],
            $captured['body']['contents'],
        );
        $this->assertSame(
            0,
            substr_count((string) json_encode($captured['body']), 'assistant'),
            'Gemini\'s assistant role is spelled `model`; the string `assistant` must not '
            . 'appear anywhere in the body',
        );
    }

    public function testGeminiContentsAreNotMergedAcrossConsecutiveSameRoleTurns(): void
    {
        // A DECISION, PINNED (16.2). formatAnthropicMessages() MUST merge
        // consecutive same-role turns because Anthropic rejects them. Whether
        // Gemini requires the same is UNVERIFIED here - nothing in this repo
        // can call the service - so the transcript is transmitted
        // turn-for-turn rather than reshaped on a guess. Adopting a merge
        // later reds this test, which is the point: it makes that a visible
        // decision instead of a silent one.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('first'), new UserMessage('second')],
        ));

        $this->assertSame(
            [
                ['role' => 'user', 'parts' => [['text' => 'first']]],
                ['role' => 'user', 'parts' => [['text' => 'second']]],
            ],
            $captured['body']['contents'],
        );
    }

    public function testCompleteHoistsTheAssembledSystemPromptIntoTheGeminiSystemInstruction(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new SystemMessage('be terse'), new UserMessage('Hi')],
            systemPrompt: 'you are a bot',
        ));

        $this->assertSame(
            ['parts' => [['text' => "you are a bot\n\nbe terse"]]],
            $captured['body']['systemInstruction'],
            'Gemini takes the standing instruction as a top-level Content - parts, no role - '
            . 'assembled prompt first, then history SystemMessages in message order',
        );
        $this->assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            $captured['body']['contents'],
            'every hoisted SystemMessage must leave `contents`, or each one returns as a user turn',
        );
    }

    public function testTheGeminiPromptRidesSystemInstructionAndNowhereElseInTheBody(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new SystemMessage('SENTINEL-be-terse'), new UserMessage('Hi')],
        ));

        $this->assertSame(
            'SENTINEL-be-terse',
            $captured['body']['systemInstruction']['parts'][0]['text'],
        );

        // A hoist that forgets to drop the SystemMessage from `contents`
        // transmits it twice - once as the instruction, once as a `user` turn.
        $this->assertSame(
            1,
            substr_count((string) json_encode($captured['body']), 'SENTINEL-be-terse'),
        );
    }

    public function testGeminiSystemInstructionJoinsEveryHistorySystemMessageInMessageOrder(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [
                new SystemMessage('first'),
                new SystemMessage('second'),
                new UserMessage('Hi'),
                new SystemMessage('third'),
            ],
        ));

        $this->assertSame(
            "first\n\nsecond\n\nthird",
            $captured['body']['systemInstruction']['parts'][0]['text'],
        );
        $this->assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            $captured['body']['contents'],
        );
    }

    public function testGeminiBodyHasNoSystemInstructionKeyWithoutASystemPrompt(): void
    {
        // NEGATIVE POLARITY. The mutation it catches is dropping the
        // `if ($system !== null)` guard in geminiBody() so the key is written
        // unconditionally - which would send an empty Content on every
        // prompt-less turn. A positive test cannot catch that.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertArrayNotHasKey('systemInstruction', $captured['body']);
    }

    public function testGeminiBodyHasNoSystemInstructionKeyForAnEmptySystemPrompt(): void
    {
        // The pathological input the `!== null`-only guard that OpenAI and
        // Bedrock use would let through as an empty instruction.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi'), new SystemMessage('')],
            systemPrompt: '',
        ));

        $this->assertArrayNotHasKey('systemInstruction', $captured['body']);
        $this->assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            $captured['body']['contents'],
        );
    }

    /**
     * @return array<string, array{0: array<int, Message>}>
     */
    public static function geminiTurnlessTranscriptProvider(): array
    {
        return [
            // The same two pathological inputs the legacy arm documents - and
            // this arm answers them DIFFERENTLY on purpose. `contents` is
            // annotated REQUIRED on the vendored proto, so an empty list is a
            // server-side 400 with nothing useful in it; the `instances`
            // envelope states no such requirement, which is why
            // testGoogleModelsStillAcceptAnEmptyTranscript and
            // testAGoogleSystemMessageOnlyTranscriptYieldsAnEmptyMessagesList
            // still record the no-guard position for THAT arm.
            'empty transcript' => [[]],
            'system messages only' => [[new SystemMessage('only'), new SystemMessage('this')]],
            // A third route this arm has and neither other arm does: a turn
            // whose content is the empty string contributes no Part, so a
            // transcript of nothing but those is turnless too.
            'empty-content turns only' => [[new UserMessage(''), new AssistantMessage('')]],
        ];
    }

    /**
     * @dataProvider geminiTurnlessTranscriptProvider
     * @param array<int, Message> $messages
     */
    public function testCompleteRejectsATurnlessGeminiTranscriptLocally(array $messages): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $response = $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: $messages,
            systemPrompt: 'asm',
        ));

        $this->assertTrue($response->isError);
        $this->assertStringContainsString('`contents` is a REQUIRED field', (string) $response->errorMessage);
        // Rejected LOCALLY: no round trip is burned on an opaque 400.
        $this->assertFalse($captured['called']);
        // And classified permanent, so Runtime does not retry it three times.
        $this->assertFalse($response->errorTransient);
    }

    /**
     * @dataProvider geminiTurnlessTranscriptProvider
     * @param array<int, Message> $messages
     */
    public function testCompleteStreamRejectsATurnlessGeminiTranscriptLocally(array $messages): void
    {
        // The SAME guard on the streaming path, captured on the STREAMER seam
        // - "complete() passes" is not evidence about completeStream(), and
        // this arm no longer reaches the stream by delegating to complete().
        $captured = null;
        $provider = $this->providerWithStreamer([], $captured, self::GEMINI_MODEL);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: $messages,
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertStringContainsString('`contents` is a REQUIRED field', (string) $chunks[0]->errorMessage);
        $this->assertFalse($captured['called']);
    }

    public function testGeminiGenerationConfigCarriesEverySamplingKnob(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
            temperature: 0.25,
            maxTokens: 1024,
            topP: 0.9,
            topK: 40,
            stop: ['STOP', ''],
        ));

        // camelCase, and nested under `generationConfig` - NOT the legacy
        // arm's flat `parameters` map.
        $this->assertSame(
            [
                'temperature' => 0.25,
                'maxOutputTokens' => 1024,
                'topP' => 0.9,
                'topK' => 40,
                // The empty stop string is filtered by the shared
                // stopSequences() joiner, same as every other arm.
                'stopSequences' => ['STOP'],
            ],
            $captured['body']['generationConfig'],
        );
    }

    public function testGeminiGenerationConfigOmitsTheOptionalKnobsThatWereNotSet(): void
    {
        // NEGATIVE POLARITY on the same builder: the guarded keys must be
        // ABSENT, not present-and-null. `temperature`/`maxOutputTokens` are
        // unguarded and fall back to the class defaults, which is why they
        // stay.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertSame(
            ['temperature' => 0.7, 'maxOutputTokens' => 4096],
            $captured['body']['generationConfig'],
        );
    }

    public function testAGeminiBodyCarriesNoToolsKeyEvenWhenToolsAreOffered(): void
    {
        // A DELIBERATE ABSENCE, PINNED (16.2) rather than left to chance.
        // Gemini DOES support function calling and setTools() is vendored;
        // this class has no shaper for it, so supportsFunctionCalling()
        // reports false and the body carries no `tools`. Whoever builds the
        // shaper reds this test and flips the flag with it - which is the
        // intended signal, not a regression.
        $captured = null;
        $provider = $this->providerWithPredictor([], $captured, self::GEMINI_MODEL);

        $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
            tools: [$this->fakeTool()],
        ));

        $this->assertArrayNotHasKey('tools', $captured['body']);
        $this->assertSame(
            0,
            substr_count((string) json_encode($captured['body']), 'reader'),
            'no part of an offered tool may leak into a body that declares none',
        );
        $this->assertFalse($provider->supportsFunctionCalling());
    }

    // -------------------------------------------------------------------------
    // Gemini response parsing — `candidates[0].content.parts[*].text`
    // -------------------------------------------------------------------------

    public function testCompleteParsesTheGeminiCandidateShape(): void
    {
        $provider = $this->providerWithPredictor(
            [
                'candidates' => [[
                    'content' => [
                        'role' => 'model',
                        // Multiple parts, because a candidate is a LIST - the
                        // same trap `predictions[0].content` does not have.
                        'parts' => [['text' => 'Hello, '], ['text' => 'world']],
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 11,
                    'candidatesTokenCount' => 5,
                    // Deliberately DIFFERENT from the sum, so a parser that
                    // read totalTokenCount instead reds.
                    'totalTokenCount' => 99,
                ],
            ],
            $unused,
            self::GEMINI_MODEL,
        );

        $response = $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertFalse($response->isError);
        $this->assertSame('Hello, world', $response->content);
        $this->assertSame(16, $response->tokensUsed);
        $this->assertNull($response->reasoning);
        $this->assertNull($response->toolCalls);
    }

    public function testCompleteDoesNotParseAGeminiReplyWithTheLegacyPredictionsShape(): void
    {
        // THE CROSS-ARM CONTROL, and it is the assertion that says the two
        // parsers are really separate. The legacy parser reads
        // `content`/`text` off `predictions[0]`; hand the Gemini parser that
        // document and it must find nothing rather than silently succeed -
        // otherwise a routing regression would still "work" and hide itself.
        $provider = $this->providerWithPredictor(
            ['predictions' => [['content' => 'legacy content']]],
            $unused,
            self::GEMINI_MODEL,
        );

        $response = $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertSame('', $response->content);
        $this->assertSame(0, $response->tokensUsed);
    }

    public function testCompleteReportsAGeminiPromptBlockAsAPermanentError(): void
    {
        // A 200 CARRYING A REFUSAL. A safety-blocked prompt answers success
        // with no candidates and a promptFeedback.blockReason; read naively
        // that is an empty completion, indistinguishable from a model that
        // chose to say nothing.
        $provider = $this->providerWithPredictor(
            ['promptFeedback' => ['blockReason' => 'SAFETY']],
            $unused,
            self::GEMINI_MODEL,
        );

        $response = $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertTrue($response->isError);
        $this->assertSame(
            'Vertex generateContent: the prompt was blocked before generation (SAFETY).',
            $response->errorMessage,
        );
        // Deterministic in the input: retrying re-sends the same blocked
        // prompt, so it must not be classified transient.
        $this->assertFalse($response->errorTransient);
    }

    public function testCompleteReturnsEmptyContentForAGeminiCandidateWithNoParts(): void
    {
        // The OTHER polarity of the block above, and it must NOT be an error:
        // an output-side `finishReason: SAFETY` truncates the content object
        // away, and a candidate with no parts is an empty answer, not a
        // failure. Pinned so the two cases cannot collapse onto one another.
        $provider = $this->providerWithPredictor(
            ['candidates' => [['finishReason' => 'SAFETY']], 'usageMetadata' => ['promptTokenCount' => 7]],
            $unused,
            self::GEMINI_MODEL,
        );

        $response = $provider->complete(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        $this->assertFalse($response->isError);
        $this->assertSame('', $response->content);
        $this->assertSame(7, $response->tokensUsed);
    }

    // -------------------------------------------------------------------------
    // Gemini streaming — `:streamGenerateContent`
    // -------------------------------------------------------------------------

    public function testCompleteStreamSelectsStreamGenerateContentForGeminiModels(): void
    {
        $captured = null;
        $provider = $this->providerWithStreamer([], $captured, self::GEMINI_MODEL);

        iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        // NOT a delegation to complete(): this arm reaches the STREAMER seam,
        // which is the behaviour change from the fall-back the legacy arm
        // still has.
        $this->assertTrue($captured['called']);
        $this->assertSame('streamGenerateContent', $captured['method']);
        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/google/models/gemini-1.5-pro-002',
            $captured['endpoint'],
        );
        $this->assertSame(
            ['role' => 'user', 'parts' => [['text' => 'Hi']]],
            $captured['body']['contents'][0],
        );
    }

    public function testCompleteStreamYieldsGeminiTextDeltasAndUsageExactlyOnce(): void
    {
        // USAGE IS CUMULATIVE ON THIS PROTOCOL, NOT SPLIT. Gemini restates
        // usageMetadata on the chunks that carry it, so the arm parks the
        // latest and emits ONE usage response after the stream ends. Runtime
        // SUMS tokensUsed across chunks (because the Anthropic arm genuinely
        // does split its usage in two), so yielding per chunk would bill this
        // three-chunk turn's 12 output tokens as 4+8+12 = 24.
        $provider = $this->providerWithStreamer(
            [
                ['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4]],
                ['candidates' => [['content' => ['parts' => [['text' => 'lo ']]]]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 8]],
                ['candidates' => [['content' => ['parts' => [['text' => 'world']]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 12]],
            ],
            $unused,
            self::GEMINI_MODEL,
        );

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        // Exact counts, not "more than zero": three text deltas then one
        // usage response, in that order.
        $this->assertCount(4, $chunks);
        $this->assertSame(['Hel', 'lo ', 'world', ''], array_map(
            static fn (CompleteResponse $c): string => $c->content,
            $chunks,
        ));
        $this->assertSame([0, 0, 0, 22], array_map(
            static fn (CompleteResponse $c): int => $c->tokensUsed,
            $chunks,
        ));

        // The accumulated answer, and the total Runtime's cross-chunk sum
        // would arrive at.
        $this->assertSame('Hello world', implode('', array_map(
            static fn (CompleteResponse $c): string => $c->content,
            $chunks,
        )));
        $this->assertSame(22, array_sum(array_map(
            static fn (CompleteResponse $c): int => $c->tokensUsed,
            $chunks,
        )));
    }

    public function testCompleteStreamEmitsNoGeminiUsageChunkWhenTheStreamCarriesNone(): void
    {
        // NEGATIVE POLARITY on the usage emission: a stream with no
        // usageMetadata anywhere must yield text deltas ONLY, not a trailing
        // zero-token response. The mutation this catches is emitting the
        // parked usage unconditionally.
        $provider = $this->providerWithStreamer(
            [['candidates' => [['content' => ['parts' => [['text' => 'hi']]]]]]],
            $unused,
            self::GEMINI_MODEL,
        );

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertSame('hi', $chunks[0]->content);
        $this->assertSame(0, $chunks[0]->tokensUsed);
    }

    public function testCompleteStreamSkipsGeminiChunksThatCarryNoText(): void
    {
        // A chunk with an empty candidate list, and one whose candidate has no
        // parts, are both real (a keep-alive; an output-side safety
        // truncation). Neither is a delta a caller can append, so neither is
        // yielded.
        $provider = $this->providerWithStreamer(
            [
                ['candidates' => []],
                ['candidates' => [['finishReason' => 'SAFETY']]],
                ['candidates' => [['content' => ['parts' => [['text' => 'only this']]]]]],
            ],
            $unused,
            self::GEMINI_MODEL,
        );

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertSame('only this', $chunks[0]->content);
    }

    public function testCompleteStreamReportsAGeminiPromptBlockAsAPermanentErrorChunk(): void
    {
        $provider = $this->providerWithStreamer(
            [['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']]],
            $unused,
            self::GEMINI_MODEL,
        );

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertSame(
            'Vertex streamGenerateContent: the prompt was blocked before generation '
            . '(PROHIBITED_CONTENT).',
            $chunks[0]->errorMessage,
        );
        $this->assertFalse($chunks[0]->errorTransient);
    }

    public function testCompleteStreamReturnsAnErrorChunkWhenTheGeminiStreamerThrows(): void
    {
        $provider = VertexProvider::create(
            projectId: 'my-project',
            model: self::GEMINI_MODEL,
            predictor: fn (): array => [],
            streamer: function (): \Generator {
                yield ['candidates' => [['content' => ['parts' => [['text' => 'partial']]]]]];

                throw new \RuntimeException('gemini stream boom');
            },
        );

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        // The catch sits OUTSIDE the chunk loop, so it fires AFTER a real
        // delta has already been yielded - the case Runtime must not blindly
        // retry.
        $this->assertCount(2, $chunks);
        $this->assertSame('partial', $chunks[0]->content);
        $this->assertTrue($chunks[1]->isError);
        $this->assertSame('gemini stream boom', $chunks[1]->errorMessage);
    }

    // -------------------------------------------------------------------------
    // completeStream()
    // -------------------------------------------------------------------------

    public function testCompleteStreamSelectsStreamRawPredictForAnthropicModels(): void
    {
        $captured = null;
        $provider = $this->providerWithStreamer([], $captured);

        iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )));

        $this->assertTrue($captured['called']);
        $this->assertSame('streamRawPredict', $captured['method']);
        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/anthropic/models/claude-3-sonnet@20240229',
            $captured['endpoint'],
        );
        $this->assertTrue($captured['body']['stream'], 'streamRawPredict still needs the body to opt into SSE');
        $this->assertSame('vertex-2023-10-16', $captured['body']['anthropic_version']);
    }

    public function testCompleteStreamYieldsTextAndThinkingDeltas(): void
    {
        $provider = $this->providerWithStreamer([
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12]]],
            ['type' => 'ping'],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'hmm']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'lo']],
            ['type' => 'message_delta', 'usage' => ['output_tokens' => 4]],
            ['type' => 'message_stop'],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertSame(12, $chunks[0]->tokensUsed);
        $this->assertSame('hmm', $chunks[1]->reasoning);
        $this->assertSame('Hel', $chunks[2]->content);
        $this->assertSame('lo', $chunks[3]->content);
        $this->assertSame(4, $chunks[4]->tokensUsed);
        $this->assertCount(5, $chunks, 'ping/message_stop carry nothing a caller can use');
    }

    public function testCompleteStreamAssemblesToolCallsFromInputJsonDeltas(): void
    {
        $provider = $this->providerWithStreamer([
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => [
                'type' => 'tool_use', 'id' => 'tu-9', 'name' => 'reader',
            ]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => [
                'type' => 'input_json_delta', 'partial_json' => '{"path"',
            ]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => [
                'type' => 'input_json_delta', 'partial_json' => ':"a.txt"}',
            ]],
            ['type' => 'content_block_stop', 'index' => 1],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks, 'only the completed call is yieldable - the fragments are not');
        $this->assertCount(1, $chunks[0]->toolCalls);
        $this->assertSame('tu-9', $chunks[0]->toolCalls[0]->id());
        $this->assertSame('reader', $chunks[0]->toolCalls[0]->name());
        $this->assertSame(['path' => 'a.txt'], $chunks[0]->toolCalls[0]->arguments());
    }

    public function testCompleteStreamSurfacesTruncatedToolArgumentsAsAnError(): void
    {
        // A cut-short stream leaves the buffered `input_json_delta` fragments
        // undecodable. Coercing that to `[]` would hand the agentic loop a
        // well-formed ZERO-ARGUMENT call - i.e. `write` would run with its
        // path and contents silently missing.
        $provider = $this->providerWithStreamer([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => [
                'type' => 'tool_use', 'id' => 'tu', 'name' => 'write',
            ]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => [
                'type' => 'input_json_delta', 'partial_json' => '{"path":"/etc/pas',
            ]],
            ['type' => 'content_block_stop', 'index' => 0],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertNull($chunks[0]->toolCalls, 'a truncated call must not be executable');
        $this->assertStringContainsString("'write'", (string) $chunks[0]->errorMessage);
        $this->assertStringContainsString('tu', (string) $chunks[0]->errorMessage);
        $this->assertStringContainsString('{"path":"/etc/pas', (string) $chunks[0]->errorMessage);
    }

    public function testCompleteStreamRejectsToolArgumentsThatDecodeToANonObject(): void
    {
        // Valid JSON is not enough - `input` has to be an object.
        $provider = $this->providerWithStreamer([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => [
                'type' => 'tool_use', 'id' => 'tu', 'name' => 'write',
            ]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => [
                'type' => 'input_json_delta', 'partial_json' => 'null',
            ]],
            ['type' => 'content_block_stop', 'index' => 0],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertTrue($chunks[0]->isError);
        $this->assertNull($chunks[0]->toolCalls);
        $this->assertStringContainsString('decoded to null', (string) $chunks[0]->errorMessage);
    }

    public function testCompleteStreamStillYieldsAGenuinelyArgumentlessToolCall(): void
    {
        // A tool that takes no arguments streams no `input_json_delta` at all.
        // That EMPTY buffer is the one case that legitimately means `{}` and
        // must not be swept up by the truncation guard.
        $provider = $this->providerWithStreamer([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => [
                'type' => 'tool_use', 'id' => 'tu-0', 'name' => 'ls',
            ]],
            ['type' => 'content_block_stop', 'index' => 0],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertFalse($chunks[0]->isError);
        $this->assertCount(1, $chunks[0]->toolCalls);
        $this->assertSame('ls', $chunks[0]->toolCalls[0]->name());
        $this->assertSame([], $chunks[0]->toolCalls[0]->arguments());
    }

    public function testCompleteStreamRejectsToolArgumentsThatAreAJsonList(): void
    {
        // `json_decode(..., true)` turns a JSON list into a PHP array too, so
        // an is_array() guard alone would have accepted `[1,2]` as an argument
        // map while the error text insists the buffer must decode to an object.
        $provider = $this->providerWithStreamer([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => [
                'type' => 'tool_use', 'id' => 'tu', 'name' => 'write',
            ]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => [
                'type' => 'input_json_delta', 'partial_json' => '[1,2]',
            ]],
            ['type' => 'content_block_stop', 'index' => 0],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertTrue($chunks[0]->isError);
        $this->assertNull($chunks[0]->toolCalls, 'a list is not an argument map');
        $this->assertStringContainsString('decoded to a JSON list', (string) $chunks[0]->errorMessage);
    }

    public function testCompleteStreamAcceptsAnExplicitlyEmptyArgumentObject(): void
    {
        // The trap in rejecting lists: `{}` and `[]` are the SAME PHP value
        // after an associative decode, so a guard written with array_is_list()
        // would reject a model that streams an explicit empty object for a
        // tool that genuinely takes no arguments.
        $provider = $this->providerWithStreamer([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => [
                'type' => 'tool_use', 'id' => 'tu-0', 'name' => 'ls',
            ]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => [
                'type' => 'input_json_delta', 'partial_json' => ' {} ',
            ]],
            ['type' => 'content_block_stop', 'index' => 0],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertFalse($chunks[0]->isError);
        $this->assertCount(1, $chunks[0]->toolCalls);
        $this->assertSame([], $chunks[0]->toolCalls[0]->arguments());
    }

    public function testCompleteStreamTruncatesAVeryLongBufferedFragmentInTheError(): void
    {
        $provider = $this->providerWithStreamer([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => [
                'type' => 'tool_use', 'id' => 'tu', 'name' => 'write',
            ]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => [
                'type' => 'input_json_delta', 'partial_json' => '{"content":"' . str_repeat('x', 5000),
            ]],
            ['type' => 'content_block_stop', 'index' => 0],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertTrue($chunks[0]->isError);
        // A truncated write() call can buffer an entire file body; the error
        // identifies the call, it does not reproduce it.
        $this->assertLessThan(500, strlen((string) $chunks[0]->errorMessage));
        $this->assertStringContainsString('…', (string) $chunks[0]->errorMessage);
    }

    public function testCompleteStreamYieldsAnErrorEvent(): void
    {
        $provider = $this->providerWithStreamer([
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'overloaded']],
        ]);

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertTrue($chunks[0]->isError);
        $this->assertSame('overloaded', $chunks[0]->errorMessage);
    }

    /**
     * THE case the Anthropic-error-type classification exists for, asserted at
     * the site that produces it.
     *
     * An overloaded Anthropic-on-Vertex backend does not answer 503: it opens a
     * successful 200 SSE stream and puts `{"type":"overloaded_error"}` in an
     * `error` event. So this chunk's `errorTransient` is the ONLY signal the
     * retry seam has for this provider's most common transient failure, and
     * `testCompleteStreamYieldsAnErrorEvent()` above passes with the flag left
     * null. Setting `errorTransient: null` here was measured green across
     * `tests/Providers` and `tests/Integration` before this test existed.
     */
    public function testAStreamedErrorEventCarriesItsTransientVerdict(): void
    {
        $overloaded = $this->providerWithStreamer([
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'overloaded']],
        ]);
        $permanent = $this->providerWithStreamer([
            ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'bad key']],
        ]);

        $request = new CompleteRequest(model: self::ANTHROPIC_MODEL, messages: [new UserMessage('Hi')]);

        $this->assertTrue(
            iterator_to_array($overloaded->completeStream($request), false)[0]->errorTransient,
            'a 200-SSE overloaded_error must reach the retry seam classified as transient',
        );
        $this->assertFalse(
            iterator_to_array($permanent->completeStream($request), false)[0]->errorTransient,
            'and an auth failure in the same channel must not be',
        );
    }

    public function testCompleteStreamReturnsErrorChunkOnException(): void
    {
        $provider = VertexProvider::create(
            projectId: 'my-project',
            streamer: function (): \Generator {
                yield from [];

                throw new \RuntimeException('stream boom');
            },
        );

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertSame('stream boom', $chunks[0]->errorMessage);
    }

    public function testCompleteStreamFallsBackToTheUnaryCallForGoogleModels(): void
    {
        $captured = null;
        $provider = $this->providerWithPredictor(
            ['content' => 'unary answer'],
            $captured,
            self::LEGACY_GOOGLE_MODEL,
        );

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::LEGACY_GOOGLE_MODEL,
            messages: [new UserMessage('Hi')],
        )), false);

        $this->assertCount(1, $chunks);
        $this->assertSame('unary answer', $chunks[0]->content);
        $this->assertSame('predict', $captured['method']);
    }

    public function testCompleteStreamReturnsGenerator(): void
    {
        $provider = $this->providerWithStreamer([]);

        $this->assertInstanceOf(
            \Generator::class,
            $provider->completeStream(new CompleteRequest(model: self::ANTHROPIC_MODEL, messages: [])),
        );
    }

    // -------------------------------------------------------------------------
    // SSE framing (private static, exercised through a scope-bound closure)
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeSseEvents(string $chunk, string &$buffer): array
    {
        $call = \Closure::bind(
            static function (string $chunk, string &$buffer): array {
                return iterator_to_array(VertexProvider::decodeSseEvents($chunk, $buffer), false);
            },
            null,
            VertexProvider::class,
        );

        return $call($chunk, $buffer);
    }

    public function testDecodeSseEventsIgnoresEventLinesAndDecodesData(): void
    {
        $buffer = '';
        $events = $this->decodeSseEvents(
            "event: content_block_delta\ndata: {\"type\":\"content_block_delta\"}\n\n",
            $buffer,
        );

        $this->assertSame([['type' => 'content_block_delta']], $events);
        $this->assertSame('', $buffer);
    }

    public function testDecodeSseEventsCarriesAPartialLineAcrossChunks(): void
    {
        $buffer = '';

        $this->assertSame([], $this->decodeSseEvents('data: {"type":"mess', $buffer));
        $this->assertSame(
            [['type' => 'message_stop']],
            $this->decodeSseEvents("age_stop\"}\n", $buffer),
        );
    }

    public function testDecodeSseEventsSkipsDoneAndUndecodablePayloads(): void
    {
        $buffer = '';

        $this->assertSame([], $this->decodeSseEvents("data: [DONE]\ndata: not json\ndata:\n", $buffer));
    }

    /**
     * Drives the whole framing pipeline the default streamer uses, from raw
     * byte chunks to decoded events - the only part of that seam that is not
     * an irreducible RPC.
     *
     * @param array<int, string> $chunks
     * @return array<int, array<string, mixed>>
     */
    private function decodeSseStream(array $chunks): array
    {
        $call = \Closure::bind(
            static function (array $chunks): array {
                return iterator_to_array(VertexProvider::decodeSseStream($chunks), false);
            },
            null,
            VertexProvider::class,
        );

        return $call($chunks);
    }

    public function testDecodeSseStreamFlushesAnUnterminatedTrailingEvent(): void
    {
        // decodeSseEvents() only emits on a newline. A server that closes right
        // after its last `data:` line - no trailing "\n" - would otherwise
        // strand that event in the buffer, losing the final answer chunk (or a
        // message_delta's token usage) with no error anywhere.
        $events = $this->decodeSseStream([
            "data: {\"type\":\"a\"}\n\n",
            'data: {"type":"b"}',
        ]);

        $this->assertSame([['type' => 'a'], ['type' => 'b']], $events);
    }

    public function testDecodeSseStreamReassemblesEventsAcrossChunkBoundaries(): void
    {
        $events = $this->decodeSseStream(['data: {"ty', "pe\":\"split\"}\n\n"]);

        $this->assertSame([['type' => 'split']], $events);
    }

    public function testDecodeSseStreamDrainsNothingWhenTheStreamEndsCleanly(): void
    {
        // The drain must not invent an event out of the residual "\n\n".
        $events = $this->decodeSseStream(["data: {\"type\":\"a\"}\n\n"]);

        $this->assertSame([['type' => 'a']], $events);
    }

    public function testDecodeSseStreamDrainsATrailingDoneWithoutYielding(): void
    {
        $this->assertSame([], $this->decodeSseStream(['data: [DONE]']));
    }

    // -------------------------------------------------------------------------
    // The SDK's own 60s TOTAL request timeout, and its removal
    //
    // These drive the real vendored gax call stack offline: the shipped
    // PredictionService client config, RetrySettings::load(), RetryMiddleware
    // and the transports' own getCallOptions(). No network, no credentials.
    // -------------------------------------------------------------------------

    private const PREDICTION_SERVICE = 'google.cloud.aiplatform.v1.PredictionService';

    /**
     * @return array<int, array{0: string}>
     */
    public static function predictionRpcProvider(): array
    {
        return [
            'Predict' => ['Predict'],
            'RawPredict' => ['RawPredict'],
            'StreamRawPredict' => ['StreamRawPredict'],
            'GenerateContent' => ['GenerateContent'],
            // Like StreamRawPredict, this one names NO retry params in the
            // shipped config, so it is built from
            // RetrySettings::constructDefault() and its initialRpcTimeoutMillis
            // is 20000 - which is why callOptions() has to zero BOTH fields.
            'StreamGenerateContent' => ['StreamGenerateContent'],
        ];
    }

    /**
     * Replays what GapicClientTrait does to a call's optional arguments:
     * cast through CallOptions, merge any call-time retrySettings override,
     * then run RetryMiddleware and capture what it hands the transport.
     *
     * @param array<string, mixed> $callOptions
     * @return array<string, mixed>
     */
    private function transportOptionsFor(string $rpc, array $callOptions): array
    {
        $config = json_decode(
            (string) file_get_contents(
                __DIR__ . '/../../vendor/google/cloud-ai-platform/src/V1/resources/'
                . 'prediction_service_client_config.json'
            ),
            true,
        );

        $retrySettings = \Google\ApiCore\RetrySettings::load(self::PREDICTION_SERVICE, $config)[$rpc];

        $options = (new \Google\ApiCore\Options\CallOptions($callOptions))->toArray();

        if (is_array($options['retrySettings'] ?? null)) {
            $retrySettings = $retrySettings->with($options['retrySettings']);
        }

        $captured = [];
        $middleware = new \Google\ApiCore\Middleware\RetryMiddleware(
            static function (\Google\ApiCore\Call $call, array $opts) use (&$captured) {
                $captured = $opts;

                return new \GuzzleHttp\Promise\FulfilledPromise(null);
            },
            $retrySettings,
        );

        $middleware(new \Google\ApiCore\Call('m', 'D', null), $options);

        return $captured;
    }

    /**
     * @param array<string, mixed> $transportOptions
     * @return array<string, mixed>
     */
    private function guzzleOptionsFor(array $transportOptions): array
    {
        $method = new \ReflectionMethod(\Google\ApiCore\Transport\RestTransport::class, 'getCallOptions');
        $method->setAccessible(true);

        /** @var array<string, mixed> $options */
        $options = $method->invoke(
            (new ReflectionClass(\Google\ApiCore\Transport\RestTransport::class))->newInstanceWithoutConstructor(),
            $transportOptions,
        );

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function providerCallOptions(): array
    {
        $call = \Closure::bind(
            static fn (): array => VertexProvider::callOptions(),
            null,
            VertexProvider::class,
        );

        return $call();
    }

    /**
     * @dataProvider predictionRpcProvider
     */
    public function testTheSdkShipsA60SecondTotalRequestTimeoutOnEveryPredictionRpc(string $rpc): void
    {
        // The premise of the override. `prediction_service_client_config.json`
        // sets `timeout_millis: 60000` on all three RPCs, RetryMiddleware
        // stamps it as `timeoutMillis`, and RestTransport turns it into
        // Guzzle's `timeout` - which is a TOTAL request timeout, not an idle
        // one. On streamRawPredict it bounds the whole stream.
        $transport = $this->transportOptionsFor($rpc, []);

        $this->assertSame(60000, $transport['timeoutMillis']);
        // 60000 / 1000 stays an int in PHP; Guzzle takes either.
        $this->assertSame(60, $this->guzzleOptionsFor($transport)['timeout']);
    }

    /**
     * @dataProvider predictionRpcProvider
     */
    public function testProviderCallOptionsLeaveNoTotalRequestTimeoutAtAll(string $rpc): void
    {
        $transport = $this->transportOptionsFor($rpc, $this->providerCallOptions());

        // Not "a bigger number": no timeoutMillis is produced at all, so no
        // transport emits a timeout option and Guzzle falls back to its own
        // default of none. An LLM completion is legitimately long-running.
        $this->assertNull($transport['timeoutMillis']);
        $this->assertArrayNotHasKey('timeout', $this->guzzleOptionsFor($transport));
    }

    /**
     * @dataProvider predictionRpcProvider
     */
    public function testProviderCallOptionsKeepAConnectTimeout(string $rpc): void
    {
        // A connect timeout bounds only TCP/TLS establishment, which either
        // succeeds in seconds or is not going to. It is the total-request
        // timeout that is unacceptable, not this.
        $guzzle = $this->guzzleOptionsFor($this->transportOptionsFor($rpc, $this->providerCallOptions()));

        $this->assertIsFloat($guzzle['connect_timeout']);
        $this->assertGreaterThan(0.0, $guzzle['connect_timeout']);
    }

    public function testTheConnectTimeoutComesFromTheSharedLibraryPolicy(): void
    {
        // Not a number of its own: whatever every other provider bounds its
        // connect phase with - including a SUGARCRUSH_CONNECT_TIMEOUT override.
        $previous = getenv('SUGARCRUSH_CONNECT_TIMEOUT');

        try {
            putenv('SUGARCRUSH_CONNECT_TIMEOUT=2.5');

            $guzzle = $this->guzzleOptionsFor(
                $this->transportOptionsFor('RawPredict', $this->providerCallOptions())
            );

            $this->assertSame(2.5, $guzzle['connect_timeout']);
        } finally {
            putenv(
                $previous === false
                    ? 'SUGARCRUSH_CONNECT_TIMEOUT'
                    : 'SUGARCRUSH_CONNECT_TIMEOUT=' . $previous
            );
        }
    }

    public function testProviderCallOptionsDoNotSetTimeoutMillisDirectly(): void
    {
        // The obvious lever - `timeoutMillis => 0`, since RetryMiddleware's
        // guard is `!isset($options['timeoutMillis'])` - is transport
        // dependent: REST reads 0 as Guzzle's "wait indefinitely", but gRPC
        // multiplies it into a deadline of *now*. Zeroing the retry timeouts
        // instead leaves the option unset, which is correct on both.
        $this->assertArrayNotHasKey('timeoutMillis', $this->providerCallOptions());
    }

    public function testTimeoutMillisZeroWouldBeAnImmediateDeadlineUnderGrpc(): void
    {
        // Pure arithmetic in gax - no grpc extension needed to demonstrate it.
        // This is why the retry settings are the lever and `timeoutMillis` is
        // not; do not "simplify" callOptions() into passing 0 here.
        $method = new \ReflectionMethod(\Google\ApiCore\Transport\GrpcTransport::class, 'getCallOptions');
        $method->setAccessible(true);

        /** @var array<string, mixed> $grpc */
        $grpc = $method->invoke(
            (new ReflectionClass(\Google\ApiCore\Transport\GrpcTransport::class))->newInstanceWithoutConstructor(),
            ['timeoutMillis' => 0],
        );

        $this->assertSame(0, $grpc['timeout'], 'gRPC reads this as microseconds, i.e. a deadline of now');

        // Whereas leaving it unset emits no deadline at all.
        /** @var array<string, mixed> $unset */
        $unset = $method->invoke(
            (new ReflectionClass(\Google\ApiCore\Transport\GrpcTransport::class))->newInstanceWithoutConstructor(),
            ['timeoutMillis' => null],
        );

        $this->assertArrayNotHasKey('timeout', $unset);
    }

    public function testZeroingOnlyTheNoRetriesTimeoutWouldSHORTENTheStreamDeadline(): void
    {
        // Why callOptions() zeroes BOTH fields. Predict/RawPredict name retry
        // params in the client config, so their initialRpcTimeoutMillis is
        // already 0 - but StreamRawPredict names none and is built from
        // RetrySettings::constructDefault(), whose initialRpcTimeoutMillis is
        // 20000. Zeroing only noRetriesRpcTimeoutMillis makes RetryMiddleware
        // fall through to that branch, taking the stream from a 60s cap to a
        // 20s one: worse than leaving it alone.
        $halfFixed = $this->transportOptionsFor(
            'StreamRawPredict',
            ['retrySettings' => ['noRetriesRpcTimeoutMillis' => 0]],
        );

        $this->assertSame(20000, $halfFixed['timeoutMillis']);

        $fixed = $this->transportOptionsFor('StreamRawPredict', $this->providerCallOptions());

        $this->assertNull($fixed['timeoutMillis']);
    }

    // -------------------------------------------------------------------------
    // The same removal, proven at the REAL call sites
    //
    // Everything above replays the GAX stack by hand, so it stays green even
    // if defaultPredictor()/defaultStreamer() stop passing callOptions()
    // altogether - which restores the full 60s cap on every RPC. These drive
    // the actual seam closures, i.e. the code that really invokes
    // rawPredict/predict/streamRawPredict, against a PredictionServiceClient
    // whose transport is a captured http handler, and assert on the Guzzle
    // options that handler is given. Still offline: insecure credentials (so
    // no ADC lookup and no token fetch) and no socket is ever opened.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function defaultSeamRpcProvider(): array
    {
        // The second value pins WHICH RPC the seam actually reached, so a test
        // that silently drove the wrong one cannot pass by accident.
        return [
            'rawPredict' => [VertexProvider::METHOD_RAW_PREDICT, ':rawPredict'],
            'predict' => [VertexProvider::METHOD_PREDICT, ':predict'],
            'streamRawPredict' => [VertexProvider::METHOD_STREAM_RAW_PREDICT, ':streamRawPredict'],
            // The Gemini pair. Both carry `timeout_millis: 60000` in the
            // shipped client config exactly as the three above do (MEASURED by
            // reading prediction_service_client_config.json), so the seam that
            // invokes them has to pass callOptions() or the whole stream is
            // capped at 60s.
            'generateContent' => [VertexProvider::METHOD_GENERATE_CONTENT, ':generateContent'],
            'streamGenerateContent' => [
                VertexProvider::METHOD_STREAM_GENERATE_CONTENT,
                ':streamGenerateContent',
            ],
        ];
    }

    /**
     * A real PredictionServiceClient whose REST transport hands every request
     * to `$captured` instead of the network.
     *
     * @param array<int, array{uri: string, options: array<string, mixed>}> $captured
     * @param-out array<int, array{uri: string, options: array<string, mixed>}> $captured
     */
    private function probeClient(array &$captured): object
    {
        $handler = static function (
            \Psr\Http\Message\RequestInterface $request,
            array $options
        ) use (&$captured): \GuzzleHttp\Promise\PromiseInterface {
            $captured[] = [
                'uri' => (string) $request->getUri(),
                // The serialized request BODY, added for the Gemini call-site
                // test: `generationConfig` reaching the protobuf object is not
                // the same claim as it reaching the wire, and only this shows
                // the second. Purely additive - no assertion above reads it.
                'body' => (string) $request->getBody(),
                'options' => $options,
            ];

            // Just enough for HttpBody / PredictResponse to deserialize - these
            // tests assert on what went OUT, not on what came back. A
            // server-streaming RPC decodes a JSON LIST of messages, so
            // `:stream*Content` gets an empty list rather than an empty object.
            $stream = str_contains((string) $request->getUri(), ':streamGenerateContent');

            return new \GuzzleHttp\Promise\FulfilledPromise(
                new \GuzzleHttp\Psr7\Response(200, [], $stream ? '[]' : '{}')
            );
        };

        return new \Google\Cloud\AIPlatform\V1\Client\PredictionServiceClient([
            // Mirrors sdkClient(): the regional endpoint, credentials left to
            // the SDK - except that here they are explicitly insecure, which is
            // what keeps construction from reaching for ADC.
            'apiEndpoint' => 'us-central1-aiplatform.googleapis.com',
            'credentials' => new \Google\ApiCore\InsecureCredentialsWrapper(),
            'transport' => 'rest',
            'transportConfig' => ['rest' => ['httpHandler' => $handler]],
        ]);
    }

    /**
     * The endpoint/body pair the seam would be handed for a given Vertex
     * method, shaped as complete()/completeStream() shape them.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function seamArgumentsFor(string $method): array
    {
        if ($method === VertexProvider::METHOD_GENERATE_CONTENT
            || $method === VertexProvider::METHOD_STREAM_GENERATE_CONTENT) {
            // Byte-identical to what geminiBody() builds for this request -
            // see testCompleteSelectsGenerateContentAndTheContentsEnvelopeForGeminiModels,
            // which pins that whole body off the injected seam. Both Gemini
            // RPCs take the same document.
            return [
                'projects/my-project/locations/us-central1/publishers/google/models/'
                    . self::GEMINI_MODEL,
                [
                    'contents' => [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
                    'systemInstruction' => ['parts' => [['text' => 'you are a bot']]],
                    'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 4096],
                ],
            ];
        }

        if ($method === VertexProvider::METHOD_PREDICT) {
            return [
                'projects/my-project/locations/us-central1/publishers/google/models/' . self::LEGACY_GOOGLE_MODEL,
                [
                    'instances' => [['messages' => [['role' => 'user', 'content' => 'Hi']]]],
                    'parameters' => ['temperature' => 0.7, 'maxOutputTokens' => 4096],
                ],
            ];
        }

        return [
            'projects/my-project/locations/us-central1/publishers/anthropic/models/' . self::ANTHROPIC_MODEL,
            [
                'anthropic_version' => 'vertex-2023-10-16',
                'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
                'max_tokens' => 4096,
            ],
        ];
    }

    /**
     * Runs one RPC through the provider's OWN default seam and returns what
     * the transport was handed.
     *
     * @return array{uri: string, options: array<string, mixed>}
     */
    private function callSiteRequestFor(string $method): array
    {
        $captured = [];
        $client = $this->probeClient($captured);

        $seam = \Closure::bind(
            static function (string $method, object $client): \Closure {
                $streaming = $method === VertexProvider::METHOD_STREAM_RAW_PREDICT
                    || $method === VertexProvider::METHOD_STREAM_GENERATE_CONTENT;

                return $streaming
                    ? VertexProvider::defaultStreamer('us-central1', $client)
                    : VertexProvider::defaultPredictor('us-central1', $client);
            },
            null,
            VertexProvider::class,
        );

        [$endpoint, $body] = $this->seamArgumentsFor($method);
        $result = $seam($method, $client)($endpoint, $method, $body);

        if ($result instanceof \Generator) {
            // The streaming seam does nothing until it is drained.
            iterator_to_array($result, false);
        }

        $this->assertCount(1, $captured, 'the seam should have made exactly one request');

        return $captured[0];
    }

    /**
     * Same probe client, driven WITHOUT the provider's call options - the
     * premise that makes the assertions below non-vacuous.
     *
     * @return array<string, mixed>
     */
    private function sdkDefaultRequestOptionsFor(string $method): array
    {
        $captured = [];
        $client = $this->probeClient($captured);
        [$endpoint, $body] = $this->seamArgumentsFor($method);

        $httpBody = new \Google\Api\HttpBody();
        $httpBody->setContentType('application/json');
        $httpBody->setData((string) json_encode($body));

        match ($method) {
            VertexProvider::METHOD_RAW_PREDICT => $client->rawPredict(
                (new \Google\Cloud\AIPlatform\V1\RawPredictRequest())
                    ->setEndpoint($endpoint)
                    ->setHttpBody($httpBody),
            ),
            VertexProvider::METHOD_PREDICT => $client->predict(
                (new \Google\Cloud\AIPlatform\V1\PredictRequest())->setEndpoint($endpoint)->setInstances([]),
            ),
            VertexProvider::METHOD_GENERATE_CONTENT => $client->generateContent(
                (new \Google\Cloud\AIPlatform\V1\GenerateContentRequest())->setModel($endpoint),
            ),
            VertexProvider::METHOD_STREAM_GENERATE_CONTENT => iterator_to_array(
                $client->streamGenerateContent(
                    (new \Google\Cloud\AIPlatform\V1\GenerateContentRequest())->setModel($endpoint),
                )->readAll(),
                false,
            ),
            default => iterator_to_array(
                $client->streamRawPredict(
                    (new \Google\Cloud\AIPlatform\V1\StreamRawPredictRequest())
                        ->setEndpoint($endpoint)
                        ->setHttpBody($httpBody),
                )->readAll(),
                false,
            ),
        };

        $this->assertCount(1, $captured);

        return $captured[0]['options'];
    }

    /**
     * @dataProvider defaultSeamRpcProvider
     */
    public function testWithoutCallOptionsTheRealRpcWouldCarryThe60SecondTotalTimeout(
        string $method,
        string $verb
    ): void {
        // Not a hypothetical: this is the same client, the same transport and
        // the same RPC the seam invokes, minus the per-call options. Guzzle is
        // handed `timeout` - a TOTAL request timeout - and nothing bounds the
        // connect phase separately. If the assertions in the two tests below
        // ever pass for the wrong reason (a probe that captures nothing, an RPC
        // that never went out), this one goes red with them.
        $options = $this->sdkDefaultRequestOptionsFor($method);

        $this->assertSame(60, $options['timeout'], $verb . ' is capped by the shipped client config');
        $this->assertArrayNotHasKey('connect_timeout', $options);
    }

    /**
     * @dataProvider defaultSeamRpcProvider
     */
    public function testTheRealCallSitesSendNoTotalRequestTimeout(string $method, string $verb): void
    {
        // The regression this guards: dropping `self::callOptions()` from
        // defaultPredictor()/defaultStreamer() silently reinstates the 60s cap
        // proven directly above, truncating a long agentic turn mid-answer -
        // and on streamRawPredict it bounds the WHOLE stream.
        $request = $this->callSiteRequestFor($method);

        $this->assertStringContainsString($verb, $request['uri']);
        $this->assertArrayNotHasKey('timeout', $request['options']);
    }

    /**
     * @dataProvider defaultSeamRpcProvider
     */
    public function testTheRealCallSitesSendTheSharedConnectTimeout(string $method, string $verb): void
    {
        // The other half: the connect bound is what legitimately replaces the
        // total timeout, and it has to arrive at the transport with the value
        // callOptions() published - not merely be present in a value object
        // nobody passes.
        $expected = $this->providerCallOptions()['transportOptions']['restOptions']['connect_timeout'];
        $request = $this->callSiteRequestFor($method);

        $this->assertStringContainsString($verb, $request['uri']);
        $this->assertArrayHasKey('connect_timeout', $request['options']);
        $this->assertSame($expected, $request['options']['connect_timeout']);
    }

    /**
     * @dataProvider geminiSeamRpcProvider
     */
    public function testTheGeminiCallSiteSendsGenerationConfigOnTheWire(string $method, string $verb): void
    {
        // THE DEFECT THIS EXISTS TO NOT REPEAT. defaultPredictor()'s legacy
        // `:predict` branch builds its PredictRequest with setEndpoint() and
        // setInstances() and NEVER calls setParameters(), so `temperature` and
        // `maxOutputTokens` are silently discarded for every PaLM 2 model -
        // half the body googleBody() carefully builds never reaches the wire.
        // That is a real, pre-existing, separately-scoped defect in the legacy
        // arm. This test is what makes the same class of bug impossible in the
        // Gemini arm: it drives the provider's OWN seam through the real
        // vendored REST transport and asserts on the SERIALIZED HTTP BODY the
        // transport was handed - not on the array the provider built, and not
        // on the protobuf object, either of which can carry a field the
        // request never sends.
        //
        // MEASURED, not asserted: this is the request document, offline. It is
        // NOT evidence that Vertex honours it - nothing here has credentials.
        $request = $this->callSiteRequestFor($method);

        $this->assertStringContainsString($verb, $request['uri']);

        /** @var array<string, mixed> $sent */
        $sent = json_decode($request['body'], true);

        $this->assertIsArray($sent, 'the seam must send a JSON document');

        // The sampling knobs, on the wire. Deleting `setGenerationConfig()`
        // from generateContentRequest() reds exactly here - which is the
        // deletion experiment this test is for.
        $this->assertArrayHasKey('generationConfig', $sent);
        $this->assertSame(4096, $sent['generationConfig']['maxOutputTokens']);
        // `temperature` is a protobuf FLOAT (32-bit), so 0.7 does not
        // round-trip to 0.7 - MEASURED: it serializes as 0.69999999. Asserted
        // with a delta rather than pinned to that literal, which is a property
        // of IEEE-754 binary32 and not of this class.
        $this->assertEqualsWithDelta(0.7, $sent['generationConfig']['temperature'], 1.0e-6);

        // The system instruction, on the wire, at its declared slot. Deleting
        // `setSystemInstruction()` reds here.
        $this->assertSame(
            ['parts' => [['text' => 'you are a bot']]],
            $sent['systemInstruction'],
        );

        // And the turns, so a request that carried config but no conversation
        // cannot pass.
        $this->assertSame(
            [['role' => 'user', 'parts' => [['text' => 'Hi']]]],
            $sent['contents'],
        );

        // The publisher resource name rides `model` on this request type, not
        // `endpoint` - which is what the REST uri template binds to.
        $this->assertSame(
            'projects/my-project/locations/us-central1/publishers/google/models/gemini-1.5-pro-002',
            $sent['model'],
        );
    }

    /**
     * NEGATIVE CONTROL for the test above, and it is not decoration: it proves
     * the body probe is a live instrument rather than one that would report
     * "present" for anything. The legacy `:predict` call site really does drop
     * its `parameters`, so the same probe, pointed at it, must report the
     * ABSENCE.
     *
     * This pins the pre-existing defect where it lives rather than repairing
     * it - repairing the legacy arm is a different step (1.10: reported, not
     * repaired). Whoever fixes it reds this test, and that is the intended
     * signal.
     */
    public function testTheLegacyPredictCallSiteStillDropsItsParameters(): void
    {
        $request = $this->callSiteRequestFor(VertexProvider::METHOD_PREDICT);

        $this->assertStringContainsString(':predict', $request['uri']);

        /** @var array<string, mixed> $sent */
        $sent = json_decode($request['body'], true);

        $this->assertIsArray($sent);
        // The instances DO survive (toProtobufValues + mergeFromJsonString),
        // so the probe is looking at a real, populated body.
        $this->assertArrayHasKey('instances', $sent);
        $this->assertSame(
            [['messages' => [['role' => 'user', 'content' => 'Hi']]]],
            $sent['instances'],
        );
        // And the parameters do not: seamArgumentsFor(METHOD_PREDICT) supplies
        // `temperature`/`maxOutputTokens`, and defaultPredictor() never calls
        // setParameters(), so they are gone before the request is serialized.
        $this->assertArrayNotHasKey('parameters', $sent);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function geminiSeamRpcProvider(): array
    {
        return [
            'generateContent' => [VertexProvider::METHOD_GENERATE_CONTENT, ':generateContent'],
            'streamGenerateContent' => [
                VertexProvider::METHOD_STREAM_GENERATE_CONTENT,
                ':streamGenerateContent',
            ],
        ];
    }

    /**
     * Builds the provider's REAL SDK client, offline.
     *
     * {@see VertexProvider::sdkClient()} deliberately leaves credentials to the
     * SDK's own ADC chain, and gax resolves that chain EAGERLY during
     * construction - so a test has to give it something to find or the
     * constructor throws before the option under test can be observed. A
     * syntactically complete but unusable service-account file is enough: the
     * key is only ever touched when a token is minted, and no RPC is made here.
     */
    private function sdkClientFor(string $location): object
    {
        $adc = (string) tempnam(sys_get_temp_dir(), 'sugarcrush-vertex-adc-');
        $previous = getenv('GOOGLE_APPLICATION_CREDENTIALS');

        file_put_contents($adc, (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'offline-test',
            'private_key_id' => 'offline',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----\n",
            'client_email' => 'offline@offline.iam.gserviceaccount.com',
            'client_id' => '0',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        try {
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $adc);

            $build = \Closure::bind(
                static fn (string $location): object => VertexProvider::sdkClient($location),
                null,
                VertexProvider::class,
            );

            return $build($location);
        } finally {
            putenv(
                $previous === false
                    ? 'GOOGLE_APPLICATION_CREDENTIALS'
                    : 'GOOGLE_APPLICATION_CREDENTIALS=' . $previous
            );
            unlink($adc);
        }
    }

    public function testTheRealSdkClientIsPointedAtTheRegionalEndpoint(): void
    {
        // `apiEndpoint` is the load-bearing client option: publisher-model
        // predictions are served by `{location}-aiplatform.googleapis.com` and
        // NOT by the global `aiplatform.googleapis.com` the SDK defaults to -
        // which is half of the original bug. Every other test here injects its
        // own client, so nothing else would notice sdkClient() losing it; the
        // symptom would be a production-only 404.
        $client = $this->sdkClientFor('europe-west4');

        $transport = (new ReflectionClass($client))->getProperty('transport');
        $transport->setAccessible(true);
        $transport = $transport->getValue($client);

        if (!$transport instanceof \Google\ApiCore\Transport\RestTransport) {
            // gax picks gRPC whenever the extension is loaded, and that
            // transport keeps its host somewhere else entirely. Asserting on
            // one shape is better than asserting on neither.
            self::markTestSkipped('the grpc extension is loaded, so this client is not on the REST transport');
        }

        // Reflect once to reach the request builder, then use its PUBLIC build()
        // - the URI it produces is the same one a real call would be sent to.
        $builder = (new ReflectionClass($transport))->getProperty('requestBuilder');
        $builder->setAccessible(true);

        $request = $builder->getValue($transport)->build(
            self::PREDICTION_SERVICE . '/RawPredict',
            (new \Google\Cloud\AIPlatform\V1\RawPredictRequest())->setEndpoint(
                'projects/p/locations/europe-west4/publishers/anthropic/models/' . self::ANTHROPIC_MODEL,
            ),
        );

        $this->assertSame('europe-west4-aiplatform.googleapis.com', $request->getUri()->getHost());
    }

    // -------------------------------------------------------------------------
    // Default seams stay dormant until called
    // -------------------------------------------------------------------------

    public function testEmbeddingsReturnsEmptyEmbeddingsResponse(): void
    {
        $provider = $this->providerWithPredictor();

        $response = $provider->embeddings(new EmbeddingsRequest(model: 'textembedding-gecko', input: 'hi'));

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertSame([], $response->embeddings);
    }

    // -------------------------------------------------------------------------
    // formatMessages() - the legacy Google shape, unchanged
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

    private function fakeTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'reader';
            }

            public function description(): string
            {
                return 'reads a file';
            }

            public function inputSchema(): array
            {
                // The natural PHP spelling of "no parameters" - which must not
                // reach the wire as `"properties": []`.
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: '');
            }
        };
    }
}
