<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\Http\StaticFiles;
use SugarCraft\Crush\Server\Server;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;

/**
 * `serve token --rotate` reaches a running server (carried into O-3b): the
 * server re-reads the token file and, when it changed, signs every client
 * out at once — cookie sessions, tickets and login codes revoked, every open
 * WebSocket closed with 4001 — instead of honouring the old token until a
 * restart.
 */
final class TokenReloadTest extends TestCase
{
    private ProtocolFixture $fixture;

    private string $stateDir;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        $this->stateDir = $this->fixture->dir . '/state';
        \mkdir($this->stateDir, 0o700);
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testARotatedTokenSignsEveryClientOut(): void
    {
        $tokens = TokenStore::new($this->stateDir);
        $old = $tokens->token();
        $auth = AuthContext::new($tokens);
        $cookie = $auth->sessions->open();
        $ticket = $auth->tickets->mint($cookie);
        $auth->loginCodes->mint();
        $server = Server::new(ServerConfig::new($this->stateDir)->withPort(0), $auth, StaticFiles::new(null), null, Loop::get());
        $server->start();

        try {
            $socket = $this->fixture->await((new WsConnector(Loop::get()))(
                'ws://127.0.0.1:' . $server->port() . '/ws',
                ['sugarcrush.v1'],
                ['Authorization' => 'Bearer ' . $old, 'Origin' => 'http://127.0.0.1:' . $server->port()],
            ));
            self::assertInstanceOf(WebSocket::class, $socket);
            $closed = new Deferred();
            $socket->on('close', static fn (?int $code) => $closed->resolve($code));

            self::assertNull($server->reloadToken(), 'nothing changed yet');

            $new = TokenStore::new($this->stateDir)->rotate();
            self::assertSame(1, $server->reloadToken());

            self::assertSame(Server::CLOSE_CREDENTIALS_ROTATED, $this->fixture->await($closed->promise()));
            self::assertTrue($tokens->matches($new));
            self::assertFalse($tokens->matches($old));
            self::assertFalse($auth->sessions->validate($cookie), 'the browser signed in under the old token is out');
            self::assertNull($auth->tickets->consume($ticket));
            self::assertSame(0, $auth->loginCodes->pending());
        } finally {
            $server->stop();
        }
    }

    public function testAnOverrideTokenNeverReloads(): void
    {
        $auth = AuthContext::new(TokenStore::new($this->stateDir)->withOverride(\str_repeat('c', 40)));
        $cookie = $auth->sessions->open();

        self::assertFalse($auth->reloadToken());
        self::assertTrue($auth->sessions->validate($cookie));
    }
}
