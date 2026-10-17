<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\CookieSessions;
use SugarCraft\Code\Server\Auth\RateLimiter;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\AuthMiddleware;
use SugarCraft\Code\Server\Http\HostAndOriginGuard;
use SugarCraft\Code\Server\ServerConfig;

/**
 * ISS-008, in isolation: the lockout is a brake on GUESSERS, never a weapon
 * against the owner.
 *
 * Every loopback client shares the address `127.0.0.1`, and a cross-site page
 * used to be able to burn the budget for free — an `<img>` on `/ws` is a GET
 * with no upgrade, and its junk ticket struck like a real failed sign-in. The
 * rewritten middleware fixes both: a matching bearer or subprotocol token is
 * authenticated BEFORE the limiter is consulted, a non-upgrade `/ws` is
 * answered 426 before either, a ticket the origin gate never admitted never
 * reaches the store, and the strike that trips a lockout is one log line.
 */
final class LockoutOrderingTest extends TestCase
{
    private const TOKEN = '9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c9c';

    private const UPGRADE = ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Version' => '13', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ=='];

    private AuthContext $auth;

    private float $now = 500.0;

    private AuthMiddleware $middleware;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->auth = AuthContext::new(TokenStore::new('/nonexistent')->withOverride(self::TOKEN), fn (): float => $this->now);
        $this->middleware = new AuthMiddleware(
            $this->auth,
            ServerConfig::new('/nonexistent'),
            function (string $line): void {
                $this->lines[] = $line;
            },
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $target, array $headers = [], string $origin = HostAndOriginGuard::ORIGIN_SAME): ResponseInterface
    {
        $request = (new ServerRequest($method, 'http://127.0.0.1:7420' . $target, $headers + ['Host' => '127.0.0.1:7420'], '', '1.1', ['REMOTE_ADDR' => '127.0.0.1']))
            ->withAttribute(HostAndOriginGuard::ATTR_ORIGIN, $origin);
        $result = ($this->middleware)($request, static fn (ServerRequestInterface $r): ResponseInterface => new Response(204));
        self::assertInstanceOf(ResponseInterface::class, $result);

        return $result;
    }

    public function testTheOwnerTokenPassesEvenWhileTheAddressIsLockedOut(): void
    {
        for ($i = 0; $i < RateLimiter::MAX_FAILURES; ++$i) {
            $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . str_repeat('a', 64)]);
        }
        self::assertGreaterThan(0, $this->auth->limiter->retryAfter('127.0.0.1'));

        self::assertSame(204, $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . self::TOKEN])->getStatusCode(), 'bearer');
        self::assertSame(204, $this->call('GET', '/ws', self::UPGRADE + ['Sec-WebSocket-Protocol' => 'candy-code.v1, candy-code.auth.' . self::TOKEN])->getStatusCode(), 'subprotocol');

        $waited = $this->call('POST', '/api/ticket', ['Cookie' => CookieSessions::COOKIE . '_7420=' . $this->auth->sessions->open()]);
        self::assertSame(429, $waited->getStatusCode(), 'a cookie principal still waits the lockout out');
        self::assertSame((string) RateLimiter::LOCKOUT_SECONDS, $waited->getHeaderLine('Retry-After'));

        // A WRONG token during the lockout is turned away at the gate too —
        // it never gets close enough to the store to pass or to strike again.
        self::assertSame(429, $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . str_repeat('b', 64)])->getStatusCode());
    }

    public function testNonUpgradeWsTrafficCannotBurnTheBudget(): void
    {
        for ($i = 0; $i < 3 * RateLimiter::MAX_FAILURES; ++$i) {
            self::assertSame(426, $this->call('GET', '/ws?ticket=' . 'x' . $i)->getStatusCode());
        }
        self::assertSame(0, $this->auth->limiter->retryAfter('127.0.0.1'), 'three lifetimes of <img> barrage, zero strikes');
    }

    public function testAJunkTicketOnARealUpgradeWithoutAMatchingOriginCannotBurnTheBudgetEither(): void
    {
        for ($i = 0; $i < 2 * RateLimiter::MAX_FAILURES; ++$i) {
            self::assertSame(401, $this->call('GET', '/ws?ticket=' . 'y' . $i, self::UPGRADE, HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());
            self::assertSame(401, $this->call('GET', '/ws?ticket=' . 'z' . $i, self::UPGRADE, HostAndOriginGuard::ORIGIN_UNCHECKED)->getStatusCode());
        }
        self::assertSame(0, $this->auth->limiter->retryAfter('127.0.0.1'), 'the strike is reserved for credentials that reached the store');
    }

    public function testTheTripIsOneLogLineAndNothingElseIs(): void
    {
        for ($i = 0; $i < RateLimiter::MAX_FAILURES + 3; ++$i) {
            $this->call('POST', '/api/ticket', ['Cookie' => CookieSessions::COOKIE . '_7420=' . bin2hex(random_bytes(16))]);
        }
        self::assertSame(
            ['locked out 127.0.0.1 for ' . RateLimiter::LOCKOUT_SECONDS . ' s after ' . RateLimiter::MAX_FAILURES . ' failed sign-ins'],
            $this->lines,
            'exactly the trip is announced — the strikes around it and the lockout itself are not',
        );
    }
}
