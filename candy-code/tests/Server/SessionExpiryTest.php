<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Ratchet\Client\Connector as WsConnector;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\CookieSessions;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;

/**
 * ISS-009: a credential that lapses must end what it opened.
 *
 * An authenticated WebSocket keeps its principal for life — the store can
 * revoke the session behind a ticket and the socket would never notice. The
 * server now remembers which cookie session each socket was opened by (and
 * when that session last authenticated anything) and sweeps idle sessions
 * closed with the 4001 sign-out; logout and rotation close theirs on the
 * spot. These pins drive the sweep on a fake clock.
 */
final class SessionExpiryTest extends TestCase
{
    private const TOKEN = '5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e5e';

    private Server $server;

    private AuthContext $auth;

    private float $now = 1000.0;

    /** @var list<string> */
    private array $lines = [];

    private int $port = 0;

    protected function setUp(): void
    {
        $clock = function (): float {
            return $this->now;
        };
        $this->auth = AuthContext::new(TokenStore::new('/nonexistent')->withOverride(self::TOKEN), $clock);
        $this->server = Server::new(
            ServerConfig::new('/nonexistent')->withPort(0),
            $this->auth,
            null,
            null,
            Loop::get(),
            function (string $line): void {
                $this->lines[] = $line;
            },
            $clock,
        );
        $this->server->start();
        $this->port = (int) $this->server->port();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        $timer = Loop::addTimer(0.05, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    public function testTheSocketAExpiredTicketOpenedIsSignedOutWith4001(): void
    {
        $session = $this->auth->sessions->open();
        $socket = $this->settle($this->connect('/ws?ticket=' . $this->auth->tickets->mint($session)));
        self::assertInstanceOf(WebSocket::class, $socket);
        $bearer = $this->settle($this->connect('/ws', ['Authorization' => 'Bearer ' . self::TOKEN]));
        self::assertInstanceOf(WebSocket::class, $bearer);
        self::assertSame(2, $this->server->connectionCount());

        // Nothing is expired yet.
        self::assertSame(0, $this->server->closeExpiredSessions());

        // Idle past the sliding sign-in window: the session socket goes, the
        // token socket stays — it has no session to lapse.
        $this->now += CookieSessions::LIFETIME_SECONDS + 1.0;
        $closed = new Deferred();
        $socket->on('close', static fn (int $code) => $closed->resolve($code));

        self::assertSame(1, $this->server->closeExpiredSessions());
        self::assertSame(Server::CLOSE_SIGNED_OUT, $this->settle($closed->promise()));
        self::assertSame(1, $this->server->connectionCount(), 'only the session socket left the registry — a bearer socket is bound to no session to expire');
        self::assertContains('sign-in expired: 1 socket closed', $this->lines);

        // The stamp is pruned with the lapse, so a second sweep is silent.
        self::assertSame(0, $this->server->closeExpiredSessions());
        $bearer->close();
    }

    public function testUseSlidesTheWindowTheStoreItselfUses(): void
    {
        $session = $this->auth->sessions->open();
        $first = $this->settle($this->connect('/ws?ticket=' . $this->auth->tickets->mint($session)));
        self::assertInstanceOf(WebSocket::class, $first);

        // Nearly a full lifetime of silence... nothing lapses yet.
        $this->now += CookieSessions::LIFETIME_SECONDS - 1.0;
        self::assertSame(0, $this->server->closeExpiredSessions());

        // One more authenticated use — a second ticket on the same session —
        // slides the store AND re-stamps the sweep, for every socket of it.
        $second = $this->settle($this->connect('/ws?ticket=' . $this->auth->tickets->mint($session)));
        self::assertInstanceOf(WebSocket::class, $second, 'a live session still mints tickets');
        self::assertSame(0, $this->server->closeExpiredSessions(), 'the sockets are young again from the LAST use, not the open');

        // A full lifetime after THAT use, both go together.
        $this->now += CookieSessions::LIFETIME_SECONDS + 1.0;
        self::assertFalse($this->auth->sessions->validate($session), 'the store agrees it lapsed');
        self::assertSame(2, $this->server->closeExpiredSessions());
        self::assertSame(0, $this->server->closeExpiredSessions(), 'and the stamps are pruned with it');
    }

    /**
     * @param array<string, string> $headers
     */
    private function connect(string $path, array $headers = []): PromiseInterface
    {
        $headers += ['Origin' => 'http://127.0.0.1:' . $this->port];

        return (new WsConnector(Loop::get()))('ws://127.0.0.1:' . $this->port . $path, ['candy-code.v1'], $headers);
    }

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
