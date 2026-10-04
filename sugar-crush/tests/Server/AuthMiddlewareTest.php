<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\CookieSessions;
use SugarCraft\Crush\Server\Auth\RateLimiter;
use SugarCraft\Crush\Server\Auth\TokenStore;
use SugarCraft\Crush\Server\Http\AuthMiddleware;
use SugarCraft\Crush\Server\Http\HostAndOriginGuard;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * Appendix O §8.1/§8.2: a credential on everything but health, login and the
 * UI files — loopback included — and the two ambient browser credentials
 * (cookie, ticket) refused without an Origin.
 */
final class AuthMiddlewareTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private AuthContext $auth;

    private float $now = 1000.0;

    private ?ServerRequestInterface $passed = null;

    protected function setUp(): void
    {
        $this->auth = AuthContext::new(TokenStore::new('/nonexistent')->withOverride(self::TOKEN), fn (): float => $this->now);
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $target, array $headers = [], string $origin = HostAndOriginGuard::ORIGIN_SAME, string $peer = '127.0.0.1'): ResponseInterface
    {
        $this->passed = null;
        $request = (new ServerRequest($method, 'http://127.0.0.1:7420' . $target, $headers + ['Host' => '127.0.0.1:7420'], '', '1.1', ['REMOTE_ADDR' => $peer]))
            ->withAttribute(HostAndOriginGuard::ATTR_ORIGIN, $origin);
        $result = (new AuthMiddleware($this->auth, ServerConfig::new('/nonexistent')))($request, function (ServerRequestInterface $r): ResponseInterface {
            $this->passed = $r;

            return new Response(204);
        });
        self::assertInstanceOf(ResponseInterface::class, $result);

        return $result;
    }

    public function testHealthLoginAndTheUiFilesArePublic(): void
    {
        self::assertSame(204, $this->call('GET', '/api/health')->getStatusCode());
        self::assertSame(204, $this->call('POST', '/api/login')->getStatusCode());
        self::assertSame(204, $this->call('GET', '/')->getStatusCode());
        self::assertSame(204, $this->call('GET', '/assets/app.js')->getStatusCode());
        self::assertNull($this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
    }

    public function testEverythingElseNeedsACredentialEvenOnLoopback(): void
    {
        foreach ([['GET', '/ws'], ['POST', '/api/ticket'], ['POST', '/api/logout'], ['GET', '/api/anything']] as [$method, $path]) {
            $response = $this->call($method, $path);
            self::assertSame(401, $response->getStatusCode(), $path);
            self::assertStringContainsString('"kind":"unauthorized"', (string) $response->getBody());
            self::assertNull($this->passed);
        }
    }

    public function testTheBearerTokenAuthenticatesWithOrWithoutAnOrigin(): void
    {
        self::assertSame(204, $this->call('GET', '/ws', ['Authorization' => 'Bearer ' . self::TOKEN], HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());
        self::assertSame('bearer', $this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));

        self::assertSame(401, $this->call('GET', '/ws', ['Authorization' => 'Bearer ' . \str_repeat('0', 64)])->getStatusCode());
    }

    public function testTheTokenSubprotocolAuthenticatesTheUpgradeOnly(): void
    {
        $offer = ['Sec-WebSocket-Protocol' => 'sugarcrush.v1, sugarcrush.auth.' . self::TOKEN];

        self::assertSame(204, $this->call('GET', '/ws', $offer, HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());
        self::assertSame('subprotocol', $this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
        self::assertSame(401, $this->call('POST', '/api/ticket', $offer)->getStatusCode(), 'the subprotocol is a WebSocket credential, not an API one');
    }

    public function testACookieSessionAuthenticatesAndCarriesItsId(): void
    {
        $session = $this->auth->sessions->open();

        self::assertSame(204, $this->call('POST', '/api/ticket', ['Cookie' => CookieSessions::COOKIE . '=' . $session])->getStatusCode());
        self::assertSame('cookie', $this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
        self::assertSame($session, $this->passed?->getAttribute(AuthMiddleware::ATTR_SESSION));
    }

    public function testTheCookieIsRefusedWithoutAnOriginAndOnACrossSiteFetch(): void
    {
        $cookie = ['Cookie' => CookieSessions::COOKIE . '=' . $this->auth->sessions->open()];

        self::assertSame(401, $this->call('POST', '/api/ticket', $cookie, HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());
        self::assertSame(401, $this->call('POST', '/api/ticket', $cookie + ['Sec-Fetch-Site' => 'cross-site'])->getStatusCode());
        self::assertSame(204, $this->call('POST', '/api/ticket', $cookie + ['Sec-Fetch-Site' => 'same-origin'])->getStatusCode());
    }

    public function testATicketOpensTheSocketOnceWhileItsCookieSessionLives(): void
    {
        $session = $this->auth->sessions->open();
        $ticket = $this->auth->tickets->mint($session);

        self::assertSame(204, $this->call('GET', '/ws?ticket=' . $ticket)->getStatusCode());
        self::assertSame('ticket', $this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
        self::assertSame(401, $this->call('GET', '/ws?ticket=' . $ticket)->getStatusCode(), 'single use');

        $revoked = $this->auth->tickets->mint($session);
        $this->auth->sessions->revoke($session);
        self::assertSame(401, $this->call('GET', '/ws?ticket=' . $revoked)->getStatusCode(), 'bound to a live session');

        $live = $this->auth->sessions->open();
        $noOrigin = $this->auth->tickets->mint($live);
        self::assertSame(401, $this->call('GET', '/ws?ticket=' . $noOrigin, [], HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());

        $other = $this->auth->sessions->open();
        $mismatch = $this->auth->tickets->mint($live);
        self::assertSame(
            401,
            $this->call('GET', '/ws?ticket=' . $mismatch, ['Cookie' => CookieSessions::COOKIE . '=' . $other])->getStatusCode(),
            'a ticket presented beside a DIFFERENT cookie is refused',
        );
    }

    public function testATicketExpiresAfterThirtySeconds(): void
    {
        $ticket = $this->auth->tickets->mint($this->auth->sessions->open());
        $this->now += 30.5;

        self::assertSame(401, $this->call('GET', '/ws?ticket=' . $ticket)->getStatusCode());
    }

    public function testWrongCredentialsLockTheAddressOutButAMissingOneDoesNot(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(401, $this->call('POST', '/api/ticket')->getStatusCode());
        }
        self::assertSame(204, $this->call('GET', '/ws', ['Authorization' => 'Bearer ' . self::TOKEN])->getStatusCode(), 'no credential presented, no strike');

        for ($i = 0; $i < RateLimiter::MAX_FAILURES; ++$i) {
            $this->call('GET', '/ws', ['Authorization' => 'Bearer wrong']);
        }
        $locked = $this->call('GET', '/ws', ['Authorization' => 'Bearer ' . self::TOKEN]);
        self::assertSame(429, $locked->getStatusCode(), 'even the right token waits out the lockout');
        self::assertSame((string) RateLimiter::LOCKOUT_SECONDS, $locked->getHeaderLine('Retry-After'));
        self::assertSame(204, $this->call('GET', '/api/health')->getStatusCode(), 'health stays reachable');
        self::assertSame(204, $this->call('GET', '/ws', ['Authorization' => 'Bearer ' . self::TOKEN], HostAndOriginGuard::ORIGIN_SAME, '127.0.0.2')->getStatusCode(), 'per address');

        $this->now += RateLimiter::LOCKOUT_SECONDS + 1;
        self::assertSame(204, $this->call('GET', '/ws', ['Authorization' => 'Bearer ' . self::TOKEN])->getStatusCode());
    }
}
