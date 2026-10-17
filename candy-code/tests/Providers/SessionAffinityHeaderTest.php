<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Tools\Tool;

/**
 * The hashed session-affinity header on the wire (prompt_plan.md P10.S4).
 *
 * EVERY assertion below reads a CAPTURED PSR-7 request, not the builder's
 * return value: the trait could hand back a perfect array and the provider
 * could still drop it on the floor between here and `post()`, which is the
 * exact class of defect this plan exists to fix (§16.1 - "implemented" is not
 * "reachable"). The capture harness is the MockHandler + Middleware::history
 * shape of SglangProviderRequestBuildingTest, COPIED IN SPIRIT and uniquely
 * named: DuplicatedTestHelperDriftTest's byte-twin guard reddens a private
 * helper that mirrors an existing one with small edits, and this suite's whole
 * reason to exist is transport, so it gets its own transport names.
 *
 * THE SIX-SITE LEDGER this file walks, one per `post()` in the two declared
 * transports: SglangProvider complete / completeStream / embeddings and
 * CustomProvider complete / completeStream / embeddings. The trait is shared;
 * the six call sites are not one code path, so each carries its own value
 * test rather than trusting transitivity.
 *
 * THE MERGE PIN (testClientDefaultHeadersMergeRatherThan…) is load-bearing
 * beyond this feature: per-request `headers` are only safe because Guzzle
 * merges them with client defaults per-name. If a future version swapped in
 * replace semantics, Authorization would vanish from every call - and that
 * regression is exactly what the co-presence assertion reddens on, for BOTH
 * providers.
 */
final class SessionAffinityHeaderTest extends TestCase
{
    private const BASE = 'https://api.example.com';

    private const RAW_SESSION = 'sg-session-42-raw';

    private const JSON_BODY = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}';

    private const EMBEDDINGS_BODY = '{"data":[{"embedding":[0.1,0.2],"index":0}]}';

    /**
     * SSE-shaped stream body in the ProviderRequestResponseTest house shape:
     * one delta frame, one finish frame, the `[DONE]` sentinel.
     */
    private const SSE_BODY = 'data: {"choices":[{"delta":{"content":"Hel"}}]}' . "\n"
        . 'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}' . "\n"
        . 'data: [DONE]' . "\n";

    /** @var list<array<string, mixed>> */
    private array $affinityHistory = [];

    /**
     * SglangProvider over a mock transport carrying one queued response per
     * body, every request recorded into {@see $affinityHistory}.
     *
     * The client mirrors `openAiCompatible()`'s defaults (Content-Type, and
     * Authorization when an api key is given) so the captured request is the
     * factory's wire shape, not a bare-client fiction; `$bareClient` switches
     * to the injected-bare-client shape unit suites build.
     */
    private function sglangAffinityTransport(
        ?string $sessionId,
        array $bodies = [self::JSON_BODY],
        ?string $apiKey = null,
        bool $bareClient = false,
    ): SglangProvider {
        $client = $this->affinityMockClient($apiKey, $bareClient, count($bodies), $bodies);

        return new SglangProvider(
            self::BASE,
            'MiniMax-M2.7',
            $apiKey,
            $client,
            null,
            null,
            [],
            $sessionId,
        );
    }

    /** Same transport harness as {@see sglangAffinityTransport()} for CustomProvider. */
    private function customAffinityTransport(
        ?string $sessionId,
        array $bodies = [self::JSON_BODY],
        ?string $apiKey = null,
    ): CustomProvider {
        $client = $this->affinityMockClient($apiKey, false, count($bodies), $bodies);

        return new CustomProvider(
            'custom',
            self::BASE,
            'some-model',
            $apiKey,
            $client,
            true,
            true,
            $sessionId,
        );
    }

    /**
     * @param list<string> $bodies
     */
    private function affinityMockClient(?string $apiKey, bool $bare, int $responses, array $bodies): Client
    {
        $this->affinityHistory = [];

        $stack = HandlerStack::create(new MockHandler(array_map(
            static fn (string $body): Response => new Response(200, [], $body),
            $bodies,
        )));
        $stack->push(Middleware::history($this->affinityHistory));

        $options = ['base_uri' => self::BASE . '/', 'handler' => $stack];

        if (!$bare) {
            $headers = ['Content-Type' => 'application/json'];

            if ($apiKey !== null) {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }

            $options['headers'] = $headers;
        }

        return new Client($options);
    }

    private function capturedAffinityRequest(int $index): RequestInterface
    {
        // Key existence, not a count: one test may build several providers and
        // their middlewares share this container slot, so history length is
        // not $index + 1 — but a MISSING key is exactly the "the call never
        // reached the transport" regression this guard is for.
        $this->assertArrayHasKey($index, $this->affinityHistory, 'the call under test never reached the transport');

        return $this->affinityHistory[$index]['request'];
    }

    public function testTheAffinityHeaderOnTheWireIsTheFullSha256OfTheSessionId(): void
    {
        $provider = $this->sglangAffinityTransport(self::RAW_SESSION);

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')], systemPrompt: 'sys'));

        $header = $this->capturedAffinityRequest(0)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);

        self::assertSame(1, preg_match('/\A[0-9a-f]{64}\z/', $header), 'value form: 64 lowercase hex, unpadded');
        self::assertSame(hash('sha256', self::RAW_SESSION), $header);
    }

    public function testTheWireHeaderCarriesNoTraceOfTheRawSessionId(): void
    {
        $provider = $this->sglangAffinityTransport(self::RAW_SESSION);

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')]));

        $header = $this->capturedAffinityRequest(0)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);

        self::assertNotSame(self::RAW_SESSION, $header);
        self::assertFalse(str_contains($header, self::RAW_SESSION), 'the plan hard constraint: hashed, never raw');
        self::assertFalse(str_contains($header, 'session-42'));
    }

    public function testTheHeaderNameOnTheWireIsTheDeclaredLiteral(): void
    {
        self::assertSame('X-CandyCode-Session', SglangProvider::SESSION_AFFINITY_HEADER);
        self::assertSame('X-CandyCode-Session', CustomProvider::SESSION_AFFINITY_HEADER);

        $provider = $this->sglangAffinityTransport(self::RAW_SESSION);
        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')]));

        self::assertTrue($this->capturedAffinityRequest(0)->hasHeader('X-CandyCode-Session'));
    }

    public function testTwoCompleteCallsInOneSessionCarryTheIdenticalHeader(): void
    {
        $provider = $this->sglangAffinityTransport(self::RAW_SESSION, [self::JSON_BODY, self::JSON_BODY]);

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('one')]));
        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('two')]));

        $first = $this->capturedAffinityRequest(0)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);
        $second = $this->capturedAffinityRequest(1)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);

        self::assertSame($first, $second);
        self::assertSame(hash('sha256', self::RAW_SESSION), $first);
    }

    public function testTwoStreamedCallsInOneSessionCarryTheIdenticalHeader(): void
    {
        $provider = $this->sglangAffinityTransport(self::RAW_SESSION, [self::SSE_BODY, self::SSE_BODY]);

        iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('one')])
        ));
        iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('two')])
        ));

        $first = $this->capturedAffinityRequest(0)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);
        $second = $this->capturedAffinityRequest(1)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);

        self::assertSame($first, $second, 'stream is the live path - it must be affine too');
        self::assertSame(hash('sha256', self::RAW_SESSION), $first);
    }

    public function testTwoSessionsOnSeparateProvidersProduceDifferentHeaders(): void
    {
        $first = $this->sglangAffinityTransport('alpha-session');
        $second = $this->sglangAffinityTransport('beta-session');

        $first->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')]));
        $second->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')]));

        $firstHeader = $this->capturedAffinityRequest(0)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);

        $secondHeader = $this->capturedAffinityRequest(1)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);

        self::assertSame(hash('sha256', 'alpha-session'), $firstHeader);
        self::assertSame(hash('sha256', 'beta-session'), $secondHeader);
        self::assertNotSame($firstHeader, $secondHeader);
    }

    public function testANullSessionIdEmitsNoAffinityHeaderAndKeepsTheDefaults(): void
    {
        $provider = $this->sglangAffinityTransport(null, [self::JSON_BODY], 'key-1');

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')]));

        $request = $this->capturedAffinityRequest(0);
        self::assertSame('', $request->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('Bearer key-1', $request->getHeaderLine('Authorization'));
    }

    public function testABlankSessionIdEmitsNoAffinityHeaderRatherThanTheEmptyStringHash(): void
    {
        $provider = $this->sglangAffinityTransport('', [self::JSON_BODY]);

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')]));

        $header = $this->capturedAffinityRequest(0)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER);

        self::assertSame('', $header);
        self::assertNotSame(hash('sha256', ''), $header, 'a blank id must NOT pin every sessionless caller to one backend');
    }

    public function testClientDefaultHeadersMergeRatherThanBeingReplacedByTheAffinityHeader(): void
    {
        $provider = $this->sglangAffinityTransport(self::RAW_SESSION, [self::JSON_BODY], 'sk-guard');

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')]));

        $request = $this->capturedAffinityRequest(0);
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('Bearer sk-guard', $request->getHeaderLine('Authorization'));
        self::assertSame(hash('sha256', self::RAW_SESSION), $request->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER));
    }

    public function testTheAffinityHeaderRidesABareInjectedClientWithNoDefaultHeaders(): void
    {
        $provider = $this->sglangAffinityTransport(self::RAW_SESSION, [self::JSON_BODY], null, true);

        $provider->complete(new CompleteRequest(model: 'MiniMax-M2.7', messages: [new UserMessage('hi')]));

        self::assertSame(
            hash('sha256', self::RAW_SESSION),
            $this->capturedAffinityRequest(0)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER),
            'per-request merge is the whole design - an injected bare client must still carry it',
        );
    }

    public function testTheAgenticTitleAndSummaryConstructionShapesAllCarryTheHeader(): void
    {
        // The Done-when triad at the honest achievable level (step brief C):
        // title and summary are Runtime turns with tools=null (proven in-repo
        // by SglangProvider's temperature docblock), so the three shapes are
        // tools-set and two tools-null one-shot requests on one provider.
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('demo');
        $tool->method('description')->willReturn('A demo tool.');
        $tool->method('inputSchema')->willReturn(['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]);

        $provider = $this->sglangAffinityTransport(
            self::RAW_SESSION,
            [self::JSON_BODY, self::JSON_BODY, self::JSON_BODY],
        );

        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('turn')],
            tools: [$tool],
        ));
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('first user message')],
            systemPrompt: 'You generate a short title.',
        ));
        $provider->complete(new CompleteRequest(
            model: 'MiniMax-M2.7',
            messages: [new UserMessage('exchange transcript')],
            systemPrompt: 'You summarize exchanges.',
        ));

        $expected = hash('sha256', self::RAW_SESSION);

        foreach ([0, 1, 2] as $i) {
            self::assertSame(
                $expected,
                $this->capturedAffinityRequest($i)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER),
                "construction shape {$i} lost the affinity header",
            );
        }
    }

    public function testEmbeddingsCarriesTheAffinityHeader(): void
    {
        $provider = $this->sglangAffinityTransport(self::RAW_SESSION, [self::EMBEDDINGS_BODY]);

        $response = $provider->embeddings(new EmbeddingsRequest(model: 'embed-model', input: 'text'));

        self::assertSame(
            hash('sha256', self::RAW_SESSION),
            $this->capturedAffinityRequest(0)->getHeaderLine(SglangProvider::SESSION_AFFINITY_HEADER),
        );
        self::assertSame([[0.1, 0.2]], $response->embeddings);
    }

    public function testTheSglangOpenAiCompatibleFactoryPassesTheAffinityIdThrough(): void
    {
        $provider = SglangProvider::openAiCompatible(
            baseUrl: self::BASE,
            model: 'MiniMax-M2.7',
            sessionAffinityId: self::RAW_SESSION,
        );

        self::assertSame(
            [SglangProvider::SESSION_AFFINITY_HEADER => hash('sha256', self::RAW_SESSION)],
            $provider->sessionAffinityHeaders(),
        );

        $untouched = SglangProvider::openAiCompatible(baseUrl: self::BASE, model: 'MiniMax-M2.7');

        self::assertSame([], $untouched->sessionAffinityHeaders());
    }

    public function testCustomCompleteCarriesTheExactHashOnItsOwnSite(): void
    {
        $provider = $this->customAffinityTransport('custom-session-7');

        $provider->complete(new CompleteRequest(model: 'some-model', messages: [new UserMessage('hi')]));

        $header = $this->capturedAffinityRequest(0)->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER);

        self::assertSame(hash('sha256', 'custom-session-7'), $header);
        self::assertFalse(str_contains($header, 'custom-session-7'));
    }

    public function testCustomStreamCarriesTheIdenticalHashAcrossTwoCalls(): void
    {
        $provider = $this->customAffinityTransport('custom-session-7', [self::SSE_BODY, self::SSE_BODY]);

        iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'some-model', messages: [new UserMessage('one')])
        ));
        iterator_to_array($provider->completeStream(
            new CompleteRequest(model: 'some-model', messages: [new UserMessage('two')])
        ));

        $first = $this->capturedAffinityRequest(0)->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER);

        self::assertSame($first, $this->capturedAffinityRequest(1)->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER));
        self::assertSame(hash('sha256', 'custom-session-7'), $first);
    }

    public function testCustomEmbeddingsCarriesTheAffinityHeader(): void
    {
        $provider = $this->customAffinityTransport('custom-session-7', [self::EMBEDDINGS_BODY]);

        $provider->embeddings(new EmbeddingsRequest(model: 'some-model', input: 'x'));

        self::assertSame(
            hash('sha256', 'custom-session-7'),
            $this->capturedAffinityRequest(0)->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER),
        );
    }

    public function testCustomNullAndBlankSessionIdsEmitNoAffinityHeader(): void
    {
        foreach ([null, ''] as $polarity) {
            $provider = $this->customAffinityTransport($polarity, [self::JSON_BODY], 'k');

            $provider->complete(new CompleteRequest(model: 'some-model', messages: [new UserMessage('hi')]));

            $request = $this->capturedAffinityRequest(0);
            self::assertSame('', $request->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER));
            self::assertSame('Bearer k', $request->getHeaderLine('Authorization'), 'absent affinity must not disturb auth');
        }
    }

    public function testCustomDiscriminatesTwoSessionIdsAndMergesWithItsClientDefaults(): void
    {
        $first = $this->customAffinityTransport('alpha-session');
        $second = $this->customAffinityTransport('beta-session', [self::JSON_BODY], 'sk-beta');

        $first->complete(new CompleteRequest(model: 'some-model', messages: [new UserMessage('hi')]));
        $second->complete(new CompleteRequest(model: 'some-model', messages: [new UserMessage('hi')]));

        $firstRequest = $this->capturedAffinityRequest(0);
        $secondRequest = $this->capturedAffinityRequest(1);

        self::assertSame(hash('sha256', 'alpha-session'), $firstRequest->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER));
        self::assertSame(hash('sha256', 'beta-session'), $secondRequest->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER));
        self::assertNotSame(
            $firstRequest->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER),
            $secondRequest->getHeaderLine(CustomProvider::SESSION_AFFINITY_HEADER),
        );
        self::assertSame('application/json', $secondRequest->getHeaderLine('Content-Type'));
        self::assertSame('Bearer sk-beta', $secondRequest->getHeaderLine('Authorization'));
    }

    public function testTheCustomOpenAiCompatibleFactoryPassesTheAffinityIdThrough(): void
    {
        $provider = CustomProvider::openAiCompatible(
            name: 'custom',
            baseUrl: self::BASE,
            model: 'some-model',
            sessionAffinityId: 'factory-session',
        );

        self::assertSame(
            [CustomProvider::SESSION_AFFINITY_HEADER => hash('sha256', 'factory-session')],
            $provider->sessionAffinityHeaders(),
        );

        $untouched = CustomProvider::openAiCompatible(name: 'custom', baseUrl: self::BASE, model: 'some-model');

        self::assertSame([], $untouched->sessionAffinityHeaders());
    }

    public function testTheValueBuilderAnswersBothPolaritiesDirectly(): void
    {
        $provider = $this->sglangAffinityTransport(self::RAW_SESSION);

        self::assertSame(hash('sha256', 'any-id'), $provider->sessionAffinityHeaderValue('any-id'));
        self::assertNull($provider->sessionAffinityHeaderValue(null));
        self::assertNull($provider->sessionAffinityHeaderValue(''));
        self::assertSame(
            [SglangProvider::SESSION_AFFINITY_HEADER => hash('sha256', self::RAW_SESSION)],
            $provider->sessionAffinityHeaders(),
        );
    }
}
