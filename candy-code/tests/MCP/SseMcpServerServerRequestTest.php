<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\MCP\McpServerRequests;
use SugarCraft\Code\MCP\SseMcpServer;

/**
 * C-MCP-3.c: the legacy SSE stream carries an answer desk.
 *
 * Before this step, a server-initiated request riding the GET stream — a
 * `ping`, an `elicitation/create` — was skipped on its way past the reply
 * matcher and never answered; the desk only existed on stdio's pipe. These
 * pins run the transport's REAL wire machinery over socket-pair fixtures
 * (same harness shape as {@see SseMcpServerTest}: the connector seam swaps
 * only the connect; framing, origin law and every wait run for real) and
 * assert the legs of the new contract: a REQUEST ON THE STREAM IS ANSWERED
 * BY A POST on the origin-locked endpoint, `notifications/progress` rides
 * the per-call sink, the traffic is still answered when NOTHING is in flight
 * ({@see SseMcpServer::pumpStream()} — the idle pump the client drives
 * between turns), and a transport WITHOUT a desk keeps its old pass-over
 * walk byte-for-byte.
 */
final class SseMcpServerServerRequestTest extends TestCase
{
    private const URL = 'http://desk.example:8791/sse';

    /** @var list<resource> fixture-side stream ends, in session order */
    private array $sessions = [];

    /** @var list<array{0:RequestInterface,1:array<string,mixed>}> every POST the transport made */
    private array $postLog = [];

    /** @var list<(\Closure(RequestInterface): void)|null> one per queued POST */
    private array $postHooks = [];

    /** @var list<array{0:string,1:?array<string,mixed>,2:bool}> frames the spawn desk answered */
    private array $deskCalls = [];

    protected function tearDown(): void
    {
        foreach ($this->sessions as $socket) {
            if (\is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sessions = [];
        $this->postLog = [];
        $this->postHooks = [];
        $this->deskCalls = [];
    }

    /**
     * A `ping` riding the reply stream ahead of the answer is answered by a
     * POST of its JSON-RPC response to the endpoint — while the call that
     * tripped it is still waiting for its reply on the same stream.
     */
    public function testARequestOnTheStreamIsAnsweredByAnEndpointPostMidCall(): void
    {
        $server = $this->startedServer($this->spawnDesk());

        $this->postHooks = [
            function (RequestInterface $request): void {
                $this->feedStream($this->frame([
                    'jsonrpc' => '2.0', 'id' => 'ping-1', 'method' => 'ping', 'params' => new \stdClass(),
                ]));
                $this->feedStream($this->replyTo($request, ['content' => [['type' => 'text', 'text' => 'pong']]]));
            },
        ];

        $result = $server->callTool('echo', ['message' => 'hi']);
        $this->assertSame('pong', $result['content'][0]['text']);

        $answers = $this->answerPosts();
        self::assertCount(1, $answers, 'the stream-carried request was not answered');
        self::assertSame('ping-1', $answers[0]['id']);
        self::assertArrayHasKey('result', $answers[0], 'a ping must be answered, not refused');
        self::assertSame([['ping', [], false]], $this->deskCalls, 'the desk saw exactly the ping request');

        // The answer rode the origin-locked endpoint the stream published.
        $answerRequest = null;
        foreach ($this->postLog as [$request]) {
            $decoded = json_decode((string) $request->getBody(), true);
            if (\is_array($decoded) && ($decoded['id'] ?? null) === 'ping-1') {
                $answerRequest = $request;
            }
        }
        self::assertNotNull($answerRequest);
        self::assertSame('POST', $answerRequest->getMethod());
        self::assertStringContainsString('desk.example:8791', (string) $answerRequest->getUri());
        self::assertStringContainsString('/messages', (string) $answerRequest->getUri());
    }

    /**
     * With a per-call desk (the seam {@see McpClient::callTool()} installs
     * when the turn has an approver), an `elicitation/create` inside the
     * reply stream reaches THAT answerer and a `notifications/progress`
     * ahead of it reaches the call's wait sink — the spawn-time desk is not
     * consulted while the per-call desk is installed.
     */
    public function testElicitationInsideAReplyStreamReachesThePerCallAnswerer(): void
    {
        $server = $this->startedServer($this->spawnDesk());

        $this->postHooks = [
            function (RequestInterface $request): void {
                $this->feedStream($this->frame([
                    'jsonrpc' => '2.0',
                    'id' => 'elicit-1',
                    'method' => 'elicitation/create',
                    'params' => [
                        'message' => 'ship it?',
                        'requestedSchema' => [
                            'type' => 'object',
                            'properties' => ['ack' => ['type' => 'string', 'description' => 'confirm']],
                            'required' => ['ack'],
                        ],
                    ],
                ]));
                $this->feedStream($this->frame([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => ['progressToken' => 1, 'progress' => 50, 'total' => 100, 'message' => 'halfway'],
                ]));
                $this->feedStream($this->replyTo($request, ['content' => []]));
            },
        ];

        $desk = new McpServerRequests(
            \sys_get_temp_dir(),
            static fn (array $questions): array => ['ack' => 'yes'],
        );
        $waited = [];
        $result = $server->callToolAnsweringRequests(
            'echo',
            ['message' => 'go'],
            static function (array $params) use (&$waited): void {
                $waited[] = $params;
            },
            $desk,
        );

        self::assertSame([], $result['content'], 'the call itself must complete normally');
        self::assertCount(1, $waited, 'progress must ride the per-call sink');
        self::assertSame(50, $waited[0]['progress']);

        $answers = $this->answerPosts();
        self::assertCount(1, $answers);
        self::assertSame('elicit-1', $answers[0]['id']);
        self::assertSame(
            ['action' => 'accept', 'content' => ['ack' => 'yes']],
            $answers[0]['result'],
            'the per-call desk (approver) must answer the elicitation',
        );
        self::assertSame([], $this->deskCalls, 'the per-call desk replaces the spawn desk for this exchange');
    }

    /**
     * Between calls the idle pump does the answering: a ping pushed after the
     * last reply was delivered is answered when pumpStream() runs — which is
     * what {@see McpClient::pumpStderr()} now calls for the desk-bearing
     * GET-stream arms, so a server that pings an idle session is answered,
     * not waited out.
     */
    public function testTheIdlePumpAnswersTrafficThatArrivedBetweenCalls(): void
    {
        $server = $this->startedServer($this->spawnDesk());

        $this->feedStream($this->frame([
            'jsonrpc' => '2.0', 'id' => 'ping-2', 'method' => 'ping',
        ]));

        $server->pumpStream();

        $answers = $this->answerPosts();
        self::assertCount(1, $answers, 'the idle pump must answer the between-call ping');
        self::assertSame('ping-2', $answers[0]['id']);
    }

    /**
     * The desk advertisement the C-MCP-3.c handshake carries: the two
     * capabilities whose requests this transport now answers — no more, no
     * less (sampling is refused by the desk itself).
     */
    public function testTheHandshakeAdvertisesExactlyTheDeskCapabilities(): void
    {
        $server = $this->startedServer($this->spawnDesk());
        $server->stop();

        $init = null;
        foreach ($this->postLog as [$request]) {
            $decoded = json_decode((string) $request->getBody(), true);
            if (\is_array($decoded) && ($decoded['method'] ?? '') === 'initialize') {
                $init = $decoded;
            }
        }
        self::assertNotNull($init, 'the handshake POSTed no initialize');
        self::assertSame(
            ['roots' => ['listChanged' => false], 'elicitation' => []],
            $init['params']['capabilities'],
        );
    }

    /**
     * Without a desk the walk keeps its pre-step shape: the request frame is
     * passed over and NO answer POST is made. The spawn-time desk is the
     * factory's decision, never the transport's.
     */
    public function testATransportWithoutADeskStillPassesServerRequestsOver(): void
    {
        $server = $this->startedServer(null);

        $this->postHooks = [
            function (RequestInterface $request): void {
                $this->feedStream($this->frame([
                    'jsonrpc' => '2.0', 'id' => 'ping-3', 'method' => 'ping',
                ]));
                $this->feedStream($this->replyTo($request, ['content' => []]));
            },
        ];

        $result = $server->callTool('echo', []);

        self::assertSame([], $result['content']);
        $counts = [];
        foreach ($this->postLog as [$request]) {
            $decoded = json_decode((string) $request->getBody(), true);
            $method = \is_array($decoded) ? ($decoded['method'] ?? 'ANSWER') : '?';
            $counts[$method] = ($counts[$method] ?? 0) + 1;
        }
        self::assertSame(
            ['initialize' => 1, 'notifications/initialized' => 1, 'tools/list' => 1, 'tools/call' => 1],
            $counts,
            'a desk-less transport must invent nothing, not even a refusal',
        );
    }

    // ------------------------------------------------------------------
    // Harness (socket-pair fixture in the SseMcpServerTest shape)
    // ------------------------------------------------------------------

    /** @param (\Closure(string, ?array<string,mixed>, bool): ?array)|null $desk */
    private function startedServer(?\Closure $desk): SseMcpServer
    {
        $server = new SseMcpServer(
            name: 'desk-demo',
            url: self::URL,
            headers: [],
            httpClient: new Client(['handler' => HandlerStack::create($this->actor())]),
            toolTimeoutSeconds: 2.0,
            connector: function (string $scheme, string $host, int $port, float $timeoutSeconds): mixed {
                unset($scheme, $host, $port, $timeoutSeconds);

                return $this->openSession();
            },
            onServerMessage: $desk,
        );

        // The lifecycle's own legs, answered like any legacy server would.
        $this->postHooks = [
            function (RequestInterface $request): void {
                $this->feedStream($this->replyTo($request, ['protocolVersion' => '2024-11-05']));
            },
            null,
            function (RequestInterface $request): void {
                $this->feedStream($this->replyTo($request, ['tools' => [['name' => 'echo']]]));
            },
        ];
        $server->start();
        self::assertTrue($server->isUp());
        $this->postHooks = [];

        return $server;
    }

    /** The spawn-time desk: records every frame, answers every request empty. */
    private function spawnDesk(): \Closure
    {
        return function (string $method, ?array $params, bool $isNotification) {
            $this->deskCalls[] = [$method, $params, $isNotification];

            return $isNotification ? null : ['result' => new \stdClass()];
        };
    }

    /**
     * One fixture session: unix pair, SSE head and the `endpoint` event
     * buffered eagerly (the client always finds them), client end handed to
     * the transport. Later frames arrive via {@see feedStream()}.
     *
     * @return resource the client end
     */
    private function openSession(): mixed
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_SOCK_STREAM);
        self::assertIsArray($pair);
        [$client, $fixture] = $pair;
        stream_set_read_buffer($client, 0);
        $this->sessions[] = $fixture;

        fwrite($fixture, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n");
        $this->feedChunked($fixture, "event: endpoint\ndata: /messages?sid=desk1\n\n");

        return $client;
    }

    /**
     * The legacy-server actor: every POST is logged and answered 202, and the
     * queued hook for that POST writes the stream traffic — exactly how real
     * servers pair delivery with answer.
     */
    private function actor(): \Closure
    {
        return function (RequestInterface $request, array $options) {
            $this->postLog[] = [$request, $options];
            $hook = array_shift($this->postHooks);
            if ($hook !== null) {
                $hook($request);
            }

            return new FulfilledPromise(new Response(202));
        };
    }

    /** @param resource $fixture */
    private function feedChunked($fixture, string $text): void
    {
        fwrite($fixture, dechex(strlen($text)) . "\r\n" . $text . "\r\n");
    }

    /** @param array<string,mixed> $message */
    private function frame(array $message): string
    {
        $json = (string) json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return 'data: ' . $json . "\n\n";
    }

    /** Push one SSE frame onto the newest session's stream. */
    private function feedStream(string $sseFrame): void
    {
        $fixture = $this->sessions[count($this->sessions) - 1];
        self::assertTrue(\is_resource($fixture), 'the fixture stream died');
        $this->feedChunked($fixture, $sseFrame);
    }

    /** The JSON-RPC reply matching the request POST that triggered the hook. */
    /** @param array<string,mixed> $result */
    private function replyTo(RequestInterface $request, array $result): string
    {
        $body = json_decode((string) $request->getBody(), true);

        return $this->frame([
            'jsonrpc' => '2.0',
            'id' => \is_array($body) ? ($body['id'] ?? 0) : 0,
            'result' => $result,
        ]);
    }

    /**
     * Every logged POST that carries a JSON-RPC `id` with NO `method` is an
     * answer to a server-initiated request — decoded bodies, wire order.
     *
     * @return list<array<string,mixed>>
     */
    private function answerPosts(): array
    {
        $answers = [];
        foreach ($this->postLog as [$request]) {
            $body = json_decode((string) $request->getBody(), true);
            if (\is_array($body) && isset($body['id']) && !isset($body['method'])) {
                $answers[] = $body;
            }
        }

        return $answers;
    }
}
