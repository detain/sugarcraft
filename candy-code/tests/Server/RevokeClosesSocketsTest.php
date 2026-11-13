<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * S-O13: a viewer socket authenticates once at upgrade, so revoking its grant
 * must close it — not merely refuse the next upgrade.
 */
final class RevokeClosesSocketsTest extends TestCase
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

    public function testRevokingAGrantClosesItsOpenSocketOnly(): void
    {
        $auth = AuthContext::new(TokenStore::new($this->stateDir));
        $server = Server::new(ServerConfig::new($this->stateDir)->withPort(0), $auth, StaticFiles::new(null), null, Loop::get());
        $server->start();

        try {
            $a = $auth->viewerTokens->grant('a');
            $b = $auth->viewerTokens->grant('b');
            $closedA = new Deferred();
            $closedB = false;
            $sockA = $this->open($server, $a['token']);
            $sockA->on('close', static fn (?int $code) => $closedA->resolve($code));
            $sockB = $this->open($server, $b['token']);
            $sockB->on('close', static function () use (&$closedB): void {
                $closedB = true;
            });
            self::assertSame(2, $server->connectionCount());

            self::assertTrue($auth->viewerTokens->revoke($a['id']));

            self::assertSame(Server::CLOSE_SIGNED_OUT, $this->fixture->await($closedA->promise()));
            self::assertFalse($closedB, 'the other grant keeps its socket');
            $sockB->close();
        } finally {
            $server->stop();
        }
    }

    public function testAnExpiredGrantIsSweptOutOfOpenSockets(): void
    {
        $now = 1_000_000.0;
        $auth = AuthContext::new(TokenStore::new($this->stateDir), static function () use (&$now): float {
            return $now;
        });
        $server = Server::new(ServerConfig::new($this->stateDir)->withPort(0), $auth, StaticFiles::new(null), null, Loop::get());
        $server->start();

        try {
            $grant = $auth->viewerTokens->grant('x', null, 1);
            $closed = new Deferred();
            $this->open($server, $grant['token'])->on('close', static fn (?int $code) => $closed->resolve($code));

            self::assertSame(0, $server->closeDeadViewerSockets(), 'a live grant keeps its socket');

            $now += 2 * 86400.0;
            self::assertSame(1, $server->closeDeadViewerSockets());
            self::assertSame(Server::CLOSE_SIGNED_OUT, $this->fixture->await($closed->promise()));
        } finally {
            $server->stop();
        }
    }

    private function open(Server $server, string $token): WebSocket
    {
        $socket = $this->fixture->await((new WsConnector(Loop::get()))(
            'ws://127.0.0.1:' . $server->port() . '/ws',
            ['candy-code.v1'],
            ['Authorization' => 'Bearer ' . $token, 'Origin' => 'http://127.0.0.1:' . $server->port()],
        ));
        self::assertInstanceOf(WebSocket::class, $socket);

        return $socket;
    }
}
