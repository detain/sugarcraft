<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Meta\MetaInformation;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-PRV-2.a: the OpenAI Responses wire arm (`POST {base}/responses`).
 *
 * EVERY fixture here is recorded-by-shape, not live-measured: this
 * environment has no `/v1/responses` key, and the class docblock of
 * {@see \SugarCraft\Code\Providers\Concerns\OpenAiResponsesWire} says so on
 * the same terms SglangProvider's fixtures do. What these pins lock is the
 * wire CONTRACT the code asserts: body spelling, header sourcing, the three
 * input item types, the flat tool form, the usage fold into the ONE
 * `parseUsage()` seam, the SSE event vocabulary, and the `api` key's
 * validation at the factory door.
 *
 * The transport is exercised through a MockHandler-bound Guzzle client
 * injected via the `responsesClient` ctor seam — the same client the
 * production path builds through {@see
 * \SugarCraft\Code\Providers\Concerns\HttpClientDefaults::guzzleClient()},
 * minus the handlers.
 */
final class OpenAiResponsesWireTest extends TestCase
{
    use HomeSandboxTrait;

    private string $responsesSandboxHome = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The factory legs read user-tier settings through
        // Bootstrap::readUserConfig(); an empty sandboxed home keeps the
        // operator's real config out of every result below (same law as
        // ProviderFactoryTest).
        $this->responsesSandboxHome = sys_get_temp_dir() . '/provider_responses_home_' . uniqid('', true);
        $this->useHomeSandbox($this->responsesSandboxHome);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->responsesSandboxHome);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    //  Helpers (names file-unique per DuplicatedTestHelperDrift discipline)
    // -------------------------------------------------------------------------

    /**
     * Every request the MockHandler client saw, in order. Bound BY
     * REFERENCE into Guzzle's history middleware, so appends inside the
     * handler land here as they happen.
     *
     * @var list<array<string, mixed>>
     */
    private array $wireHistory = [];

    /**
     * A responses-arm provider speaking through a MockHandler client.
     *
     * @param list<GuzzleResponse> $queued
     */
    private function responsesProviderWithHandler(
        array $queued,
        ?string $baseUrl = 'https://gw.test/v1',
        ?string $apiKey = 'sk-test',
        ?string $organization = null,
    ): OpenAIProvider {
        $this->wireHistory = [];
        $mock = new MockHandler($queued);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->wireHistory));

        return new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o-mini',
            // modelPrices: none declared -> every bill is the disclosed
            // unpriced account, pinned below as such.
            [],
            modelsBaseUrl: $baseUrl,
            modelsApiKey: $apiKey,
            api: OpenAIProvider::API_RESPONSES,
            organization: $organization,
            responsesClient: new GuzzleClient(['handler' => $stack]),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function responsesRequest(mixed ...$overrides): CompleteRequest
    {
        return new CompleteRequest(...array_merge(
            ['model' => 'gpt-4o-mini', 'messages' => [new UserMessage('Hi')]],
            $overrides,
        ));
    }

    /** @return array<string, mixed> */
    private function responsesBatchDoc(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'resp_ok',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Hello '],
                        ['type' => 'output_text', 'text' => 'world'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 30,
                'total_tokens' => 130,
                'input_tokens_details' => ['cached_tokens' => 40],
            ],
        ];
    }

    private function responsesToolNoProps(): Tool
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('noop');
        $tool->method('description')->willReturn('Do nothing');
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => []]);

        return $tool;
    }

    private function responsesToolWithProps(): Tool
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('get_weather');
        $tool->method('description')->willReturn('Get the weather');
        $tool->method('inputSchema')->willReturn([
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ]);

        return $tool;
    }

    // -------------------------------------------------------------------------
    //  Batch request shape
    // -------------------------------------------------------------------------

    public function testBatchRequestSpeaksTheResponsesEndpointWithAuthHeaders(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR))],
            baseUrl: 'https://gw.test/v1/',
            organization: 'org_pinned',
        );

        $provider->complete($this->responsesRequest());

        /** @var \Psr\Http\Message\RequestInterface $request */
        $request = $this->wireHistory[0]['request'];

        // Trailing-slash base normalises; the endpoint is the /responses
        // root, not the chat arm's /chat/completions.
        $this->assertSame('https://gw.test/v1/responses', (string) $request->getUri());
        $this->assertSame('Bearer sk-test', $request->getHeaderLine('Authorization'));
        $this->assertSame('org_pinned', $request->getHeaderLine('OpenAI-Organization'));
    }

    public function testNullBaseUrlFallsToTheSdkDefaultRoot(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR))],
            baseUrl: null,
        );

        $provider->complete($this->responsesRequest());

        $this->assertSame(
            'https://api.openai.com/v1/responses',
            (string) $this->wireHistory[0]['request']->getUri(),
        );
    }

    public function testBatchBodyCarriesTheResponsesSpelling(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR))],
        );

        $provider->complete($this->responsesRequest(
            systemPrompt: 'You are terse.',
            maxTokens: 512,
        ));

        $body = json_decode((string) $this->wireHistory[0]['request']->getBody(), true);

        $this->assertSame('gpt-4o-mini', $body['model']);
        $this->assertSame('You are terse.', $body['instructions']);
        $this->assertSame(512, $body['max_output_tokens']);
        // The store:false law: a candy-code session never server-stores its
        // transcript (privacy + a duplicate bill of the same prompt).
        $this->assertFalse($body['store']);
        // temperature was never set -> the Responses default applies
        // untouched (the chat arm's legacy `?? 0.7` is NOT inherited).
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertArrayNotHasKey('reasoning', $body);
    }

    public function testExplicitTemperatureAndEffortRoundDownRideTheBody(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR))],
        );

        $provider->complete($this->responsesRequest(
            model: 'o3-mini',
            temperature: 0.25,
            reasoningEffort: 'max',
        ));

        $body = json_decode((string) $this->wireHistory[0]['request']->getBody(), true);

        $this->assertSame(0.25, $body['temperature']);
        // SAME predicate + SAME `max`/`xhigh` -> `high` round-down as the
        // chat arm's reasoning_effort (C-PRV-1.a's shared door). C-PRV-2.b
        // added `summary:auto` + the encrypted-content include INSIDE the
        // same gate — the round-down itself is untouched.
        $this->assertSame(['effort' => 'high', 'summary' => 'auto'], $body['reasoning']);
        $this->assertSame(['reasoning.encrypted_content'], $body['include']);
    }

    public function testEffortDroppedForNonReasoningFamilyModels(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR))],
        );

        $provider->complete($this->responsesRequest(reasoningEffort: 'high'));

        $body = json_decode((string) $this->wireHistory[0]['request']->getBody(), true);

        $this->assertArrayNotHasKey('reasoning', $body);
        // C-PRV-2.b: the include rides the SAME gate — a non-reasoning
        // session keeps its exact pre-2.b bytes.
        $this->assertArrayNotHasKey('include', $body);
    }

    public function testToolsAreFlatAndSchemasGoThroughNormalization(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR))],
        );

        $provider->complete($this->responsesRequest(
            tools: [$this->responsesToolWithProps(), $this->responsesToolNoProps()],
        ));

        $rawBody = (string) $this->wireHistory[0]['request']->getBody();
        $body = json_decode($rawBody, true);

        // FLAT: name/description/parameters sit ON the tool, not nested in
        // a `function` object (chat's spelling — a 400 if sent here).
        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertSame('get_weather', $body['tools'][0]['name']);
        $this->assertSame('Get the weather', $body['tools'][0]['description']);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            $body['tools'][0]['parameters'],
        );
        $this->assertArrayNotHasKey('function', $body['tools'][0]);

        // normalizeToolSchema ran on this arm too: an empty `properties`
        // MAP encodes `{}` on the wire, never the `[]` array that OpenAI
        // rejects ("[] is not of type object" — the ToolSchema law).
        $this->assertStringContainsString('"properties":{}', $rawBody);
    }

    public function testTranscriptForksIntoTheThreeInputItemTypes(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR))],
        );

        $call = ToolCall::fromArray([
            'id' => 'call_1',
            'name' => 'get_weather',
            'arguments' => ['city' => 'Oslo'],
            'rawArguments' => '{"city":"Oslo"}',
        ]);

        $provider->complete($this->responsesRequest(messages: [
            new UserMessage('How is the weather?'),
            new AssistantMessage('It is sunny.', [$call]),
            new ToolResultMessage('call_2', 'ok'),
            new SystemMessage('mid-transcript note'),
        ]));

        $body = json_decode((string) $this->wireHistory[0]['request']->getBody(), true);

        $this->assertSame([
            [
                'type' => 'message',
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'How is the weather?']],
            ],
            // Call RECORDS before the message text of the same turn —
            // generation order, sibling items (the chat wire's field
            // inside one message does not exist here).
            [
                'type' => 'function_call',
                'call_id' => 'call_1',
                'name' => 'get_weather',
                // rawArguments replays verbatim (A23), the array never
                // re-encoded into a different byte string.
                'arguments' => '{"city":"Oslo"}',
            ],
            [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'It is sunny.']],
            ],
            [
                'type' => 'function_call_output',
                'call_id' => 'call_2',
                'output' => 'ok',
            ],
            [
                'type' => 'message',
                'role' => 'system',
                'content' => [['type' => 'input_text', 'text' => 'mid-transcript note']],
            ],
        ], $body['input']);
    }

    // -------------------------------------------------------------------------
    //  Batch response decode
    // -------------------------------------------------------------------------

    public function testBatchResponseFoldsUsageThroughTheOneParseUsageSeam(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR))],
        );

        // 'gpt-99-unlisted': neither a built-in table entry nor a declared
        // rate — the unpriced DISCLOSURE legs below are load-bearing only
        // for an id the pricing table genuinely does not know (gpt-4o-mini
        // IS priced built-in; an earlier pin asserting 0.0 for it measured
        // the table, not a missing rate).
        $response = $provider->complete($this->responsesRequest(model: 'gpt-99-unlisted'));

        $this->assertSame('Hello world', $response->content);
        $this->assertNull($response->reasoning);
        $this->assertNull($response->toolCalls);
        $this->assertFalse($response->truncated);
        $this->assertSame(130, $response->tokensUsed);

        $usage = $response->usage;
        $this->assertNotNull($usage);
        // input_tokens INCLUDES the cached prefix on this wire exactly as
        // prompt_tokens does on chat's — the fold makes the two arms share
        // {@see OpenAIProvider::parseUsage()} verbatim: input bucket is the
        // UNpaid part, cacheRead the paid-in-full part.
        $this->assertSame(60, $usage->inputTokens);
        $this->assertSame(40, $usage->cacheReadTokens);
        $this->assertSame(30, $usage->outputTokens);
        $this->assertSame(130, $usage->totalTokens);
        $this->assertNull($usage->cacheCreationTokens);
        // No declared rate: 0.0 as the LOWER BOUND with the name carried —
        // never a fabricated free (the SES-1 law, one path for both arms).
        $this->assertSame(0.0, $response->costUsd);
        $this->assertSame('gpt-99-unlisted', $usage->unpricedModel);
    }

    public function testBatchResponseReadsReasoningSummaryAndFunctionCalls(): void
    {
        $doc = $this->responsesBatchDoc([
            'output' => [
                [
                    'type' => 'reasoning',
                    'summary' => [
                        ['type' => 'summary_text', 'text' => 'first thought'],
                        ['type' => 'summary_text', 'text' => 'second thought'],
                    ],
                ],
                [
                    'type' => 'function_call',
                    'call_id' => 'call_7',
                    'name' => 'get_weather',
                    'arguments' => '{"city":"Oslo"}',
                ],
                [
                    'type' => 'function_call',
                    'call_id' => 'call_8',
                    'name' => 'broken',
                    // A scalar payload: refused via argumentsError by the
                    // A11 doctrine, never run as a silently-empty [] and
                    // never a mid-turn TypeError.
                    'arguments' => '12',
                ],
            ],
            'usage' => null,
        ]);

        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($doc, JSON_THROW_ON_ERROR))],
        );

        $response = $provider->complete($this->responsesRequest());

        $this->assertSame('', $response->content);
        $this->assertSame("first thought\nsecond thought", $response->reasoning);

        $this->assertIsArray($response->toolCalls);
        $this->assertCount(2, $response->toolCalls);

        [$good, $bad] = $response->toolCalls;
        $this->assertSame('call_7', $good->id());
        $this->assertSame('get_weather', $good->name());
        $this->assertSame(['city' => 'Oslo'], $good->arguments());
        $this->assertSame('{"city":"Oslo"}', $good->rawArguments());
        $this->assertNull($good->argumentsError());

        $this->assertSame([], $bad->arguments());
        $this->assertNotNull($bad->argumentsError());
        // A scalar payload earns NO replay string: it is refused, never
        // re-billed as bytes (see responsesToolCall).
        $this->assertNull($bad->rawArguments());

        // A usage-less document costs the turn its accounting, not the turn:
        // all-unreported Usage, zero total, model disclosed unpriced.
        $this->assertNotNull($response->usage);
        $this->assertNull($response->usage->inputTokens);
        $this->assertNull($response->usage->cacheReadTokens);
        $this->assertSame(0, $response->tokensUsed);
        // gpt-4o-mini HAS a built-in rate, so an all-unreported usage
        // settles at a real $0.00 — no unpriced disclosure is owed. The
        // disclosure leg above (gpt-99-unlisted) is what SES-1 demands
        // when no rate exists at all; the two pins together prove the
        // fold shares parseUsage()'s ONE pricing path, not two.
        $this->assertSame(0.0, $response->costUsd);
        $this->assertNull($response->usage->unpricedModel);
    }

    public function testIncompleteStatusSetsTheE707Flag(): void
    {
        $doc = $this->responsesBatchDoc([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
        ]);

        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], json_encode($doc, JSON_THROW_ON_ERROR))],
        );

        $response = $provider->complete($this->responsesRequest());

        // `status: incomplete` is this wire's spelling of the chat arm's
        // `finish_reason: length` — the length-stop notice reads the same
        // Message flag on either arm.
        $this->assertTrue($response->truncated);
    }

    public function testServerSideTransportErrorCarriesTheServerProse(): void
    {
        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(400, [], json_encode([
                'error' => ['message' => 'Unsupported parameter: bogus', 'type' => 'invalid_request_error'],
            ], JSON_THROW_ON_ERROR))],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI responses request failed: Unsupported parameter: bogus');

        $provider->complete($this->responsesRequest());
    }

    // -------------------------------------------------------------------------
    //  Streaming
    // -------------------------------------------------------------------------

    public function testStreamYieldsDeltasReassemblesCallsAndBillsLast(): void
    {
        $sse = implode("\n", [
            'event: response.created',
            'data: {"type":"response.created","response":{"id":"resp_s"}}',
            '',
            'event: response.output_text.delta',
            'data: {"type":"response.output_text.delta","delta":"Hel"}',
            '',
            'event: response.output_text.delta',
            'data: {"type":"response.output_text.delta","delta":"lo"}',
            '',
            'event: response.output_item.added',
            'data: {"type":"response.output_item.added","item":{"id":"item_1","type":"function_call","call_id":"call_1","name":"get_weather","arguments":""}}',
            '',
            'event: response.function_call_arguments.delta',
            'data: {"type":"response.function_call_arguments.delta","item_id":"item_1","delta":"{\\"city\\""}',
            '',
            'event: response.function_call_arguments.delta',
            'data: {"type":"response.function_call_arguments.delta","item_id":"item_1","delta":":\\"Oslo\\"}"}',
            '',
            'event: response.output_item.done',
            'data: {"type":"response.output_item.done","item":{"id":"item_1","type":"function_call","call_id":"call_1","name":"get_weather","arguments":"{\\"city\\":\\"Oslo\\"}"}}',
            '',
            'event: response.completed',
            'data: {"type":"response.completed","response":{"id":"resp_s","usage":{"input_tokens":10,"output_tokens":5,"total_tokens":15}}}',
            '',
            'data: [DONE]',
            '',
        ]);

        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, ['Content-Type' => 'text/event-stream'], $sse)],
        );

        $frames = iterator_to_array($provider->completeStream($this->responsesRequest()), false);

        $this->assertSame('Hel', $frames[0]->content);
        $this->assertSame('lo', $frames[1]->content);

        $call = $frames[2]->toolCalls[0] ?? null;
        $this->assertNotNull($call);
        $this->assertSame('call_1', $call->id());
        $this->assertSame('get_weather', $call->name());
        $this->assertSame(['city' => 'Oslo'], $call->arguments());
        $this->assertSame('{"city":"Oslo"}', $call->rawArguments());

        // The bill is the LAST frame and the whole Usage rides it (E17),
        // the same law the chat stream carries.
        $terminal = $frames[count($frames) - 1];
        $this->assertSame('', $terminal->content);
        $this->assertNull($terminal->toolCalls);
        $this->assertSame(15, $terminal->tokensUsed);
        $this->assertNotNull($terminal->usage);
        $this->assertSame(10, $terminal->usage->inputTokens);
        $this->assertSame(5, $terminal->usage->outputTokens);
        $this->assertFalse($terminal->truncated);
        // Nothing after the terminal frame.
        $this->assertCount(4, $frames);
    }

    public function testStreamFlushesCallsThatNeverSawTheirDoneFrame(): void
    {
        // Cut mid-call: added + one args delta, then the stream ends.
        $sse = implode("\n", [
            'event: response.output_item.added',
            'data: {"type":"response.output_item.added","item":{"id":"item_9","type":"function_call","call_id":"call_9","name":"noop","arguments":""}}',
            '',
            'event: response.function_call_arguments.delta',
            'data: {"type":"response.function_call_arguments.delta","item_id":"item_9","delta":"{\\"a\\":1}"}',
            '',
        ]);

        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], $sse)],
        );

        $frames = iterator_to_array($provider->completeStream($this->responsesRequest()), false);

        $this->assertCount(1, $frames);
        $call = $frames[0]->toolCalls[0] ?? null;
        $this->assertNotNull($call);
        $this->assertSame('call_9', $call->id());
        $this->assertSame(['a' => 1], $call->arguments());
    }

    public function testIncompleteStreamMarksEveryPostInCompleteFrameTruncated(): void
    {
        $sse = implode("\n", [
            'event: response.output_text.delta',
            'data: {"type":"response.output_text.delta","delta":"cut"}',
            '',
            'event: response.incomplete',
            'data: {"type":"response.incomplete","response":{"id":"r3","usage":{"input_tokens":4,"output_tokens":6,"total_tokens":10}}}',
            '',
        ]);

        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], $sse)],
        );

        $frames = iterator_to_array($provider->completeStream($this->responsesRequest()), false);

        $terminal = $frames[count($frames) - 1];
        $this->assertSame(10, $terminal->tokensUsed);
        // An incomplete terminal: the same length-stop flag the batch arm
        // raises on `status: incomplete`.
        $this->assertTrue($terminal->truncated);
    }

    public function testIncompleteStreamWithNoUsageAndNoPendingCallsStillDeliversTheFlag(): void
    {
        // Review round 1: a malformed `.incomplete` frame — no usable
        // response document, so no usage carrier, and nothing pending to
        // flush — must still land the E707 flag on SOME frame. Pre-fix
        // this shape truncated silently: the flag only rode the usage and
        // flush frames. The remedy is the flag-only terminal yield, the
        // shape Sglang's arm ships for a payload-less length stop.
        $sse = implode("\n", [
            'event: response.incomplete',
            'data: {"type":"response.incomplete","response":{"id":"r5"}}',
            '',
        ]);

        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], $sse)],
        );

        $frames = iterator_to_array($provider->completeStream($this->responsesRequest()), false);

        $this->assertCount(1, $frames);
        $frame = $frames[0];
        $this->assertTrue($frame->truncated);
        // Flag ONLY: no invented payload rides the terminal.
        $this->assertSame('', $frame->content);
        $this->assertNull($frame->toolCalls);
        $this->assertNull($frame->usage);
    }

    public function testStreamErrorEventRaisesWithServerMessage(): void
    {
        $sse = implode("\n", [
            'event: response.failed',
            'data: {"type":"response.failed","response":{"id":"r4","error":{"message":"server exploded"}}}',
            '',
        ]);

        $provider = $this->responsesProviderWithHandler(
            queued: [new GuzzleResponse(200, [], $sse)],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI responses stream failed: server exploded');

        iterator_to_array($provider->completeStream($this->responsesRequest()), false);
    }

    // -------------------------------------------------------------------------
    //  Arm isolation + E646 structural law
    // -------------------------------------------------------------------------

    public function testResponsesArmNeverTouchesTheSdkChatContract(): void
    {
        $sdk = $this->createMock(ClientContract::class);
        $sdk->expects(self::never())->method('chat');

        $provider = new OpenAIProvider(
            $sdk,
            'gpt-4o-mini',
            [],
            modelsBaseUrl: 'https://gw.test/v1',
            modelsApiKey: 'sk-test',
            api: OpenAIProvider::API_RESPONSES,
            responsesClient: new GuzzleClient([
                'handler' => new MockHandler([
                    new GuzzleResponse(200, [], json_encode($this->responsesBatchDoc(), JSON_THROW_ON_ERROR)),
                ]),
            ]),
        );

        $this->assertSame('Hello world', $provider->complete($this->responsesRequest())->content);
    }

    public function testChatArmNeverTouchesTheResponsesClient(): void
    {
        $raw = $this->createMock(GuzzleClient::class);
        $raw->expects(self::never())->method('request');

        $chat = $this->createMock(ChatContract::class);
        $chat->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-9',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'chat path'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7],
        ], MetaInformation::from([])));

        $sdk = $this->createMock(ClientContract::class);
        $sdk->method('chat')->willReturn($chat);

        $provider = new OpenAIProvider(
            $sdk,
            'gpt-4o',
            [],
            api: OpenAIProvider::API_CHAT,
            responsesClient: $raw,
        );

        $this->assertSame('chat path', $provider->complete(new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('Hello')],
        ))->content);
    }

    public function testTheWireAddsNoTotalRequestTimeout(): void
    {
        // E646: an LLM request is bounded by connect + stream transport
        // seams, NEVER by a total-request timeout. The responses arm speaks
        // only through HttpClientDefaults' bounded client — structurally
        // pinned: no `'timeout' =>` option may appear in its source, and
        // its client must come from the house seam.
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Providers/Concerns/OpenAiResponsesWire.php',
        );
        $this->assertNotSame('', $source);

        $this->assertStringNotContainsString("'timeout' =>", $source);
        $this->assertStringNotContainsString('"timeout" =>', $source);
        $this->assertStringContainsString('self::guzzleClient()', $source);
    }

    // -------------------------------------------------------------------------
    //  Factory door
    // -------------------------------------------------------------------------

    public function testFactoryDefaultsToTheChatArmAndCarriesTheApiChoice(): void
    {
        $factory = new ProviderFactory();

        $chatProvider = $factory->create([
            'type' => 'openai',
            'apiKey' => 'sk-test',
            'model' => 'gpt-4o-mini',
        ]);
        $this->assertSame('chat', $this->readWireChoice($chatProvider));

        $responsesProvider = $factory->create([
            'type' => 'openai',
            'apiKey' => 'sk-test',
            'model' => 'gpt-4o-mini',
            'api' => 'responses',
        ]);
        $this->assertInstanceOf(OpenAIProvider::class, $responsesProvider);
        $this->assertSame('responses', $this->readWireChoice($responsesProvider));

        // withModel rebuilds via get_object_vars — the wire choice rides
        // the clone (the arm is the object's identity, not the model's).
        $this->assertSame('responses', $this->readWireChoice($responsesProvider->withModel('o3-mini')));
    }

    public function testFactoryRefusesAnUnknownApiSpelling(): void
    {
        $factory = new ProviderFactory();

        try {
            $factory->create([
                'type' => 'openai',
                'apiKey' => 'sk-test',
                'api' => 'rest',
            ]);
            $this->fail('an unknown `api` spelling must never build a provider');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('api must be "chat" or "responses", got \'rest\'', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('api must be "chat" or "responses", got int');

        $factory->create([
            'type' => 'openai',
            'apiKey' => 'sk-test',
            'api' => 42,
        ]);
    }

    private function readWireChoice(object $provider): string
    {
        $property = new \ReflectionProperty(OpenAIProvider::class, 'api');

        return $property->getValue($provider);
    }
}
