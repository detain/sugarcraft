<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Http\Message\ServerRequest;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\CookieSessions;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\ApiController;
use SugarCraft\Code\Server\Http\AuthMiddleware;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Support\ForkedChild;

/**
 * The whole transport, end to end, on the shared loop: a real `Server` on an
 * ephemeral loopback port, driven by react/http's `Browser` and a
 * `ratchet/pawl` WebSocket client in the same process (the o0-spikes (a)
 * shape). Covers the browser sign-in chain (login code → cookie → ticket →
 * `/ws`), the token client, every refusal the upgrade can meet, the pending
 * protocol handler's answers, the size limit and a graceful stop.
 */
final class WsUpgradeTest extends TestCase
{
    private const SERVER_TOKEN = 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface';

    private Server $server;

    private AuthContext $auth;

    private int $port;

    private Browser $browser;

    protected function setUp(): void
    {
        $this->auth = AuthContext::new(TokenStore::new('/nonexistent')->withOverride(self::SERVER_TOKEN));
        $this->server = Server::new(
            ServerConfig::new('/nonexistent')->withPort(0),
            $this->auth,
            StaticFiles::new(null),
            null,
            Loop::get(),
        );
        $this->server->start();
        $this->port = (int) $this->server->port();
        $this->browser = (new Browser())->withRejectErrorResponse(false)->withTimeout(5.0);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        // Let the closes the stop queued reach their sockets, then return the
        // shared loop empty-handed to the next test.
        $timer = Loop::addTimer(0.05, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    public function testTheListenerAndItsConnectionsAreRegisteredForForkedChildren(): void
    {
        self::assertGreaterThanOrEqual(1, ForkedChild::registeredServerStreams(), 'the listener is registered');
        $this->health();
        $before = ForkedChild::registeredServerStreams();

        $this->server->stop();
        $this->settle($this->delay(0.05));
        self::assertLessThan($before, ForkedChild::registeredServerStreams(), 'a closed listener is forgotten');
    }

    public function testHealthIsPublicSaysOkProtocolAndNoWebUi(): void
    {
        $response = $this->health();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'protocol' => ServerConfig::PROTOCOL_MAJOR, 'web' => false], \json_decode((string) $response->getBody(), true));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    /**
     * D-2: the health endpoint used to claim nothing about the UI while the
     * router answered `/` with a notice page. Now it reports the truth of the
     * root it serves, re-probed per request, and a root without an index (or
     * no root at all) never reads as an installed UI.
     */
    public function testHealthReportsTheWebUiPresenceOfTheRootItServes(): void
    {
        $dist = \sys_get_temp_dir() . '/cc-d2-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($dist . '/assets', 0777, true));
        $index = $dist . '/index.html';
        \file_put_contents($index, '<!doctype html><title>ui</title>');

        try {
            $credentials = new AuthMiddleware($this->auth, ServerConfig::new('/nonexistent'));
            $api = new ApiController(
                $this->auth,
                ServerConfig::new('/nonexistent'),
                $credentials,
                null,
                null,
                StaticFiles::new($dist),
            );
            $request = new ServerRequest('GET', 'http://127.0.0.1:7420/api/health');

            self::assertSame(
                ['ok' => true, 'protocol' => ServerConfig::PROTOCOL_MAJOR, 'web' => true],
                \json_decode((string) $api($request)->getBody(), true),
                'a root carrying an index answers web: true'
            );

            \unlink($index);
            self::assertSame(
                ['ok' => true, 'protocol' => ServerConfig::PROTOCOL_MAJOR, 'web' => false],
                \json_decode((string) $api($request)->getBody(), true),
                'the same controller re-probes: a vanished index turns the flag false mid-run'
            );

            $bare = new ApiController(
                $this->auth,
                ServerConfig::new('/nonexistent'),
                $credentials,
                null,
                null,
                StaticFiles::new(null),
            );
            self::assertSame(
                ['ok' => true, 'protocol' => ServerConfig::PROTOCOL_MAJOR, 'web' => false],
                \json_decode((string) $bare($request)->getBody(), true),
                'no root at all answers web: false'
            );
        } finally {
            @\unlink($index);
            @\rmdir($dist . '/assets');
            @\rmdir($dist);
        }
    }

    /**
     * D-2 production leg: the flag must ride the SERVER's own wiring, not just
     * a hand-built controller. A `Server` constructed with a populated dist
     * root — the same StaticFiles instance the router serves files from —
     * answers `/api/health` with `web: true` end to end. Drop the one
     * `$this->static` argument in `Server::start()` and the flag silently
     * reverts to the controller default of false; every other health pin here
     * stays green through that cut (double default). This is the pin that
     * keeps the wiring itself load-bearing.
     */
    public function testHealthReportsTheWebUiThroughTheServerWiringItself(): void
    {
        $dist = \sys_get_temp_dir() . '/cc-d2-serve-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($dist, 0777, true));
        \file_put_contents($dist . '/index.html', '<!doctype html><title>ui</title>');

        $server = null;
        try {
            $server = Server::new(
                ServerConfig::new('/nonexistent')->withPort(0),
                $this->auth,
                StaticFiles::new($dist),
                null,
                Loop::get(),
            );
            $server->start();
            $response = $this->settle($this->browser->get('http://127.0.0.1:' . (int) $server->port() . '/api/health'));
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(
                ['ok' => true, 'protocol' => ServerConfig::PROTOCOL_MAJOR, 'web' => true],
                \json_decode((string) $response->getBody(), true),
                'the live server answers web: true for the UI its own file server serves'
            );
        } finally {
            if ($server !== null) {
                $server->stop();
            }
            @\unlink($dist . '/index.html');
            @\rmdir($dist);
        }
    }

    public function testABrowserSignsInWithTheLoginCodeAndOpensTheSocketWithATicket(): void
    {
        $url = $this->server->loginUrl();
        self::assertSame(1, \preg_match('/#code=([0-9a-f]{32})$/', $url, $m), 'the code rides in the fragment');

        $login = $this->post('/api/login', ['code' => $m[1]]);
        self::assertSame(200, $login->getStatusCode());
        $setCookie = $login->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Strict', $setCookie);
        self::assertStringNotContainsString('Secure', $setCookie, 'no TLS, no trusted proxy: no Secure flag');
        // ISS-007: the cookie's NAME is scoped to the port it was minted on,
        // and its AUTHORITY is the loginSecret beside it in the body — the
        // page keeps that in localStorage and the server demands it back.
        self::assertSame(1, \preg_match('/' . CookieSessions::COOKIE . '_' . $this->port . '=([0-9a-f]+);/', $setCookie, $c), 'the cookie name carries the port');
        $cookie = CookieSessions::COOKIE . '_' . $this->port . '=' . $c[1];
        $secret = (string) (\json_decode((string) $login->getBody(), true)['loginSecret'] ?? '');
        self::assertSame(1, \preg_match('/^[0-9a-f]{64}$/', $secret), 'the login secret is 64 hex');

        self::assertSame(401, $this->post('/api/login', ['code' => $m[1]])->getStatusCode(), 'the code is spent');

        self::assertSame(401, $this->post('/api/ticket', [], ['Cookie' => $cookie])->getStatusCode(), 'the cookie alone no longer mints tickets');
        self::assertSame(401, $this->post('/api/ticket', [], ['Cookie' => $cookie, AuthMiddleware::SECRET_HEADER => str_repeat('0', 64)])->getStatusCode(), 'a wrong secret does not either');
        $ticketResponse = $this->post('/api/ticket', [], ['Cookie' => $cookie, AuthMiddleware::SECRET_HEADER => $secret]);
        self::assertSame(200, $ticketResponse->getStatusCode());
        $ticket = (string) (\json_decode((string) $ticketResponse->getBody(), true)['ticket'] ?? '');

        $socket = $this->connect('/ws?ticket=' . $ticket);
        self::assertInstanceOf(WebSocket::class, $socket);
        self::assertSame(1, $this->server->connectionCount());
        self::assertSame(['candy-code.v1'], $socket->response->getHeader('Sec-WebSocket-Protocol'));

        $reply = $this->exchange($socket, '{"jsonrpc":"2.0","id":"c1","method":"server.hello","params":{}}');
        self::assertSame('c1', $reply['id']);
        self::assertSame(-32601, $reply['error']['code']);
        self::assertSame('not_implemented', $reply['error']['data']['kind']);

        self::assertSame(-32700, $this->exchange($socket, 'not json')['error']['code']);
        self::assertSame(-32600, $this->exchange($socket, '[1,2]')['error']['code']);

        // ISS-009: signing out ends the socket the session opened, with a
        // close code that says why.
        $closed = new Deferred();
        $socket->on('close', static fn (int $code): mixed => $closed->resolve($code));
        $logout = $this->post('/api/logout', [], ['Cookie' => $cookie, AuthMiddleware::SECRET_HEADER => $secret]);
        self::assertSame(200, $logout->getStatusCode());
        self::assertSame(Server::CLOSE_SIGNED_OUT, $this->settle($closed->promise()));
        self::assertSame(0, $this->server->connectionCount(), 'the signed-out socket left the registry');

        $refused = $this->connect('/ws?ticket=' . $ticket);
        self::assertInstanceOf(\Throwable::class, $refused, 'the ticket was single use');
        self::assertStringContainsString('401', $refused->getMessage());
    }

    public function testATokenClientUsesTheBearerHeaderOrTheTokenSubprotocol(): void
    {
        $bearer = $this->connect('/ws', ['Authorization' => 'Bearer ' . self::SERVER_TOKEN]);
        self::assertInstanceOf(WebSocket::class, $bearer);
        $bearer->close();

        $subprotocol = $this->connect('/ws', [], ['candy-code.v1', 'candy-code.auth.' . self::SERVER_TOKEN]);
        self::assertInstanceOf(WebSocket::class, $subprotocol);
        self::assertSame(['candy-code.v1'], $subprotocol->response->getHeader('Sec-WebSocket-Protocol'), 'the token is never echoed');
        $subprotocol->close();

        $login = $this->post('/api/login', ['token' => self::SERVER_TOKEN]);
        self::assertSame(200, $login->getStatusCode(), 'the token also signs a browser in');
        self::assertSame(400, $this->post('/api/ticket', [], ['Authorization' => 'Bearer ' . self::SERVER_TOKEN])->getStatusCode(), 'tickets are for cookie sessions');
    }

    public function testEveryUpgradeRefusal(): void
    {
        $bearer = ['Authorization' => 'Bearer ' . self::SERVER_TOKEN];

        self::assertStringContainsString('401', $this->failure($this->connect('/ws')));
        self::assertStringContainsString('401', $this->failure($this->connect('/ws?ticket=deadbeef')));
        self::assertStringContainsString('401', $this->failure($this->connect('/ws', ['Cookie' => CookieSessions::COOKIE . '=' . $this->auth->sessions->open()])), 'a cookie alone never opens the socket');
        self::assertStringContainsString('403', $this->failure($this->connect('/ws', $bearer + ['Origin' => 'https://evil.example'])));
        self::assertStringContainsString('421', $this->failure($this->connect('/ws', $bearer + ['Host' => 'evil.example:' . $this->port])));
        self::assertStringContainsString('426', $this->failure($this->connect('/ws', $bearer, ['chat'])));

        $plain = $this->settle($this->browser->get($this->url('/ws'), $bearer));
        self::assertInstanceOf(ResponseInterface::class, $plain);
        self::assertSame(426, $plain->getStatusCode(), 'a plain GET of /ws is not an upgrade');
        self::assertSame(0, $this->server->connectionCount());
    }

    public function testAnOversizedMessageClosesTheSocket1009(): void
    {
        $socket = $this->connect('/ws', ['Authorization' => 'Bearer ' . self::SERVER_TOKEN]);
        self::assertInstanceOf(WebSocket::class, $socket);

        $closed = new Deferred();
        $socket->on('close', static fn (int $code) => $closed->resolve($code));
        $socket->send(\str_repeat('x', ServerConfig::MAX_CLIENT_FRAME_BYTES + 1));

        self::assertSame(1009, $this->settle($closed->promise()));
    }

    public function testStoppingClosesEverySocketGoingAwayAndRefusesNewClients(): void
    {
        $socket = $this->connect('/ws', ['Authorization' => 'Bearer ' . self::SERVER_TOKEN]);
        self::assertInstanceOf(WebSocket::class, $socket);
        $closed = new Deferred();
        $socket->on('close', static fn (int $code) => $closed->resolve($code));

        $this->server->stop();

        self::assertSame(1001, $this->settle($closed->promise()));
        self::assertSame(0, $this->server->connectionCount());
        self::assertInstanceOf(\Throwable::class, $this->settle($this->browser->get($this->url('/api/health'))), 'the port no longer answers');
    }

    public function testTheUpgradeSignatureClosesItsParameterListOnItsOwnLines(): void
    {
        // CWPD-17: the constructor parameter list grew a glued `…) {` leg
        // (the `$log` addition rode the same line as the closing brace) —
        // PSR-12 violation, and the exact shape a careless grep for
        // parameters misses. One line per parameter, brace on its own.
        $src = (string) \file_get_contents(__DIR__ . '/../../src/Server/Ws/Upgrade.php');
        $signatureStart = \strpos($src, 'public function __construct(');
        self::assertNotFalse($signatureStart, 'fixture: the constructor is still declared');
        $signatureEnd = \strpos($src, '{', $signatureStart);
        self::assertNotFalse($signatureEnd, 'fixture: the constructor still has a body');
        $signature = \substr($src, $signatureStart, $signatureEnd - $signatureStart);
        foreach (\explode("\n", $signature) as $line) {
            self::assertFalse(
                \str_contains($line, ',') && \str_contains($line, ')'),
                'a parameter line must not carry the closing paren: ' . \trim($line),
            );
        }
        self::assertStringContainsString("private readonly ?\\Closure \$log = null,\n    ) {\n", $src);
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    private function health(): ResponseInterface
    {
        $response = $this->settle($this->browser->get($this->url('/api/health')));
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function post(string $path, array $body, array $headers = []): ResponseInterface
    {
        $response = $this->settle($this->browser->post(
            $this->url($path),
            $headers + ['Origin' => 'http://127.0.0.1:' . $this->port, 'Content-Type' => 'application/json'],
            (string) \json_encode($body),
        ));
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    /**
     * The WebSocket, or the Throwable the handshake failed with.
     *
     * @param array<string, string> $headers
     * @param list<string>          $protocols
     */
    private function connect(string $path, array $headers = [], array $protocols = ['candy-code.v1']): WebSocket|\Throwable
    {
        $headers += ['Origin' => 'http://127.0.0.1:' . $this->port];

        return $this->settle((new WsConnector(Loop::get()))('ws://127.0.0.1:' . $this->port . $path, $protocols, $headers));
    }

    /** @return array<string, mixed> */
    private function exchange(WebSocket $socket, string $text): array
    {
        $reply = new Deferred();
        $listener = static fn (MessageInterface $message) => $reply->resolve((string) $message);
        $socket->on('message', $listener);
        $socket->send($text);
        $raw = $this->settle($reply->promise());
        $socket->removeListener('message', $listener);
        self::assertIsString($raw);

        return (array) \json_decode($raw, true);
    }

    private function failure(WebSocket|\Throwable $result): string
    {
        if ($result instanceof WebSocket) {
            $result->close();
            self::fail('the upgrade was accepted');
        }

        return $result->getMessage();
    }

    private function delay(float $seconds): PromiseInterface
    {
        $deferred = new Deferred();
        Loop::addTimer($seconds, static fn () => $deferred->resolve(null));

        return $deferred->promise();
    }

    /**
     * Run the shared loop until $promise settles: its value, or the Throwable
     * it rejected with. A bounded wait — a hang fails the test, never the run.
     */
    private function settle(PromiseInterface $promise, float $timeout = 5.0): mixed
    {
        $done = false;
        $result = null;
        $promise->then(
            static function (mixed $value) use (&$done, &$result): void {
                $done = true;
                $result = $value;
                Loop::stop();
            },
            static function (\Throwable $error) use (&$done, &$result): void {
                $done = true;
                $result = $error;
                Loop::stop();
            },
        );

        if (!$done) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }
        self::assertTrue($done, 'the transport did not answer within ' . $timeout . ' s');

        return $result;
    }
}
