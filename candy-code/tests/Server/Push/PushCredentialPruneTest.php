<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Push;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\CookieSessions;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\AuthMiddleware;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Push\PushSubscription;
use SugarCraft\Code\Server\Push\PushSubscriptionStore;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * S-O14 (the single-credential half): a token ROTATION prunes whole principal
 * classes, but revoking ONE viewer grant or signing ONE browser out retires a
 * single credential. The push rows that credential subscribed must go with it
 * (and only those), or a client that can no longer sign in keeps being
 * notified and every grant/sign-in leaks a row until the cap evicts it.
 */
final class PushCredentialPruneTest extends TestCase
{
    private const RFC_POINT = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

    private const TOKEN = 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface';

    private ProtocolFixture $fixture;

    private string $stateDir;

    private AuthContext $auth;

    private Server $server;

    private float $now = 1_000_000.0;

    private Browser $browser;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        // The dispatcher's own push store opens the fixture's state dir, so the
        // server under test shares it: subscribe and prune meet in one push.json.
        $this->stateDir = $this->fixture->dir;
        $clock = function (): float {
            return $this->now;
        };
        $this->auth = AuthContext::new(TokenStore::new($this->stateDir)->withOverride(self::TOKEN), $clock);
        $this->server = Server::new(
            ServerConfig::new($this->stateDir)->withPort(0),
            $this->auth,
            StaticFiles::new(null),
            $this->fixture->dispatcher,
            Loop::get(),
            null,
            $clock,
        );
        $this->server->start();
        $this->browser = (new Browser())->withRejectErrorResponse(false)->withTimeout(5.0);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        $this->fixture->tearDown();
    }

    public function testTheStoreDropsOnlyTheRowsOfTheNamedCredential(): void
    {
        $store = PushSubscriptionStore::forStateDir($this->stateDir);
        self::assertTrue($store->put($this->subscription('a'), 'viewer', false, 'grant-a'));
        self::assertTrue($store->put($this->subscription('b'), 'viewer', false, 'grant-b'));
        self::assertTrue($store->put($this->subscription('legacy'), 'viewer', false));

        self::assertSame(0, $store->pruneCredential(''), 'an empty credential matches nothing, including ownerless rows');
        self::assertSame(1, $store->pruneCredential('grant-a'));
        self::assertSame(0, $store->pruneCredential('grant-a'), 'idempotent');

        $left = \array_map(static fn (array $e): string => $e['subscription']->endpoint, \array_values(PushSubscriptionStore::forStateDir($this->stateDir)->entries()));
        \sort($left);
        $want = [$this->subscription('b')->endpoint, $this->subscription('legacy')->endpoint];
        \sort($want);
        self::assertSame($want, $left);
        self::assertStringNotContainsString('grant-b', (string) \file_get_contents($this->stateDir . '/push.json'), 'the credential itself is never written, only its digest');
    }

    public function testRevokingOneViewerGrantPrunesItsRowsEvenWithNoSocketOpen(): void
    {
        $a = $this->auth->viewerTokens->grant('a');
        $b = $this->auth->viewerTokens->grant('b');
        $store = PushSubscriptionStore::forStateDir($this->stateDir);
        self::assertTrue($store->put($this->subscription('a'), 'viewer', false, $a['id']));
        self::assertTrue($store->put($this->subscription('b'), 'viewer', false, $b['id']));
        self::assertTrue($store->put($this->subscription('owner'), 'bearer', false));

        self::assertTrue($this->auth->viewerTokens->revoke($a['id']));

        $left = \array_map(static fn (array $e): string => $e['subscription']->endpoint, \array_values(PushSubscriptionStore::forStateDir($this->stateDir)->entries()));
        \sort($left);
        $want = [$this->subscription('b')->endpoint, $this->subscription('owner')->endpoint];
        \sort($want);
        self::assertSame($want, $left, 'only the revoked grant\'s row went');
    }

    public function testAnExpiredViewerGrantsRowsGoWhenTheSweepClosesItsSocket(): void
    {
        $grant = $this->auth->viewerTokens->grant('x', null, 1);
        $store = PushSubscriptionStore::forStateDir($this->stateDir);
        self::assertTrue($store->put($this->subscription('x'), 'viewer', false, $grant['id']));
        $socket = $this->settle((new WsConnector(Loop::get()))(
            'ws://127.0.0.1:' . $this->server->port() . '/ws',
            ['candy-code.v1'],
            ['Authorization' => 'Bearer ' . $grant['token'], 'Origin' => 'http://127.0.0.1:' . $this->server->port()],
        ));
        self::assertInstanceOf(WebSocket::class, $socket);

        $this->now += 2 * 86400.0;
        self::assertSame(1, $this->server->closeDeadViewerSockets());

        self::assertSame([], PushSubscriptionStore::forStateDir($this->stateDir)->entries());
    }

    public function testACookieLogoutPrunesTheRowsThatSessionSubscribed(): void
    {
        [$cookie, $secret] = $this->signIn();
        $first = $this->openTicketSocket($cookie, $secret);
        $this->hello($first);
        $reply = $this->exchange($first, \json_encode([
            'jsonrpc' => '2.0',
            'id' => 's1',
            'method' => 'push.subscribe',
            'params' => ['endpoint' => $this->subscription('browser')->endpoint, 'keys' => ['p256dh' => self::RFC_POINT, 'auth' => self::RFC_AUTH]],
        ], \JSON_THROW_ON_ERROR));
        self::assertTrue($reply['result']['subscribed'] ?? false, \json_encode($reply));

        // The owner's own subscription (bearer door) must survive the browser's sign-out.
        self::assertTrue(PushSubscriptionStore::forStateDir($this->stateDir)->put($this->subscription('owner'), 'bearer', false));
        self::assertCount(2, PushSubscriptionStore::forStateDir($this->stateDir)->entries());

        $logout = $this->post('/api/logout', [], ['Cookie' => $cookie, AuthMiddleware::SECRET_HEADER => $secret]);
        self::assertSame(200, $logout->getStatusCode());

        $left = \array_values(PushSubscriptionStore::forStateDir($this->stateDir)->entries());
        self::assertCount(1, $left, 'the signed-out browser\'s row went, the owner\'s stayed');
        self::assertSame($this->subscription('owner')->endpoint, $left[0]['subscription']->endpoint);
    }

    private function subscription(string $name): PushSubscription
    {
        return PushSubscription::new('https://updates.push.services.mozilla.com/wpush/v2/' . $name, self::RFC_POINT, self::RFC_AUTH);
    }

    /** @return array{0: string, 1: string} the cookie and the login secret */
    private function signIn(): array
    {
        self::assertSame(1, \preg_match('/#code=([0-9a-f]{32})$/', $this->server->loginUrl(), $m));
        $login = $this->post('/api/login', ['code' => $m[1]]);
        self::assertSame(200, $login->getStatusCode());
        self::assertSame(1, \preg_match('/' . CookieSessions::COOKIE . '_' . $this->server->port() . '=([0-9a-f]+);/', $login->getHeaderLine('Set-Cookie'), $c));

        return [
            CookieSessions::COOKIE . '_' . $this->server->port() . '=' . $c[1],
            (string) (\json_decode((string) $login->getBody(), true)['loginSecret'] ?? ''),
        ];
    }

    private function openTicketSocket(string $cookie, string $secret): WebSocket
    {
        $ticket = $this->post('/api/ticket', [], ['Cookie' => $cookie, AuthMiddleware::SECRET_HEADER => $secret]);
        self::assertSame(200, $ticket->getStatusCode());
        $socket = $this->settle((new WsConnector(Loop::get()))(
            'ws://127.0.0.1:' . $this->server->port() . '/ws?ticket=' . (string) (\json_decode((string) $ticket->getBody(), true)['ticket'] ?? ''),
            ['candy-code.v1'],
            ['Origin' => 'http://127.0.0.1:' . $this->server->port()],
        ));
        self::assertInstanceOf(WebSocket::class, $socket);

        return $socket;
    }

    private function hello(WebSocket $socket): void
    {
        $reply = $this->exchange($socket, \json_encode([
            'jsonrpc' => '2.0',
            'id' => 'h',
            'method' => 'server.hello',
            'params' => ['minProtocol' => 1, 'maxProtocol' => 1, 'client' => ['name' => 'test', 'version' => '0']],
        ], \JSON_THROW_ON_ERROR));
        self::assertArrayHasKey('result', $reply, \json_encode($reply));
    }

    /** @param array<string, string> $headers */
    private function post(string $path, array $body, array $headers = []): ResponseInterface
    {
        $port = (int) $this->server->port();
        $response = $this->settle($this->browser->post(
            'http://127.0.0.1:' . $port . $path,
            $headers + ['Origin' => 'http://127.0.0.1:' . $port, 'Content-Type' => 'application/json'],
            (string) \json_encode($body),
        ));
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
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

    private function settle(PromiseInterface $promise): mixed
    {
        return $this->fixture->await($promise);
    }
}
