<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\UnixConnector;
use SugarCraft\Code\Cli\Serve;
use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

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
        $forgotten = [];
        $server->onCredentialsRevoked(static function (?string $id) use (&$forgotten): void {
            $forgotten[] = $id;
        });
        $server->start();

        try {
            $socket = $this->fixture->await((new WsConnector(Loop::get()))(
                'ws://127.0.0.1:' . $server->port() . '/ws',
                ['candy-code.v1'],
                ['Authorization' => 'Bearer ' . $old, 'Origin' => 'http://127.0.0.1:' . $server->port()],
            ));
            self::assertInstanceOf(WebSocket::class, $socket);
            $closed = new Deferred();
            $socket->on('close', static fn (?int $code) => $closed->resolve($code));

            self::assertNull($server->reloadToken(), 'nothing changed yet');

            $new = TokenStore::new($this->stateDir)->rotate();
            self::assertSame(1, $server->reloadToken());
            self::assertSame([null], $forgotten, 'a rotation tells the idempotency seam every credential died');

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

    /**
     * Control-socket authentication precedes every verb: a peer with the wrong
     * token is refused with ZERO side effect (token bytes, live connection and
     * rotation state untouched), and the right token still reaches the verb.
     */
    public function testControlSocketRefusesBeforeMutating(): void
    {
        $tokens = TokenStore::new($this->stateDir);
        $old = $tokens->token();
        $auth = AuthContext::new($tokens);
        $server = Server::new(ServerConfig::new($this->stateDir)->withPort(0), $auth, StaticFiles::new(null), null, Loop::get());
        $server->start();
        $logged = [];
        $listener = (new \ReflectionMethod(Serve::class, 'openControlSocket'))->invoke(
            null,
            StateDir::open($this->stateDir),
            $server,
            $tokens,
            static function (string $line) use (&$logged): void {
                $logged[] = $line;
            },
        );
        self::assertNotNull($listener, \implode(';', $logged));

        try {
            $socket = $this->fixture->await((new WsConnector(Loop::get()))(
                'ws://127.0.0.1:' . $server->port() . '/ws',
                ['candy-code.v1'],
                ['Authorization' => 'Bearer ' . $old, 'Origin' => 'http://127.0.0.1:' . $server->port()],
            ));
            $closed = false;
            $socket->on('close', static function () use (&$closed): void {
                $closed = true;
            });
            // A rotation is pending on disk: an unauthenticated peer must not be
            // able to make the server pick it up.
            $new = TokenStore::new($this->stateDir)->rotate();

            foreach (['reload-token', 'login-url'] as $verb) {
                $answer = $this->controlRequest($verb . ' ' . \str_repeat('x', 40));
                self::assertSame(['ok' => false, 'error' => 'unauthorized'], $answer, $verb);
            }
            self::assertSame(['ok' => false, 'error' => 'unauthorized'], $this->controlRequest('reload-token'));
            $this->fixture->await((new \React\Promise\Promise(static function (callable $resolve): void {
                Loop::get()->addTimer(0.05, static fn () => $resolve(true));
            })));

            self::assertFalse($closed, 'a refused verb closed no WebSocket');
            self::assertTrue($tokens->matches($old), 'the server still holds the old token');
            self::assertFalse($tokens->matches($new), 'the refused verb did not reload the rotated token');

            $ok = $this->controlRequest('reload-token ' . $old);
            self::assertTrue($ok['ok']);
            self::assertTrue($ok['rotated']);
            self::assertSame(1, $ok['closed']);
        } finally {
            $listener->close();
            $server->stop();
        }
    }

    /** @return array<string, mixed> */
    private function controlRequest(string $line): array
    {
        $connection = $this->fixture->await((new UnixConnector(Loop::get()))->connect('unix://' . $this->stateDir . '/control.sock'));
        self::assertInstanceOf(ConnectionInterface::class, $connection);
        $reply = new Deferred();
        $buffer = '';
        $connection->on('data', static function (string $chunk) use (&$buffer): void {
            $buffer .= $chunk;
        });
        $connection->on('close', static function () use (&$buffer, $reply): void {
            $reply->resolve($buffer);
        });
        $connection->write($line . "\n");

        return \json_decode((string) $this->fixture->await($reply->promise()), true);
    }
}
