<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\MCP\HttpMcpServer;
use SugarCraft\Code\MCP\OAuthClientRegistration;

/**
 * MCP-4 (audit re-run F-1): evil-redirect pins for the HTTP transport's
 * POST and DELETE egress and for the OAuth issuer funnel.
 *
 * Every request these paths make carries a credential — the configured
 * bearer header on the JSON-RPC legs and the session DELETE, the client
 * secret and refresh token in the OAuth form bodies. Guzzle's default
 * policy follows redirects (up to ten cross-host hops, re-sending the
 * body on 307/308), so an unchecked 3xx answer moved that credential to
 * whatever host the Location header named. The fix sets
 * `allow_redirects => false` on every MCP egress request and refuses a
 * 3xx by name where the response is interpreted (assertSuccess), mirroring
 * the posture the event-stream GET has always kept.
 *
 * The fixture clients ride a real HandlerStack: a raw closure wired as
 * `handler` bypasses Guzzle's default middleware, so only the stack makes
 * the redirect decision live in the test. Pre-fix (option removed), the
 * evil hop is recorded and these pins go red on the request count and the
 * never-sees-evil assertions.
 *
 * The SSE transport's POST-side pin lives with its socket-pair harness in
 * SseMcpServerTest; the GET-side refusal pin predates this round there.
 * Stdio has no HTTP egress at all, and the curl metadata fetch in
 * McpAuthCommand never set CURLOPT_FOLLOWLOCATION.
 */
final class McpRedirectRefusalTest extends TestCase
{
    private const CONFIGURED_URL = 'http://locked.example:9777/mcp';

    private const BEARER_CANARY = 'Bearer mcp-4-http-canary';

    /** @var list<array{0: RequestInterface, 1: array<string, mixed>}> */
    private array $log = [];

    protected function tearDown(): void
    {
        $this->log = [];
        parent::tearDown();
    }

    /**
     * A Guzzle client whose sink records every request that reaches it and
     * answers per the supplied choreography.
     *
     * @param \Closure(RequestInterface, array<string, mixed>): Response $answer
     */
    private function recordingClient(\Closure $answer): Client
    {
        return new Client([
            'handler' => HandlerStack::create(
                function (RequestInterface $request, array $options) use ($answer) {
                    $this->log[] = [$request, $options];

                    return new FulfilledPromise($answer($request, count($this->log)));
                },
            ),
        ]);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function redirectStatuses(): array
    {
        return ['301' => [301], '302' => [302], '307' => [307], '308' => [308]];
    }

    /**
     * Every JSON-RPC POST leg rides send(); the handshake's initialize is the
     * first of them. A 3xx off-origin must be refused by name with the hop
     * never taken, and the ONE logged request must be the bearer-carrying
     * original at the configured host (the pin is vacuous otherwise).
     *
     * @param int $status
     */
    #[DataProvider('redirectStatuses')]
    public function testEveryRedirectStatusIsRefusedOnTheJsonRpcPost(int $status): void
    {
        $server = new HttpMcpServer(
            name: 'http-evil',
            url: self::CONFIGURED_URL,
            headers: ['Authorization' => self::BEARER_CANARY],
            httpClient: $this->recordingClient(
                static fn (RequestInterface $request, int $n): Response => $n === 1
                    // A well-formed tool roster for a FOLLOWED redirect: without
                    // the refusal the start would SUCCEED at the evil host —
                    // the red then names the hop, not a parse artifact.
                    ? new Response($status, ['Location' => 'http://evil.example/stolen'])
                    : new Response(200, [], (string) json_encode([
                        'jsonrpc' => '2.0',
                        'id' => 0,
                        'result' => ['tools' => []],
                    ])),
            ),
        );

        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }

        self::assertNotNull($failure, "HTTP {$status} redirect was accepted");
        self::assertStringContainsString('does not follow redirects', $failure);
        self::assertStringContainsString("HTTP {$status}", $failure);
        self::assertCount(1, $this->log, 'the redirect produced a second, off-origin request');
        self::assertSame('locked.example', $this->log[0][0]->getUri()->getHost());
        self::assertSame(self::BEARER_CANARY, $this->log[0][0]->getHeaderLine('Authorization'));
        self::assertArrayNotHasKey('evil', $this->evilRequests(), 'the bearer hopped off-origin');
    }

    /**
     * The refusal is the transport's own words, not the redirector's body:
     * the message names BOTH what leaked (bearer, request body) and where
     * the law comes from, in the family the GET gate established.
     */
    public function testThePostRefusalNamesTheEgressItRefused(): void
    {
        $server = new HttpMcpServer(
            name: 'http-evil',
            url: self::CONFIGURED_URL,
            headers: ['Authorization' => self::BEARER_CANARY],
            httpClient: $this->recordingClient(
                static fn (RequestInterface $request, int $n): Response => new Response(302, ['Location' => 'http://evil.example/stolen', 'Content-Type' => 'application/json'], '{"error": "look at my fine print"}'),
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the JSON-RPC POST was redirected');

        try {
            $server->start();
        } finally {
            $failure = '';
            // Re-run the message assertions without swallowing: start() wraps
            // the refusal, so check the composed text names the family.
            try {
                $server->start();
            } catch (\RuntimeException $e) {
                $failure = $e->getMessage();
            }
            self::assertStringNotContainsString('fine print', $failure, 'the redirector body must not be relayed');
            self::assertStringContainsString('off the configured origin', $failure);
        }
    }

    /**
     * The best-effort session DELETE carries the bearer too, and swallow-
     * all-means-all does not soften the egress law: the hop is never taken,
     * the failure stays quiet.
     */
    public function testTheSessionDeleteNeverFollowsEither(): void
    {
        $server = new HttpMcpServer(
            name: 'http-evil',
            url: self::CONFIGURED_URL,
            headers: ['Authorization' => self::BEARER_CANARY],
            httpClient: $this->recordingClient(static function (RequestInterface $request, int $n): Response {
                $decoded = json_decode((string) $request->getBody(), true);
                $body = is_array($decoded) ? $decoded : [];

                if ($request->getMethod() === 'DELETE') {
                    return new Response(302, ['Location' => 'http://evil.example/stolen-session']);
                }

                return match ((string) ($body['method'] ?? '')) {
                    'initialize' => new Response(200, ['Mcp-Session-Id' => 'sess-1'], (string) json_encode([
                        'jsonrpc' => '2.0', 'id' => $body['id'] ?? 0, 'result' => ['capabilities' => []],
                    ])),
                    'tools/list' => new Response(200, [], (string) json_encode([
                        'jsonrpc' => '2.0', 'id' => $body['id'] ?? 0, 'result' => ['tools' => []],
                    ])),
                    default => new Response(202),
                };
            }),
        );
        $server->start();
        $this->log = [];

        $server->stop();

        $deletes = array_values(array_filter($this->log, static fn (array $e): bool => $e[0]->getMethod() === 'DELETE'));
        self::assertCount(1, $deletes, 'the session DELETE was not the expected single request');
        self::assertFalse($deletes[0][1]['allow_redirects'], 'the DELETE must carry the refusal option');
        self::assertSame('locked.example', $deletes[0][0]->getUri()->getHost());
        self::assertSame([], $this->evilRequests());
    }

    /**
     * Belt pin: EVERY request that left the transport — handshake legs,
     * notification legs alike — carried allow_redirects => false in its
     * resolved Guzzle options (the per-request gate, not just the client
     * default).
     */
    public function testEveryTransportRequestCarriesTheRefusalOption(): void
    {
        $server = new HttpMcpServer(
            name: 'http-evil',
            url: self::CONFIGURED_URL,
            headers: ['Authorization' => self::BEARER_CANARY],
            httpClient: $this->recordingClient(static function (RequestInterface $request, int $n): Response {
                $decoded = json_decode((string) $request->getBody(), true);
                $body = is_array($decoded) ? $decoded : [];

                return match ((string) ($body['method'] ?? '')) {
                    'initialize' => new Response(200, ['Mcp-Session-Id' => 'sess-1'], (string) json_encode([
                        'jsonrpc' => '2.0', 'id' => $body['id'] ?? 0, 'result' => ['capabilities' => []],
                    ])),
                    'tools/list' => new Response(200, [], (string) json_encode([
                        'jsonrpc' => '2.0', 'id' => $body['id'] ?? 0, 'result' => ['tools' => []],
                    ])),
                    default => new Response(202),
                };
            }),
        );
        $server->start();

        self::assertNotEmpty($this->log, 'the handshake sent no requests at all');
        foreach ($this->log as [$request, $options]) {
            self::assertArrayHasKey('allow_redirects', $options, (string) $request->getUri());
            self::assertFalse($options['allow_redirects'], (string) $request->getUri());
        }
    }

    /**
     * The OAuth issuer funnel carries client secrets and refresh tokens in
     * its form bodies — requestJson forces the refusal ahead of caller
     * options so no leg can re-arm the follow. Pre-fix, the evil answer's
     * token payload would have been ACCEPTED as the refresh response.
     */
    public function testTheTokenLegRefusesToLeaveTheIssuer(): void
    {
        $registration = new OAuthClientRegistration(
            $this->recordingClient(
                static fn (RequestInterface $request, int $n): Response => $n === 1
                    ? new Response(302, ['Location' => 'http://evil.example/steal'])
                    : new Response(200, [], (string) json_encode(['access_token' => 'stolen', 'expires_in' => 3600])),
            ),
            '/tmp/opencode/ccfix-mcp/oauth-never-written.json',
        );

        $failure = null;
        try {
            $registration->refreshToken('https://issuer.test/token', 'cid', 'sec', 'rtk-canary');
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }

        self::assertNotNull($failure, 'the redirect was followed and the evil token accepted');
        self::assertStringContainsString('issuer.test', $failure);
        self::assertCount(1, $this->log, 'the redirect produced a second, off-origin request');
        self::assertSame('issuer.test', $this->log[0][0]->getUri()->getHost());
        self::assertStringContainsString('rtk-canary', (string) $this->log[0][0]->getBody(), 'the pin must ride the token-bearing request');
        self::assertFalse($this->log[0][1]['allow_redirects']);
        self::assertSame([], $this->evilRequests());
    }

    /**
     * Requests that reached the evil host — empty while the law holds.
     *
     * @return list<RequestInterface>
     */
    private function evilRequests(): array
    {
        return array_values(array_map(
            static fn(array $e): RequestInterface => $e[0],
            array_filter(
                $this->log,
                static fn(array $e): bool => str_contains((string) $e[0]->getUri(), 'evil.example'),
            ),
        ));
    }
}
