<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\CookieSessions;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\Http\StaticFiles;
use SugarCraft\Crush\Server\Server;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Support\ForkedChild;

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

    public function testHealthIsPublicAndSaysOnlyOkAndTheProtocol(): void
    {
        $response = $this->health();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'protocol' => ServerConfig::PROTOCOL_MAJOR], \json_decode((string) $response->getBody(), true));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
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
        self::assertSame(1, \preg_match('/' . CookieSessions::COOKIE . '=([0-9a-f]+);/', $setCookie, $c));
        $cookie = CookieSessions::COOKIE . '=' . $c[1];

        self::assertSame(401, $this->post('/api/login', ['code' => $m[1]])->getStatusCode(), 'the code is spent');

        $ticketResponse = $this->post('/api/ticket', [], ['Cookie' => $cookie]);
        self::assertSame(200, $ticketResponse->getStatusCode());
        $ticket = (string) (\json_decode((string) $ticketResponse->getBody(), true)['ticket'] ?? '');

        $socket = $this->connect('/ws?ticket=' . $ticket);
        self::assertInstanceOf(WebSocket::class, $socket);
        self::assertSame(1, $this->server->connectionCount());
        self::assertSame(['sugarcrush.v1'], $socket->response->getHeader('Sec-WebSocket-Protocol'));

        $reply = $this->exchange($socket, '{"jsonrpc":"2.0","id":"c1","method":"server.hello","params":{}}');
        self::assertSame('c1', $reply['id']);
        self::assertSame(-32601, $reply['error']['code']);
        self::assertSame('not_implemented', $reply['error']['data']['kind']);

        self::assertSame(-32700, $this->exchange($socket, 'not json')['error']['code']);
        self::assertSame(-32600, $this->exchange($socket, '[1,2]')['error']['code']);
        $socket->close();

        $refused = $this->connect('/ws?ticket=' . $ticket);
        self::assertInstanceOf(\Throwable::class, $refused, 'the ticket was single use');
        self::assertStringContainsString('401', $refused->getMessage());
    }

    public function testATokenClientUsesTheBearerHeaderOrTheTokenSubprotocol(): void
    {
        $bearer = $this->connect('/ws', ['Authorization' => 'Bearer ' . self::SERVER_TOKEN]);
        self::assertInstanceOf(WebSocket::class, $bearer);
        $bearer->close();

        $subprotocol = $this->connect('/ws', [], ['sugarcrush.v1', 'sugarcrush.auth.' . self::SERVER_TOKEN]);
        self::assertInstanceOf(WebSocket::class, $subprotocol);
        self::assertSame(['sugarcrush.v1'], $subprotocol->response->getHeader('Sec-WebSocket-Protocol'), 'the token is never echoed');
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
        $socket->send(\str_repeat('x', ServerConfig::MAX_CLIENT_MESSAGE_BYTES + 1));

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
    private function connect(string $path, array $headers = [], array $protocols = ['sugarcrush.v1']): WebSocket|\Throwable
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
