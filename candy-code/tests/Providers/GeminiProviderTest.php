<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\GeminiProvider;
use SugarCraft\Code\Providers\ProviderException;
use SugarCraft\Code\Providers\VertexProvider;

/**
 * PRV-3: the native Gemini API-key arm.
 *
 * The BODY and PARSE semantics of the `generateContent` wire are pinned
 * twice over already - by `VertexProviderTest`'s Gemini fixtures (the
 * shared `Concerns\GeminiGenerateContentWire` builder is byte-identical
 * pre- and post-extraction, md5-pinned in the lane report) and by the
 * transmission matrix (`SystemPromptTransmissionMatrixTest` drives
 * `systemInstruction.parts[0].text` for both hosts). What lives ONLY here
 * is what PRV-3 adds: the `x-goog-api-key` transport, the
 * `models/{model}:generateContent` / `:streamGenerateContent?alt=sse`
 * endpoint templating, the per-frame `alt=sse` decoder path through THIS
 * provider's real Guzzle transport, and the honest capability flags.
 */
final class GeminiProviderTest extends TestCase
{
    private const OK_DOCUMENT = [
        'candidates' => [
            ['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP'],
        ],
    ];

    // -------------------------------------------------------------------------
    // Surface and construction guards
    // -------------------------------------------------------------------------

    public function testTheNameIsGeminiAndTheDefaultsAreTheSharedConstants(): void
    {
        $provider = new GeminiProvider('gsk-test');

        $this->assertSame('gemini', $provider->name());
        $this->assertStringEndsWith(
            '/v1beta/models/' . GeminiProvider::DEFAULT_MODEL . ':generateContent',
            $provider->generateEndpoint(GeminiProvider::DEFAULT_MODEL),
            'the factory reads the DEFAULT_MODEL constant - this endpoint must visibly use it',
        );
    }

    public function testCreateAcceptsTheMinimalConfigAndAppliesDefaults(): void
    {
        $provider = GeminiProvider::create(['apiKey' => 'gsk-min']);

        $this->assertSame('gemini', $provider->name());
        $this->assertStringStartsWith('https://generativelanguage.googleapis.com/v1beta/', $provider->generateEndpoint('gemini-2.5-pro'));
    }

    public function testThinkingBudgetBelowMinusOneRefusesAtTheConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GeminiProvider thinkingBudget must be -1 (dynamic), 0 (off) or a positive token count, got -2.');

        new GeminiProvider('gsk-test', thinkingBudget: -2);
    }

    public function testThinkingBudgetAcceptsTheWholeDocumentedRangeIncludingZero(): void
    {
        // Vertex floors thinking at MIN_THINKING_BUDGET for the Anthropic
        // arms; the Gemini protocol itself accepts -1 (dynamic) and 0 (off),
        // and 0 MEANS OFF here - clamping it away would be a lie the wire
        // cannot carry. Construction must not refuse either.
        $this->assertInstanceOf(GeminiProvider::class, new GeminiProvider('gsk-test', thinkingBudget: -1));
        $this->assertInstanceOf(GeminiProvider::class, new GeminiProvider('gsk-test', thinkingBudget: 0));
        $this->assertInstanceOf(GeminiProvider::class, new GeminiProvider('gsk-test', thinkingBudget: 1024));
    }

    public function testTheCapabilityFlagsStateTheNativeArmsPosition(): void
    {
        $provider = new GeminiProvider('gsk-test');

        $this->assertTrue($provider->supportsStreaming(), ':streamGenerateContent?alt=sse is first-class on this API');
        $this->assertFalse($provider->supportsFunctionCalling(), 'the arm builds no tool shapes - claiming the capability without the plumbing is the bug PRV-3 refuses to reintroduce');
        $this->assertTrue($provider->supportsVision(), 'inline images ride inlineData parts via the shared AttachmentEncoding');
        $this->assertFalse($provider->supportsDocuments(), 'document blocks are the Anthropic-family position, same as Vertex\'s arm takes');
        $this->assertTrue($provider->supportsJsonSchema(), 'PRV-3 (3/3): the shared arm emits a structured-output schema beside its mime type (C-PRV-5.d: responseJsonSchema on the modern family, the responseSchema subset on the families that predate it)');

        $this->assertCount(0, $provider->embeddings(new EmbeddingsRequest(model: 'gemini-embedding-1', input: 'x'))->embeddings);
    }

    // -------------------------------------------------------------------------
    // Endpoint templating
    // -------------------------------------------------------------------------

    public function testGenerateEndpointTemplatesTheVersionedPathOverEveryDocumentedRoot(): void
    {
        $cases = [
            // [root, model, stream?, expected URL]
            ['https://generativelanguage.googleapis.com', 'gemini-2.5-flash', false, 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'],
            ['https://generativelanguage.googleapis.com', 'gemini-2.5-flash', true, 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent?alt=sse'],
            // trailing slash must not stack
            ['https://proxy.invalid/', 'm', false, 'https://proxy.invalid/v1beta/models/m:generateContent'],
            // an explicit version suffix is honoured as-is, not doubled
            ['https://proxy.invalid/v1', 'm', false, 'https://proxy.invalid/v1/models/m:generateContent'],
            ['https://proxy.invalid/v1beta/', 'm', true, 'https://proxy.invalid/v1beta/models/m:streamGenerateContent?alt=sse'],
            // the model is a PATH SEGMENT: reserved characters escape
            ['https://x', 'gemini-2.5-flash@release', false, 'https://x/v1beta/models/gemini-2.5-flash%40release:generateContent'],
        ];

        foreach ($cases as [$base, $model, $stream, $expected]) {
            $this->assertSame(
                $expected,
                (new GeminiProvider('gsk-test', $base))->generateEndpoint($model, $stream),
                "root {$base} (stream: " . var_export($stream, true) . ') must template as documented',
            );
        }
    }

    public function testContextWindowFollowsTableOverrideAndFloor(): void
    {
        $this->assertSame(1_048_576, (new GeminiProvider('gsk-test'))->contextWindow(), 'the default 2.5-flash row is Google\'s published 1M window');
        $this->assertSame(1_048_576, (new GeminiProvider('gsk-test', defaultModel: 'gemini-2.5-pro-001'))->contextWindow(), 'a dated id reads the same family row as the price table does');
        $this->assertSame(200_000, (new GeminiProvider('gsk-test', defaultModel: 'gemma-3-27b'))->contextWindow(), 'a family with no row keeps the status-quo floor, never a fabricated larger budget');
        $this->assertSame(4096, (new GeminiProvider('gsk-test', contextWindowOverride: 4096))->contextWindow(), 'the operator override outranks the table');
    }

    // -------------------------------------------------------------------------
    // Body shaping through the unary seam (roles, knobs, error carriers)
    // -------------------------------------------------------------------------

    public function testCompleteBuildsTheNativeDocument(): void
    {
        $captured = null;
        $provider = $this->providerCapturingUnary($captured, ['modelPrices' => []]);

        $response = $provider->complete(new CompleteRequest(
            model: 'gemini-2.5-flash',
            messages: [new UserMessage('hi'), new AssistantMessage('sure'), new UserMessage('again')],
            systemPrompt: 'be brief',
            temperature: 0.2,
            maxTokens: 512,
            topP: 0.9,
            topK: 40,
            stop: 'END',
        ));

        $this->assertSame('ok', $response->content);
        $this->assertStringEndsWith(':generateContent', $captured['endpoint']);
        $body = $captured['body'];

        $this->assertSame([
            ['role' => 'user', 'parts' => [['text' => 'hi']]],
            ['role' => 'model', 'parts' => [['text' => 'sure']]],
            ['role' => 'user', 'parts' => [['text' => 'again']]],
        ], $body['contents']);
        $this->assertSame(['parts' => [['text' => 'be brief']]], $body['systemInstruction']);
        $this->assertSame(0.2, $body['generationConfig']['temperature']);
        $this->assertSame(512, $body['generationConfig']['maxOutputTokens']);
        $this->assertSame(0.9, $body['generationConfig']['topP']);
        $this->assertSame(40, $body['generationConfig']['topK']);
        $this->assertSame(['END'], $body['generationConfig']['stopSequences']);
        $this->assertArrayNotHasKey('model', $body, 'the model rides the URL, never the body - the extraction\'s defining rule for this wire');
    }

    public function testGenerationConfigStaysMinimalUntilItIsAskedFor(): void
    {
        $captured = null;
        $provider = $this->providerCapturingUnary($captured);

        $provider->complete(new CompleteRequest(
            model: 'gemini-2.5-flash',
            messages: [new UserMessage('hi')],
        ));

        $config = $captured['body']['generationConfig'];
        $this->assertSame(0.7, $config['temperature'], 'DEFAULT_TEMPERATURE rides the wire when the request states none');
        $this->assertArrayNotHasKey('maxOutputTokens', $config, 'audit A21(b): an unasked ceiling truncates thinking-heavy 2.5 turns');
        $this->assertArrayNotHasKey('thinkingConfig', $config);
        $this->assertArrayNotHasKey('topP', $config);
        $this->assertArrayNotHasKey('topK', $config);
        $this->assertArrayNotHasKey('stopSequences', $config);
        $this->assertArrayNotHasKey('systemInstruction', $captured['body'], 'no system prompt means no systemInstruction object');
    }

    public function testAConfiguredThinkingBudgetRidesTheWireIncludingZeroForOff(): void
    {
        $captured = null;
        $provider = $this->providerCapturingUnary($captured, ['thinkingBudget' => 0]);

        $provider->complete(new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')]));

        $this->assertSame(['thinkingBudget' => 0], $captured['body']['generationConfig']['thinkingConfig']);
    }

    public function testStructuredOutputRidesTheJsonSchemaAndMimeTypePairTogetherOrNotAtAll(): void
    {
        // C-PRV-5.d: Google's GenerationConfig REQUIRES responseMimeType
        // beside EITHER structured-output field and takes only ONE of the two
        // at a time, so the pair still travels together or not at all — and on
        // this family the field is responseJsonSchema, which carries the
        // operator's standard JSON Schema whole. (The older `responseSchema`
        // subset arm is what a pre-2.5 family still gets; that path and its
        // sanitiser are pinned in GeminiResponseSchemaTest.) An absent or
        // unparseable schema still emits neither half.
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer']];

        $captured = null;
        $provider = $this->providerCapturingUnary($captured);

        $provider->complete(new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')], jsonSchema: $schema));
        $this->assertSame($schema, $captured['body']['generationConfig']['responseJsonSchema']);
        $this->assertSame('application/json', $captured['body']['generationConfig']['responseMimeType']);
        $this->assertArrayNotHasKey('responseSchema', $captured['body']['generationConfig'], 'the arms are mutually exclusive - the field that accepts JSON Schema says response_schema must be omitted');

        // The string spelling of the same schema decodes to the same pair.
        $provider->complete(new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')], jsonSchema: (string) json_encode($schema)));
        $this->assertSame($schema, $captured['body']['generationConfig']['responseJsonSchema']);

        $provider->complete(new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')], jsonSchema: 'this is not json'));
        $this->assertArrayNotHasKey('responseJsonSchema', $captured['body']['generationConfig'], 'an unparseable schema degrades to plain output, never a half a pair');
        $this->assertArrayNotHasKey('responseMimeType', $captured['body']['generationConfig']);

        $provider->complete(new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')]));
        $this->assertArrayNotHasKey('responseJsonSchema', $captured['body']['generationConfig']);
    }

    public function testAnEmptyTranscriptRefusesThroughTheErrorCarrier(): void
    {
        // complete() wraps the trait's local rejection the same way every
        // arm wraps a transport failure: an error response naming the wire,
        // not a thrown exception the turn loop would have to know about.
        $captured = null;
        $provider = $this->providerCapturingUnary($captured);

        $response = $provider->complete(new CompleteRequest(model: 'gemini-2.5-flash', messages: [], systemPrompt: 'only system'));

        $this->assertTrue($response->isError);
        $this->assertStringContainsString('Gemini generateContent: `contents` is a REQUIRED field', $response->errorMessage);
        $this->assertFalse($captured['called'], 'the refusal must cost no dial');
    }

    // -------------------------------------------------------------------------
    // Unary parse (shared trait, but this host labels the errors Gemini)
    // -------------------------------------------------------------------------

    public function testUnaryResponseFoldsTheUsageSplitAndPricesIt(): void
    {
        $provider = new GeminiProvider(
            'gsk-test',
            'https://x.invalid',
            'gemini-2.5-flash',
            http: static fn (string $endpoint, array $body): array => [
                'candidates' => [['content' => ['parts' => [['text' => 'He'], ['text' => 'llo']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 10, 'totalTokenCount' => 30],
            ],
        );

        $response = $provider->complete(new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')]));

        $this->assertSame('Hello', $response->content);
        $this->assertSame(30, $response->tokensUsed);
        $this->assertNotNull($response->usage);
        $this->assertSame(20, $response->usage->inputTokens);
        $this->assertSame(10, $response->usage->outputTokens);
        $this->assertFalse($response->truncated);
        // 20/1k at $0.0003 + 10/1k at $0.0025 - the gemini-2.5-flash row of
        // the shared PRICE_TABLE must price this host's turns too.
        $this->assertEqualsWithDelta(20 / 1000 * 0.0003 + 10 / 1000 * 0.0025, $response->costUsd, 1e-9);
    }

    public function testUnaryMaxTokensVerdictAndBlockedPromptErrorNameTheNativeHost(): void
    {
        $truncated = (new GeminiProvider('k', 'https://x.invalid', 'm', http: static fn (): array => [
            'candidates' => [['content' => ['parts' => [['text' => 'cut']]], 'finishReason' => 'MAX_TOKENS']],
        ]))->complete(new CompleteRequest(model: 'm', messages: [new UserMessage('hi')]));
        $this->assertTrue($truncated->truncated);

        $blocked = (new GeminiProvider('k', 'https://x.invalid', 'm', http: static fn (): array => [
            'promptFeedback' => ['blockReason' => 'SAFETY'],
        ]))->complete(new CompleteRequest(model: 'm', messages: [new UserMessage('hi')]));
        $this->assertTrue($blocked->isError);
        $this->assertSame('Gemini generateContent: the prompt was blocked before generation (SAFETY).', $blocked->errorMessage);
    }

    // -------------------------------------------------------------------------
    // Stream loop frames through the seam (parked usage, premature end)
    // -------------------------------------------------------------------------

    public function testStreamWithoutAFinishReasonReportsThePrematureEndOnce(): void
    {
        $provider = new GeminiProvider(
            'k',
            'https://x.invalid',
            'gemini-2.5-flash',
            httpStream: static function (): iterable {
                yield ['candidates' => [['content' => ['parts' => [['text' => 'partial']]]]]];
            },
        );

        $chunks = iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')])
        ));

        $this->assertCount(2, $chunks);
        $this->assertSame('partial', $chunks[0]->content);
        $this->assertTrue($chunks[1]->isError);
        $this->assertStringStartsWith('Gemini streamGenerateContent: ', $chunks[1]->errorMessage);
    }

    public function testLengthStopRidesAFlagOnlyFrameBeforeTheParkedUsage(): void
    {
        // E707 ordering, native host: the verdict frame must reach the
        // consumer BEFORE the billed usage frame so the fold sees both.
        $provider = new GeminiProvider(
            'k',
            'https://x.invalid',
            'gemini-2.5-flash',
            httpStream: static function (): iterable {
                yield ['candidates' => [['content' => ['parts' => [['text' => 'so far']]], 'finishReason' => 'MAX_TOKENS']], 'usageMetadata' => ['promptTokenCount' => 5, 'totalTokenCount' => 5]];
            },
        );

        $chunks = iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')])
        ));

        $this->assertCount(3, $chunks, 'text frame, flag-only frame, parked usage frame');
        $this->assertSame('so far', $chunks[0]->content);
        $this->assertTrue($chunks[1]->truncated);
        $this->assertSame('', $chunks[1]->content);
        $this->assertSame(5, $chunks[2]->tokensUsed);
    }

    public function testABlockedPromptFrameIsTheOnlyErrorTheStreamReports(): void
    {
        // The error chunk counts as a finish: a second, transient premature-end
        // frame must not overwrite the blocked verdict at the consumer.
        $provider = new GeminiProvider(
            'k',
            'https://x.invalid',
            'gemini-2.5-flash',
            httpStream: static function (): iterable {
                yield ['promptFeedback' => ['blockReason' => 'BLOCK']];
            },
        );

        $chunks = array_values(array_filter(iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')])
        )), static fn (CompleteResponse $c): bool => $c->isError));

        $this->assertCount(1, $chunks);
        $this->assertSame('Gemini streamGenerateContent: the prompt was blocked before generation (BLOCK).', $chunks[0]->errorMessage);
    }

    // -------------------------------------------------------------------------
    // The genuinely NEW parser path: alt=sse frames over the real transport
    // -------------------------------------------------------------------------

    public function testAltSseUnaryTransportPinsTheHeaderAndUrlAndBody(): void
    {
        $container = [];
        $provider = $this->providerOverMockGuzzle([
            new Response(200, [], (string) json_encode(self::OK_DOCUMENT)),
        ], $container);

        $response = $provider->complete(new CompleteRequest(
            model: 'gemini-2.5-flash',
            messages: [new UserMessage('go')],
            systemPrompt: 'be brief',
        ));

        $this->assertSame('ok', $response->content);
        $this->assertCount(1, $container);
        $request = $container[0]['request'];
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
            (string) $request->getUri(),
        );
        $this->assertSame('gsk-secret', $request->getHeaderLine('x-goog-api-key'), 'the native arm authenticates with the goog header, never a bearer token or query key');
        $this->assertStringNotContainsString('key=', (string) $request->getUri(), 'the key must never leak into the URL');
        $decoded = json_decode((string) $request->getBody(), true);
        $this->assertSame([['role' => 'user', 'parts' => [['text' => 'go']]]], $decoded['contents']);
        $this->assertArrayHasKey('systemInstruction', $decoded);
    }

    public function testAltSseStreamTransportYieldsOneResponsePerDataFrame(): void
    {
        // The frame shape that made this parser genuinely new: EVERY `data:`
        // line is a whole GenerateContentResponse JSON document - no `event:`
        // lines, no `[DONE]` sentinel, no partial-frame deltas to splice.
        $frameOne = (string) json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Hel']]]]]]);
        $frameTwo = (string) json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'lo']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 10, 'totalTokenCount' => 30],
        ]);

        $container = [];
        $provider = $this->providerOverMockGuzzle([
            new Response(200, [], "data: {$frameOne}\n\ndata: {$frameTwo}\n\n"),
        ], $container);

        $chunks = iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('go')])
        ));

        $this->assertSame(['Hel', 'lo', ''], array_map(static fn (CompleteResponse $c): string => $c->content, $chunks));
        $this->assertSame(30, array_sum(array_map(static fn (CompleteResponse $c): int => $c->tokensUsed, $chunks)), 'the cumulative document bills once');
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent?alt=sse', (string) $container[0]['request']->getUri());
        $this->assertSame('gsk-secret', $container[0]['request']->getHeaderLine('x-goog-api-key'));
    }

    public function testSseDecoderReassemblesGeminiFramesStraddlingReadBoundaries(): void
    {
        // The transport reads 8192-byte slices; a GenerateContentResponse can
        // straddle any of them. Splitting one mid-JSON - and leaving the last
        // frame unterminated - must still decode whole documents, the same
        // way Vertex pins the shared decoder.
        $decode = \Closure::bind(
            static function (array $chunks): array {
                return iterator_to_array(GeminiProvider::decodeSseStream($chunks), false);
            },
            null,
            GeminiProvider::class,
        );

        $doc = (string) json_encode(['candidates' => [['content' => ['parts' => [['text' => 'split across reads']]]]]]);
        $cut = (int) strpos($doc, 'across');
        $this->assertGreaterThan(0, $cut, 'the straddle point must exist for this fixture to mean anything');

        $this->assertSame(
            [json_decode($doc, true)],
            $decode(['data: ' . substr($doc, 0, $cut), substr($doc, $cut) . "\n\n"]),
        );
        $this->assertSame(
            [['type' => 'a'], ['type' => 'b']],
            $decode(["data: {\"type\":\"a\"}\n\n", 'data: {"type":"b"}']),
            'a server that closes right after its last data line must not strand the terminal frame',
        );
    }

    // -------------------------------------------------------------------------
    // Rebinding
    // -------------------------------------------------------------------------

    public function testWithModelRebindsTheDefaultTheEmptyModelRequestsResolveTo(): void
    {
        $captured = null;
        $provider = $this->providerCapturingUnary($captured);

        $rebound = $provider->withModel('gemini-2.5-pro');
        $this->assertInstanceOf(GeminiProvider::class, $rebound);

        // CompleteRequest::model === '' resolves through modelId() to the
        // provider default - the seam proves the rebound arm templates the
        // new id without the caller naming it.
        $rebound->complete(new CompleteRequest(model: '', messages: [new UserMessage('hi')]));
        $this->assertStringEndsWith('/models/gemini-2.5-pro:generateContent', $captured['endpoint']);
        $this->assertSame(1_048_576, $rebound->contextWindow());
    }

    // =========================================================================
    // PRV-3 review fixes: bounded SSE framing and the shared error unwrapper
    // =========================================================================

    public function testTheSseDecoderRefusesFramesBeyondTheEightKiBBound(): void
    {
        // CRITICAL-1: read(8192) in the transports is chunk GRANULARITY; the
        // bound that closes the memory-DoS shape lives in the shared decoder
        // and must raise BEFORE any yield of the offending document.
        $decode = \Closure::bind(
            static function (array $chunks): array {
                return iterator_to_array(GeminiProvider::decodeSseStream($chunks), false);
            },
            null,
            GeminiProvider::class,
        );

        // A terminated 16 KiB frame - double the bound on one line.
        $fat = 'data: ' . str_repeat('A', 16 * 1024) . "\n\n";
        $caught = null;
        try {
            $decode([$fat]);
        } catch (ProviderException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'a 16 KiB data line must be refused, not fed to json_decode');
        $this->assertStringContainsString('8192', $caught->getMessage(), 'the refusal names the cap');

        // An UNTERMINATED runaway line is refused mid-stream: the buffer may
        // not grow past the bound while waiting for a newline that never
        // comes. Split into sub-cap reads so no single chunk crosses it -
        // only accumulation can.
        $runaway = 'data: ' . str_repeat('B', 3000);
        $caught = null;
        try {
            $decode([$runaway, $runaway, $runaway]);
        } catch (ProviderException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'three sub-cap reads of one unclosed frame must still hit the bound');
        $this->assertStringContainsString('without a line terminator', $caught->getMessage());

        // The bound is not off-by-one-hungry: a maximum-size line survives.
        $doc = '{"candidates":[{"content":{"parts":[{"text":"' . str_repeat('o', 8100) . '"}]}}]}';
        $this->assertLessThanOrEqual(8192, strlen('data: ' . $doc), 'fixture must sit at-or-under the cap');
        $events = $decode(['data: ' . $doc . "\n\n"]);
        $this->assertCount(1, $events);
    }

    public function testTheGoogleErrorUnwrapperIsSharedByBothProviderErrorPaths(): void
    {
        // CRITICAL-2: one parser, both hosts, censused so the shared helper
        // can neither be forked into a second implementation nor quietly
        // dropped from a call site while the prose still claims it.
        $root = dirname(__DIR__, 2) . '/src/Providers/';
        $files = [
            'Concerns/AnthropicMessagesWire.php',
            'GeminiProvider.php',
            'VertexProvider.php',
            'AnthropicProvider.php',
            'Concerns/GeminiGenerateContentWire.php',
        ];

        $definitions = 0;
        foreach ($files as $relative) {
            $definitions += substr_count((string) file_get_contents($root . $relative), 'function vertexJsonHttpErrorBody');
        }
        $this->assertSame(1, $definitions, 'the unwrapper must exist in exactly one file - the shared trait - never per-host');
        $this->assertSame(3, substr_count((string) file_get_contents($root . 'GeminiProvider.php'), 'self::vertexJsonHttpErrorBody(')
            + substr_count((string) file_get_contents($root . 'VertexProvider.php'), 'self::vertexJsonHttpErrorBody('),
            'both provider error paths must keep routing through the shared parser (one Gemini call, two Vertex calls)');

        $envelope = (string) json_encode([
            'error' => ['code' => 403, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'PERMISSION_DENIED'],
        ]);
        $thrower = static function () use ($envelope) {
            throw RequestException::create(
                new Request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'),
                new Response(403, [], $envelope),
            );
        };

        $gemini = (new GeminiProvider('gsk-bad', 'https://x.invalid', 'gemini-2.5-flash', http: $thrower))
            ->complete(new CompleteRequest(model: 'gemini-2.5-flash', messages: [new UserMessage('hi')]));
        $this->assertTrue($gemini->isError);
        $this->assertSame(
            'API key not valid. Please pass a valid API key. (PERMISSION_DENIED)',
            $gemini->errorMessage,
            'the native arm must show Google\'s sentence, not Guzzle\'s transport prose',
        );

        $vertex = VertexProvider::create(
            projectId: 'p',
            location: 'l',
            model: 'gemini-1.5-pro-002',
            predictor: $thrower,
        )->complete(new CompleteRequest(model: 'gemini-1.5-pro-002', messages: [new UserMessage('hi')]));
        $this->assertTrue($vertex->isError);
        $this->assertStringStartsWith('API key not valid. Please pass a valid API key. (PERMISSION_DENIED', $vertex->errorMessage);
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /**
     * @param array<string, mixed> $options extra ctor knobs
     * @param-out array<string, mixed> $captured
     */
    private function providerCapturingUnary(?array &$captured, array $options = []): GeminiProvider
    {
        $captured = ['called' => false];

        return new GeminiProvider(
            'gsk-test',
            'https://gemini.invalid',
            'gemini-2.5-flash',
            http: function (string $endpoint, array $body) use (&$captured): array {
                $captured = ['called' => true, 'endpoint' => $endpoint, 'body' => $body];

                return self::OK_DOCUMENT;
            },
            modelPrices: $options['modelPrices'] ?? [],
            thinkingBudget: $options['thinkingBudget'] ?? null,
        );
    }

    /**
     * Real transport over a Guzzle mock handler: the reflection path the
     * house uses for seam-less providers (anthropicOverMock precedent).
     * `$container` fills as the request passes through the pushed history
     * middleware, so it is carried by reference like every other probe here.
     *
     * @param list<Response> $responses
     * @param-out list<array<string, mixed>> $container
     */
    private function providerOverMockGuzzle(array $responses, ?array &$container): GeminiProvider
    {
        $provider = new GeminiProvider('gsk-secret');

        $client = (new \ReflectionProperty(GeminiProvider::class, 'httpClient'))->getValue($provider);
        $stack = $client->getConfig('handler');
        $this->assertInstanceOf(\GuzzleHttp\HandlerStack::class, $stack);
        $stack->setHandler(new GuzzleMockHandler($responses));
        $container = [];
        $stack->push(Middleware::history($container));

        return $provider;
    }
}
