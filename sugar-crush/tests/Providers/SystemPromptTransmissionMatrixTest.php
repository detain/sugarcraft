<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Chat\CreateStreamedResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Responses\StreamResponse;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * The transmission matrix: every provider the factory can build must put
 * CompleteRequest::$systemPrompt on the wire, in whatever field that
 * provider's protocol uses.
 *
 * One test file walks the whole roster — derived from src/Providers/, not
 * hand-maintained — hands each provider an identical CompleteRequest carrying
 * a distinctive sentinel, and pins the sentinel to the exact wire slot of that
 * provider's protocol on BOTH the complete() and the completeStream() path.
 * A provider added later with no systemPrompt handling fails
 * {@see testEveryProviderImplementerHasATransmissionContract} on day one; a
 * provider whose transmission is deleted fails its per-provider test.
 *
 * Each provider is driven exactly the way its own suite drives it (Sglang /
 * Custom through a mocked Guzzle client and the captured wire body,
 * OpenAI through a captured create()/createStreamed() params array, Bedrock
 * through the AWS MockHandler's last command, Vertex through its injectable
 * predictor/streamer seams, ClaudeCode through ClaudeCodeInvocation's public
 * printModeArgs() wire shaper — its execute() step is proc_open and cannot
 * run in a unit test, so the shaper is driven with the exact options the
 * provider builds, as ClaudeCodeProviderTest does).
 */
final class SystemPromptTransmissionMatrixTest extends TestCase
{
    /**
     * The wire location each covered provider's protocol uses for
     * CompleteRequest::$systemPrompt.
     *
     * ONE ROW PER REQUEST-BODY BUILDER, NOT PER PROVIDER CLASS, and that is a
     * correction rather than a refinement. This map was
     * `array<class-string, string>` — a single wire slot per class — and
     * VertexProvider builds TWO bodies, chosen at call time by
     * isAnthropicModel() (VertexProvider.php:231, :397-400). The row
     * `VertexProvider::class => 'system'` was true of the Anthropic arm alone,
     * every Vertex test in this file drove that arm by hardcoding a `claude-*`
     * model, and the Google arm dropped the assembled prompt outright for the
     * whole of Phase 1 — the exact defect this file was written to make
     * impossible. An alphabet is coverage; this one could not SAY that a class
     * has two builders, so nothing here could notice that only one of them
     * transmitted.
     *
     * A class with more than one builder therefore contributes several rows,
     * keyed `<FQCN>#<discriminator>`. The class half stays a `::class`
     * reference so a class rename still breaks this file at compile time.
     *
     * Four families:
     * - Sglang/Custom/OpenAI prepend an OpenAI-chat-shaped leading system
     *   message into `messages` (SglangProvider.php:672-677,
     *   CustomProvider.php:155-160 / :210-215, OpenAIProvider.php:90-95 /
     *   :127-130);
     * - Bedrock hoists it into the Converse top-level `system` block list
     *   (BedrockProvider.php:164-166 / :215-217 via systemBlocks() :337-343);
     * - Vertex hoists it into the Anthropic body's top-level `system` string
     *   (VertexProvider.php:455-458) or, for a `publishers/google` model, into
     *   `instances[0].context` (VertexProvider.php:1015-1017) — both through
     *   the one joiner, systemInstruction() :508;
     * - ClaudeCode turns it into a `--system-prompt` CLI argv pair
     *   (ClaudeCodeProvider.php:80 / :105 ->
     *   ClaudeCodeInvocation.php:75-78).
     *
     * EchoProvider is deliberately absent: it is a test double with no wire —
     * it echoes a blockquote in PHP and never serializes a request payload
     * (EchoProvider.php:18-23, 84-91). See
     * {@see testEveryProviderImplementerHasATransmissionContract} for the
     * derived-roster assertion that names this exemption.
     *
     * @var array<string, string>
     */
    private const TRANSMISSION_CONTRACT = [
        SglangProvider::class => 'messages[0]',
        CustomProvider::class => 'messages[0]',
        OpenAIProvider::class => 'messages[0]',
        BedrockProvider::class => 'system[0].text',
        VertexProvider::class . '#anthropic' => 'system',
        VertexProvider::class . '#google' => 'instances[0].context',
        ClaudeCodeProvider::class => '--system-prompt argv',
    ];

    /**
     * The distinctive, FIXED marker every covered provider must put on the
     * wire verbatim. Recognisable in any payload by grep, and unique enough
     * that a `substr_count(..., 1)` over the whole serialized body proves the
     * sentinel rides the protocol's system slot and nowhere else.
     */
    private const SENTINEL = 'P1S7-SENTINEL-4f8a2c91';

    /**
     * A `publishers/google` model id, which is what selects VertexProvider's
     * SECOND body builder: isAnthropicModel() is
     * `str_contains(strtolower($model), 'claude')` (VertexProvider.php:397-400)
     * and modelId() prefers the REQUEST's model over the provider's own
     * default (:412-415), so this id on the request routes there whatever the
     * helper below constructs the provider with.
     */
    private const VERTEX_GOOGLE_MODEL = 'gemini-1.5-pro-002';

    /**
     * One OpenAI-compatible streamed chunk plus the terminal marker, carrying
     * the full envelope (id/object/created/model/index) the openai-php SDK's
     * CreateStreamedResponse requires — byte-identical to the fixture
     * OpenAIProviderTest::testCompleteStreamPayloadLeadsWithSystemPrompt
     * feeds createStreamed(). Sglang/Custom parse only `choices[0].delta`,
     * so the extra keys are harmless to them.
     */
    private const SSE_BODY = "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\",\"created\":1,\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"}}]}\n\ndata: [DONE]\n\n";

    // =========================================================================
    // Derived roster
    // =========================================================================

    public function testEveryProviderImplementerHasATransmissionContract(): void
    {
        // Derived roster (rule 15: derive, never hand-maintain): the SAME
        // derivation ProviderRequestResponseTest::providerImplementers() runs
        // for its streamed-usage contract, shared so the two contracts cannot
        // drift apart. A future provider with no transmission entry reds this
        // test on day one.
        $implementers = ProviderRequestResponseTest::providerImplementers();

        // A multi-body provider contributes several rows under one class, and
        // the roster diff is about CLASS coverage, so drop the
        // `#<discriminator>` suffix and de-duplicate before comparing.
        $contracted = array_values(array_unique(array_map(
            static function (string $key): string {
                $fqcn = explode('#', $key)[0];

                return substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            },
            array_keys(self::TRANSMISSION_CONTRACT),
        )));

        $this->assertSame(
            ['EchoProvider'],
            array_values(array_diff($implementers, $contracted)),
            'Every ProviderInterface implementer except EchoProvider must transmit '
            . 'CompleteRequest::$systemPrompt onto the wire. EchoProvider is exempted WITH A '
            . 'NAMED REASON: it is a test double with no wire — it echoes a blockquote in PHP '
            . 'and never serializes a request payload (EchoProvider.php:18-23, 84-91). A NEW '
            . 'provider must add a TRANSMISSION_CONTRACT entry AND a per-provider transmission '
            . 'test.',
        );

        $this->assertSame(
            [],
            array_values(array_diff($contracted, $implementers)),
            'a TRANSMISSION_CONTRACT entry names a class that no longer implements '
            . 'ProviderInterface; delete the entry.',
        );
    }

    // =========================================================================
    // Sglang — leading `messages[0]` system message, OpenAI chat/completions
    // order (SglangProvider.php:672-677). Both paths share buildParams().
    // =========================================================================

    public function testSglangTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths(): void
    {
        // Driven through a mocked Guzzle client the way
        // SglangProviderRequestBuildingTest drives it (history middleware,
        // then the decoded wire body). Both paths are pinned independently:
        // "complete() passes" was exactly the conflation that hid the same
        // bug in OpenAIProvider::completeStream().
        $provider = $this->sglangProvider();
        $provider->complete($this->request());

        $sent = $this->sentBody();
        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $sent['messages'][0]);
        // Prepended, not replacing: the original message still follows, untouched.
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($sent['messages'], 1));
        // The sentinel rides the protocol's system slot and nowhere else.
        $this->assertSame(1, substr_count((string) json_encode($sent), self::SENTINEL));

        $streamProvider = $this->sglangProvider(self::SSE_BODY);
        iterator_to_array($streamProvider->completeStream($this->request()));

        $streamed = $this->sentBody();
        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $streamed['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($streamed['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($streamed), self::SENTINEL));
    }

    public function testSglangNullSystemPromptTransmitsNothing(): void
    {
        // The guard lives in the shared buildParams(), so one path pins both.
        // '' is "unset" for this provider (SglangProvider.php:672), matching
        // the optional-knob filter below it.
        $provider = $this->sglangProvider();
        $provider->complete($this->request(null));

        // Exact shape: no system element may appear, empty or otherwise.
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $this->sentBody()['messages']);
    }

    // =========================================================================
    // Custom — leading `messages[0]` system message, same OpenAI shape
    // (CustomProvider.php:155-160 / :210-215). Both paths share the guard.
    // =========================================================================

    public function testCustomTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths(): void
    {
        // Same harness family as CustomProviderTest (a MockHandler's wire
        // body); history middleware is used here so one sentBody() helper
        // serves both this provider and Sglang.
        $provider = $this->customProvider();
        $provider->complete($this->request());

        $sent = $this->sentBody();
        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $sent['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($sent['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($sent), self::SENTINEL));

        $streamProvider = $this->customProvider(self::SSE_BODY);
        iterator_to_array($streamProvider->completeStream($this->request()));

        $streamed = $this->sentBody();
        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $streamed['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($streamed['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($streamed), self::SENTINEL));
    }

    public function testCustomNullSystemPromptTransmitsNothing(): void
    {
        // The guard lives in the shared message-prepend block, so one path
        // pins both. '' is "unset" for this provider (CustomProvider.php:155).
        $provider = $this->customProvider();
        $provider->complete($this->request(null));

        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $this->sentBody()['messages']);
    }

    // =========================================================================
    // OpenAI — leading `messages[0]` system message
    // (OpenAIProvider.php:90-95 / :127-130). Driven through captured
    // create()/createStreamed() params, the way OpenAIProviderTest drives it.
    // =========================================================================

    public function testOpenAiTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths(): void
    {
        $captured = [];
        $provider = $this->openAiProviderWithCapture($captured);

        $provider->complete($this->request());

        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $captured['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($captured['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($captured), self::SENTINEL));

        iterator_to_array($provider->completeStream($this->request()));

        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $captured['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($captured['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($captured), self::SENTINEL));
    }

    public function testOpenAiNullSystemPromptTransmitsNothing(): void
    {
        $captured = [];
        $provider = $this->openAiProviderWithCapture($captured);

        $provider->complete($this->request(null));

        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $captured['messages']);
    }

    public function testOpenAiEmptyStringSystemPromptIsTransmittedBecauseTheGuardIsNotNullOnly(): void
    {
        // Measured guard difference: OpenAIProvider's guard is `!== null` only
        // (OpenAIProvider.php:90, :127), so '' — which Sglang/Custom/Vertex
        // treat as "unset" — IS transmitted here. Pin the measured behaviour
        // so the guards cannot silently drift together.
        $captured = [];
        $provider = $this->openAiProviderWithCapture($captured);

        $provider->complete($this->request(''));

        $this->assertSame(['role' => 'system', 'content' => ''], $captured['messages'][0]);
    }

    // =========================================================================
    // Bedrock — Converse top-level `system` block list, never inside messages
    // (BedrockProvider.php:164-166 / :215-217 via systemBlocks() :337-343).
    // =========================================================================

    public function testBedrockTransmitsSystemPromptInTheConverseSystemBlockListOnBothPaths(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
        ]));
        $provider = new BedrockProvider($this->offlineRuntimeClient($mock), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $provider->complete($this->request());

        $sent = $mock->getLastCommand()->toArray();
        $this->assertSame([['text' => self::SENTINEL]], $sent['system']);
        // Converse has no per-message `system` role: system text lives ONLY in
        // the top-level block list, so messages must carry no system entry at
        // all — and no smuggled sentinel either.
        $this->assertSame([['role' => 'user', 'content' => [['text' => 'Hi']]]], $sent['messages']);
        $this->assertSame(1, substr_count((string) json_encode($sent), self::SENTINEL));

        $streamMock = new MockHandler();
        $streamMock->append(new Result(['stream' => new \ArrayIterator([])]));
        $streamProvider = new BedrockProvider($this->offlineRuntimeClient($streamMock), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        iterator_to_array($streamProvider->completeStream($this->request()));

        $streamed = $streamMock->getLastCommand()->toArray();
        $this->assertSame([['text' => self::SENTINEL]], $streamed['system']);
        $this->assertSame([['role' => 'user', 'content' => [['text' => 'Hi']]]], $streamed['messages']);
        $this->assertSame(1, substr_count((string) json_encode($streamed), self::SENTINEL));
    }

    public function testBedrockNullSystemPromptTransmitsNothing(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
        ]));
        $provider = new BedrockProvider($this->offlineRuntimeClient($mock), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $provider->complete($this->request(null));

        // systemBlocks() returns [] when the prompt is null, and complete()
        // only sets the key for a non-empty list (BedrockProvider.php:164-166).
        $this->assertArrayNotHasKey('system', $mock->getLastCommand()->toArray());
    }

    public function testBedrockEmptyStringSystemPromptIsTransmittedBecauseTheGuardIsNotNullOnly(): void
    {
        // Measured guard difference: systemBlocks() checks `!== null` only
        // (BedrockProvider.php:341), so '' — which Sglang/Custom/Vertex treat
        // as "unset" — IS shaped into a system block. The wire itself cannot
        // carry it: the AWS SDK's Converse validator rejects a zero-length
        // text block before any I/O (measured 2026-08-26: "expected string
        // length to be >= 1"), so the honest pin is the shaper's output, not
        // the serialized wire. Reflect systemBlocks() the way
        // BedrockProviderTest reflects private methods.
        $provider = new BedrockProvider(
            $this->offlineRuntimeClient(new MockHandler()),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
        );

        $method = new \ReflectionMethod(BedrockProvider::class, 'systemBlocks');
        $method->setAccessible(true);

        $this->assertSame(
            [['text' => '']],
            $method->invoke($provider, $this->request('')),
        );
    }

    // =========================================================================
    // Vertex — TWO body builders, selected by isAnthropicModel()
    // (VertexProvider.php:231). The Anthropic arm hoists into the body's
    // top-level `system` string (VertexProvider.php:455-458): a `system` role
    // inside messages is a 400 on the Anthropic API. The Google arm hoists
    // into `instances[0].context` (VertexProvider.php:1015-1017): that
    // envelope has no system role at all, and formatMessages()'s
    // `default => 'user'` arm would otherwise deliver the prompt as an
    // ordinary user turn. Both arms go through the one joiner,
    // systemInstruction() :508. Driving only the Anthropic arm is how the
    // Google arm's silence survived the whole of Phase 1.
    // =========================================================================

    public function testVertexTransmitsSystemPromptInTheAnthropicTopLevelSystemFieldOnBothPaths(): void
    {
        $captured = null;
        $provider = $this->vertexProvider([], $captured);
        $provider->complete($this->request(model: 'claude-3-sonnet@20240229'));

        $this->assertSame(self::SENTINEL, $captured['body']['system']);
        $this->assertSame(
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
            $captured['body']['messages'],
            'a `system` role inside messages is a 400 on the Anthropic API',
        );
        $this->assertSame(1, substr_count((string) json_encode($captured['body']), self::SENTINEL));

        $streamed = null;
        $streamProvider = $this->vertexStreamer([], $streamed);
        iterator_to_array($streamProvider->completeStream($this->request(model: 'claude-3-sonnet@20240229')));

        $this->assertSame(self::SENTINEL, $streamed['body']['system']);
        $this->assertSame(
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
            $streamed['body']['messages'],
        );
        $this->assertSame(1, substr_count((string) json_encode($streamed['body']), self::SENTINEL));
    }

    public function testVertexNullSystemPromptTransmitsNothing(): void
    {
        $captured = null;
        $provider = $this->vertexProvider([], $captured);
        $provider->complete($this->request(null, 'claude-3-sonnet@20240229'));

        // systemInstruction() returns null when no part exists, and anthropicBody()
        // only sets the key for a non-null value (VertexProvider.php:455-458).
        $this->assertArrayNotHasKey('system', $captured['body']);
    }

    public function testVertexTransmitsSystemPromptInTheGoogleInstanceContextOnBothPaths(): void
    {
        $captured = null;
        $provider = $this->vertexProvider([], $captured);
        $provider->complete($this->request(model: self::VERTEX_GOOGLE_MODEL));

        $this->assertSame('predict', $captured['method']);
        $this->assertSame(self::SENTINEL, $captured['body']['instances'][0]['context']);
        $this->assertSame(
            [['role' => 'user', 'content' => 'Hi']],
            $captured['body']['instances'][0]['messages'],
            'the Google `instances` envelope has no system role; a prompt left in '
            . '`messages` reaches the model as an ordinary user turn',
        );
        $this->assertSame(1, substr_count((string) json_encode($captured['body']), self::SENTINEL));

        // NOT the streamer seam: completeStream() yields complete() for a
        // non-Anthropic model (VertexProvider.php:290-297), so the streaming
        // path is captured on the PREDICTOR. Asserting it separately is still
        // the point — complete() passing is not evidence about
        // completeStream(), which is exactly how the OpenAI arm hid this same
        // defect until P1.S3.
        $streamed = null;
        $streamProvider = $this->vertexProvider([], $streamed);
        iterator_to_array(
            $streamProvider->completeStream($this->request(model: self::VERTEX_GOOGLE_MODEL)),
            false,
        );

        $this->assertSame('predict', $streamed['method']);
        $this->assertSame(self::SENTINEL, $streamed['body']['instances'][0]['context']);
        $this->assertSame(1, substr_count((string) json_encode($streamed['body']), self::SENTINEL));
    }

    public function testVertexGoogleArmNullSystemPromptTransmitsNothing(): void
    {
        $captured = null;
        $provider = $this->vertexProvider([], $captured);
        $provider->complete($this->request(null, self::VERTEX_GOOGLE_MODEL));

        $this->assertArrayNotHasKey('context', $captured['body']['instances'][0]);
    }

    // =========================================================================
    // ClaudeCode — `--system-prompt` CLI argv pair
    // (ClaudeCodeProvider.php:80 / :105 -> ClaudeCodeInvocation.php:75-78).
    // The provider hands its invocation exactly the options below; the wire
    // shaper is printModeArgs(), driven the way ClaudeCodeProviderTest drives
    // it — the execute() step that would follow is proc_open and cannot run
    // in a unit test.
    // =========================================================================

    public function testClaudeCodeTransmitsSystemPromptAsASystemPromptArgvPairOnBothPaths(): void
    {
        $invocation = new ClaudeCodeInvocation();

        $completeArgs = $invocation->printModeArgs('Hi', [
            'format' => 'json',
            'bare' => true,
            'systemPrompt' => self::SENTINEL,
        ]);
        $completeFlag = array_search('--system-prompt', $completeArgs, true);
        $this->assertNotFalse($completeFlag, 'complete() must pass --system-prompt');
        $this->assertSame(self::SENTINEL, $completeArgs[$completeFlag + 1]);
        $this->assertSame(1, substr_count(implode(' ', $completeArgs), self::SENTINEL));

        $streamArgs = $invocation->printModeArgs('Hi', [
            'format' => 'stream-json',
            'bare' => true,
            'systemPrompt' => self::SENTINEL,
        ]);
        $streamFlag = array_search('--system-prompt', $streamArgs, true);
        $this->assertNotFalse($streamFlag, 'completeStream() must pass --system-prompt');
        $this->assertSame(self::SENTINEL, $streamArgs[$streamFlag + 1]);
        $this->assertSame(1, substr_count(implode(' ', $streamArgs), self::SENTINEL));
    }

    public function testClaudeCodeNullSystemPromptTransmitsNoFlag(): void
    {
        // The provider always passes 'systemPrompt' => $request->systemPrompt
        // (ClaudeCodeProvider.php:80); printModeArgs() gates the pair on
        // isset() (ClaudeCodeInvocation.php:75-78), so null -> no flag at all.
        // (Note: '' would still emit the pair with an empty value — the same
        // `!== null`-only polarity as OpenAI/Bedrock, by a different idiom.)
        $invocation = new ClaudeCodeInvocation();

        $args = $invocation->printModeArgs('Hi', [
            'format' => 'json',
            'bare' => true,
            'systemPrompt' => null,
        ]);

        $this->assertNotContains('--system-prompt', $args);
    }

    // =========================================================================
    // Harness — each provider driven exactly as its own suite drives it.
    // =========================================================================

    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * Same mock harness as SglangProviderRequestBuildingTest::provider().
     */
    private function sglangProvider(string $body = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'): SglangProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new GuzzleMockHandler([new Response(200, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://api.example.com',
            'gpt-4',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
    }

    /**
     * Same mock family as CustomProviderTest (a MockHandler's wire body),
     * routed through history middleware so one sentBody() serves both it and
     * Sglang.
     */
    private function customProvider(string $body = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'): CustomProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new GuzzleMockHandler([new Response(200, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
            true,
            true,
        );
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    /**
     * Same mock chain as OpenAIProviderTest::testCompleteStreamPayloadLeadsWithSystemPrompt:
     * the chat mock records the params array it was handed, on both the
     * create() and the createStreamed() wire methods.
     *
     * $captured is taken by reference (the same idiom as
     * {@see vertexProvider()}'s $captured) so the closures' writes are
     * visible to the caller after this helper returns.
     *
     * @param array<string, mixed> $captured
     */
    private function openAiProviderWithCapture(array &$captured): OpenAIProvider
    {
        $captured = [];
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);

        $chat->method('create')->willReturnCallback(function (array $params) use (&$captured): ChatCreateResponse {
            $captured = $params;

            return ChatCreateResponse::from([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion',
                'created' => 1,
                'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], MetaInformation::from([]));
        });

        $chat->method('createStreamed')->willReturnCallback(function (array $params) use (&$captured): StreamResponse {
            $captured = $params;

            return new StreamResponse(
                CreateStreamedResponse::class,
                new Response(200, [], Utils::streamFor(self::SSE_BODY)),
            );
        });

        return new OpenAIProvider($client, 'gpt-4o');
    }

    /**
     * Same offline client as BedrockProviderTest::offlineRuntimeClient().
     */
    private function offlineRuntimeClient(MockHandler $handler): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $handler,
        ]);
    }

    /**
     * Same unary-seam provider as VertexProviderTest::providerWithPredictor().
     *
     * @param array<string, mixed> $return
     * @param-out array<string, mixed> $captured
     */
    private function vertexProvider(array $return = [], ?array &$captured = null): VertexProvider
    {
        $captured = ['called' => false];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'claude-3-sonnet@20240229',
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
     * Same streaming-seam provider as VertexProviderTest::providerWithStreamer().
     *
     * @param array<int, array<string, mixed>> $events
     * @param-out array<string, mixed> $captured
     */
    private function vertexStreamer(array $events, ?array &$captured = null): VertexProvider
    {
        $captured = ['called' => false];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'claude-3-sonnet@20240229',
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

    /**
     * The identical CompleteRequest every covered provider is handed: one
     * user turn plus the sentinel on $systemPrompt by default.
     */
    private function request(?string $systemPrompt = self::SENTINEL, string $model = 'gpt-4'): CompleteRequest
    {
        return new CompleteRequest(
            model: $model,
            messages: [new UserMessage('Hi')],
            systemPrompt: $systemPrompt,
        );
    }
}