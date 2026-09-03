<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateStreamedResponse as ChatCreateStreamedResponse;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;
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
    /**
     * The token total each provider's contract fixture carries on the wire,
     * under the per-delta contract
     * {@see \SugarCraft\Crush\Providers\ProviderInterface::completeStream()}.
     *
     * Three families: Bedrock (terminal metadata event's inputTokens +
     * outputTokens, BedrockProvider.php:500-503) and Vertex (disjoint
     * message_start input_tokens + message_delta output_tokens buckets,
     * VertexProvider.php:1116-1144) carry usage the implementation MUST read:
     * their tests assertSame() this total, and a provider that stops reading
     * the wire reds. Sglang/Custom/OpenAI currently hardcode tokensUsed: 0
     * (SglangProvider.php:1271, CustomProvider.php:488, OpenAIProvider.php:357).
     * Their fixtures lay the wire's cumulative total on EVERY chunk (10/20/30)
     * — a deliberately E24-hostile shape, not the real wire (the real
     * OpenAI-compatible stream is usage:null-per-chunk, captured live in
     * SglangProviderStreamingTest.php:251-259) — and their tests assert the sum
     * is in {0, 30}: 0 (current code, allowed as "nothing reported",
     * ProviderInterface.php:53-54) or 30 (compliant terminal-once emission,
     * ProviderInterface.php:49-52). Naive per-chunk accumulation of a
     * cumulative wire (the E24 failure mode) sums 60 and reds.
     *
     * ClaudeCode's stream-json wire carries no usage at all
     * (ClaudeCodeProvider.php:374-393), so 0 is the only answer; its test
     * asserts assertSame(). EchoProvider is deliberately absent: it is a test
     * double with no usage concept — it echoes a blockquote in PHP with no
     * tokensUsed/costUsd on any chunk (EchoProvider.php:84-91). See
     * testEveryProviderImplementerHasAStreamedUsageContractFixture for the
     * derived-roster assertion that names this exemption.
     *
     * @var array<class-string, int>
     */
    private const STREAMED_USAGE_CONTRACT = [
        SglangProvider::class => 30,
        CustomProvider::class => 30,
        OpenAIProvider::class => 30,
        BedrockProvider::class => 30,
        VertexProvider::class => 30,
        ClaudeCodeProvider::class => 0,
    ];

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

    // =========================================================================
    // Streamed usage contract (E24)
    // =========================================================================
    //
    // ProviderInterface::completeStream() requires PER-DELTA usage: each
    // yielded CompleteResponse's tokensUsed/costUsd is that chunk's own
    // increment, and consumers sum across the whole stream
    // (Runtime::runStreaming() -> Usage::sum()). Each test feeds one provider
    // a discriminating wire sequence and pins the sum a correct per-delta
    // implementation must produce: real-shaped where the real wire
    // discriminates (Bedrock's terminal metadata event, Vertex's disjoint
    // buckets), deliberately E24-hostile where it does not — Sglang/Custom/OpenAI
    // get the wire's cumulative total on EVERY chunk, because their real wire
    // (usage:null-per-chunk, captured in SglangProviderStreamingTest.php:251-259)
    // cannot separate "reads nothing" from "reads the terminal total once".
    // Fixtures are real-shaped or deliberately so, never invented: provenance
    // is cited per fixture. Every expected total lives in
    // STREAMED_USAGE_CONTRACT, whose completeness the roster test below derives
    // from src/Providers/ rather than hand-maintains.

    /**
     * The derived roster of ProviderInterface implementers: every class in
     * src/Providers/ that implements the interface, short-named and sorted
     * (rule 15: derive, never hand-maintain).
     *
     * Born in P1.S5 for the streamed-usage contract's fixture-completeness
     * test. P1.S7's {@see SystemPromptTransmissionMatrixTest} consumes the
     * SAME derivation for its transmission contract, so the two contracts
     * share one roster and cannot drift apart.
     *
     * @return list<string>
     */
    public static function providerImplementers(): array
    {
        $implementers = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Providers/*.php') as $file) {
            $short = basename($file, '.php');
            $fqcn = 'SugarCraft\Crush\Providers\\' . $short;
            if (in_array(ProviderInterface::class, class_implements($fqcn) ?: [], true)) {
                $implementers[] = $short;
            }
        }
        sort($implementers);

        return $implementers;
    }

    public function testEveryProviderImplementerHasAStreamedUsageContractFixture(): void
    {
        // Derived roster (rule 15: derive, never hand-maintain): scan
        // src/Providers/ for classes implementing ProviderInterface. A future
        // provider with no fixture entry reds this test.
        $implementers = self::providerImplementers();

        $fixtured = array_map(
            static fn (string $fqcn): string => substr($fqcn, (int) strrpos($fqcn, '\\') + 1),
            array_keys(self::STREAMED_USAGE_CONTRACT),
        );

        $this->assertSame(
            ['EchoProvider'],
            array_values(array_diff($implementers, $fixtured)),
            'Every ProviderInterface implementer except EchoProvider must have a streamed-usage '
            . 'contract fixture. EchoProvider is exempted WITH A NAMED REASON: it is a test '
            . 'double with no usage concept — it echoes a blockquote in PHP and its '
            . 'completeStream() yields CompleteResponse objects carrying no tokensUsed/costUsd '
             . 'at all (EchoProvider.php:109-124, 84-91), mirroring the P1.S7 precedent for '
            . 'exempting a stub. A NEW provider must add a STREAMED_USAGE_CONTRACT entry AND a '
            . 'per-provider contract test.',
        );

        $this->assertSame(
            [],
            array_values(array_diff($fixtured, $implementers)),
            'a STREAMED_USAGE_CONTRACT entry names a class that no longer implements '
            . 'ProviderInterface; delete the entry.',
        );
    }

    public function testSglangStreamedUsageIsPerDeltaNotCumulative(): void
    {
        // The fixture is a deliberately HYPOTHETICAL E24-hostile discriminating
        // shape, NOT the real server: the real OpenAI-compatible wire is
        // usage:null-per-chunk, captured live in
        // SglangProviderStreamingTest.php:251-259 — the contract test's job is
        // to pin the contract, not the current server. The wire's cumulative
        // total (10, 20, 30) rides EVERY chunk.
        $sse = 'data: {"choices":[{"delta":{"content":"Hel"}}],"usage":{"total_tokens":10}}' . "\n"
            . 'data: {"choices":[{"delta":{"content":"lo"}}],"usage":{"total_tokens":20}}' . "\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"total_tokens":30}}' . "\n"
            . 'data: [DONE]' . "\n";

        $mock = new MockHandler([new Response(200, [], $sse)]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $provider = new SglangProvider('https://api.example.com', 'gpt-4', null, $client);

        $chunks = iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'gpt-4', messages: [new UserMessage('hi')])
        ));

        $sum = array_sum(array_map(static fn (CompleteResponse $c): int => $c->tokensUsed, $chunks));

        // parseChunk hardcodes tokensUsed: 0 (SglangProvider.php:1271), so the sum
        // is 0 — allowed under the contract as "nothing reported"
        // (ProviderInterface.php:53-54). A compliant terminal-once emission
        // (ProviderInterface.php:49-52) yields 30 — also allowed. Naive
        // per-chunk accumulation of a cumulative wire (the E24 failure mode)
        // yields 10+20+30=60, which must stay RED.
        $this->assertContains(
            $sum,
            [0, self::STREAMED_USAGE_CONTRACT[SglangProvider::class]],
            'streamed tokensUsed sum must be 0 ("nothing reported") or the wire total emitted once (30) '
            . '— never 60, which would mean the cumulative wire total was summed per chunk (E24).',
        );
    }

    public function testCustomStreamedUsageIsPerDeltaNotCumulative(): void
    {
        // The fixture is a deliberately HYPOTHETICAL E24-hostile discriminating
        // shape, NOT the real server: the real OpenAI-compatible wire is
        // usage:null-per-chunk, captured live in
        // SglangProviderStreamingTest.php:251-259 — the contract test's job is
        // to pin the contract, not the current server. The wire's cumulative
        // total (10, 20, 30) rides EVERY chunk.
        $sse = 'data: {"choices":[{"delta":{"content":"Hel"}}],"usage":{"total_tokens":10}}' . "\n"
            . 'data: {"choices":[{"delta":{"content":"lo"}}],"usage":{"total_tokens":20}}' . "\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"total_tokens":30}}' . "\n"
            . 'data: [DONE]' . "\n";

        $mock = new MockHandler([new Response(200, [], $sse)]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $provider = new CustomProvider('custom', 'https://api.example.com', 'gpt-4', null, $client, true, true);

        $chunks = iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'gpt-4', messages: [new UserMessage('hi')])
        ));

        $sum = array_sum(array_map(static fn (CompleteResponse $c): int => $c->tokensUsed, $chunks));

        // parseChunk hardcodes tokensUsed: 0 (CustomProvider.php:488), so the sum
        // is 0 — allowed under the contract as "nothing reported"
        // (ProviderInterface.php:53-54). A compliant terminal-once emission
        // (ProviderInterface.php:49-52) yields 30 — also allowed. Naive
        // per-chunk accumulation of a cumulative wire (the E24 failure mode)
        // yields 10+20+30=60, which must stay RED.
        $this->assertContains(
            $sum,
            [0, self::STREAMED_USAGE_CONTRACT[CustomProvider::class]],
            'streamed tokensUsed sum must be 0 ("nothing reported") or the wire total emitted once (30) '
            . '— never 60, which would mean the cumulative wire total was summed per chunk (E24).',
        );
    }

    public function testOpenAiStreamedUsageIsPerDeltaNotCumulative(): void
    {
        // parseChunk() takes the SDK's streamed chunk objects; build them with
        // the real openai-php factory so toArray() is byte-for-byte the SDK
        // shape (the ReasoningExtractionTest.php:290 pattern). The fixture is a
        // deliberately HYPOTHETICAL E24-hostile discriminating shape, NOT the
        // real server: the real OpenAI-compatible wire is usage:null-per-chunk,
        // captured live in SglangProviderStreamingTest.php:251-259 — the
        // contract test's job is to pin the contract, not the current server.
        // The wire's cumulative total rides EVERY chunk (10, 20, 30).
        $chunks = [
            ChatCreateStreamedResponse::from([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion.chunk',
                'created' => 1,
                'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'delta' => ['content' => 'Hel'], 'finish_reason' => null]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
            ]),
            ChatCreateStreamedResponse::from([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion.chunk',
                'created' => 1,
                'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => null]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            ]),
            ChatCreateStreamedResponse::from([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion.chunk',
                'created' => 1,
                'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            ]),
        ];

        $provider = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $sum = 0;
        foreach ($chunks as $chunk) {
            $sum += $method->invoke($provider, $chunk)->tokensUsed;
        }

        // parseChunk hardcodes tokensUsed: 0 (OpenAIProvider.php:357), so the sum
        // is 0 — allowed under the contract as "nothing reported"
        // (ProviderInterface.php:53-54). A compliant terminal-once emission
        // (ProviderInterface.php:49-52) yields 30 — also allowed. Naive
        // per-chunk accumulation of a cumulative wire (the E24 failure mode)
        // yields 10+20+30=60, which must stay RED.
        $this->assertContains(
            $sum,
            [0, self::STREAMED_USAGE_CONTRACT[OpenAIProvider::class]],
            'streamed tokensUsed sum must be 0 ("nothing reported") or the wire total emitted once (30) '
            . '— never 60, which would mean the cumulative wire total was summed per chunk (E24).',
        );
    }

    public function testBedrockStreamedUsageLandsOnceOnTheTerminalMetadataEvent(): void
    {
        // ConverseStream event arrays exactly as Aws' EventParsingIterator
        // yields them (the BedrockProviderTest.php:562-568 shape): text arrives
        // as contentBlockDelta events, and usage lands once, on the terminal
        // metadata event — every earlier event genuinely has none to report
        // (BedrockProvider.php:500-503).
        $events = [
            ['contentBlockDelta' => ['delta' => ['text' => 'Hel']]],
            ['contentBlockDelta' => ['delta' => ['text' => 'lo']]],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => ['inputTokens' => 20, 'outputTokens' => 10]]],
        ];

        $provider = new BedrockProvider(
            $this->createMock(BedrockRuntimeClient::class),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
        );

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $sum = 0;
        foreach ($events as $event) {
            $sum += $method->invokeArgs($provider, [$event, 'anthropic.claude-sonnet-4-6'])->tokensUsed;
        }

        // 20 input + 10 output, reported once on the terminal metadata event:
        // the sum a correct per-delta implementation must produce. This reds if
        // the usage read is deleted (every event then reports 0) or if usage
        // were accumulated instead of read fresh per event.
        $this->assertSame(self::STREAMED_USAGE_CONTRACT[BedrockProvider::class], $sum);
    }

    public function testVertexStreamedUsageIsSplitAcrossDisjointBucketEvents(): void
    {
        // Anthropic-on-Vertex SSE events (VertexProvider.php:1116-1144;
        // Usage.php:68-77): input tokens on message_start, output tokens on the
        // terminal message_delta — two usage-bearing chunks, disjoint buckets,
        // nothing repeated on every chunk.
        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 20]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'lo']],
            ['type' => 'message_delta', 'usage' => ['output_tokens' => 10]],
            ['type' => 'message_stop'],
        ];

        $provider = new VertexProvider(
            'my-project',
            'us-central1',
            'claude-3-sonnet@20240229',
            null,
            static function (string $endpoint, string $method, array $body) use ($events): iterable {
                yield from $events;
            },
        );

        $chunks = iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'claude-3-sonnet@20240229', messages: [new UserMessage('hi')])
        ));

        $sum = array_sum(array_map(static fn (CompleteResponse $c): int => $c->tokensUsed, $chunks));

        // 20 input + 10 output across the two disjoint bucket events: the sum
        // a correct per-delta implementation must produce. This reds if either
        // bucket read is deleted (the sum drops to the surviving bucket).
        $this->assertSame(self::STREAMED_USAGE_CONTRACT[VertexProvider::class], $sum);
    }

    public function testClaudeCodeStreamedUsageIsPerDeltaNotCumulative(): void
    {
        // The stream-json wire format carries NO usage at all, and parseChunk
        // reads none (ClaudeCodeProvider.php:374-393): the only yieldable shape
        // is event.delta.type = text_delta. completeStream() cannot be driven
        // in a unit test — it spawns a child via proc_open (ClaudeCodeProvider.php:118-120) —
        // so parseChunk is driven by reflection with the same wire sequence a
        // full stream would carry.
        $events = [
            ['event' => ['delta' => ['type' => 'text_delta', 'text' => 'Hel']]],
            ['event' => ['delta' => ['type' => 'text_delta', 'text' => 'lo']]],
        ];

        $provider = new ClaudeCodeProvider(new ClaudeCodeInvocation());

        $method = new \ReflectionMethod($provider, 'parseChunk');
        $method->setAccessible(true);

        $sum = 0;
        foreach ($events as $event) {
            $sum += $method->invokeArgs($provider, [$event])->tokensUsed;
        }

        // The stream-json wire carries NO usage at all, so 0 — "nothing
        // reported" — is the only possible answer; there is no terminal total
        // to emit once. parseChunk reports none (ClaudeCodeProvider.php:381-382),
        // so the honest streamed total is "nothing reported" = 0. Red if the
        // provider ever starts fabricating or accumulating usage on this path
        // — the E24 failure mode.
        $this->assertSame(self::STREAMED_USAGE_CONTRACT[ClaudeCodeProvider::class], $sum);
    }
}
