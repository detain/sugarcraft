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
 * Appendix O §8.1/§8.2 as amended by ISS-007 and ISS-008: a credential on
 * everything but health, login and the UI files — loopback included; the two
 * ambient browser credentials (cookie, ticket) refused without an Origin, and
 * the cookie refused unless the login secret minted with it comes back in
 * {@see AuthMiddleware::SECRET_HEADER}; and the owner token authenticated
 * BEFORE any lockout, so one noisy local process can never sign the owner out
 * of its own port.
 */
final class AuthMiddlewareTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /** The headers that make a `/ws` GET a real upgrade (ISS-008's 426 door). */
    private const UPGRADE = ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Version' => '13', 'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ=='];

    private AuthContext $auth;

    private float $now = 1000.0;

    private AuthMiddleware $middleware;

    /** @var list<string> */
    private array $logLines = [];

    /** @var list<string> */
    private array $seenSessions = [];

    private ?ServerRequestInterface $passed = null;

    protected function setUp(): void
    {
        $this->auth = AuthContext::new(TokenStore::new('/nonexistent')->withOverride(self::TOKEN), fn (): float => $this->now);
        $this->middleware = new AuthMiddleware(
            $this->auth,
            ServerConfig::new('/nonexistent'),
            function (string $line): void {
                $this->logLines[] = $line;
            },
            function (string $session): void {
                $this->seenSessions[] = $session;
            },
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $target, array $headers = [], string $origin = HostAndOriginGuard::ORIGIN_SAME, string $peer = '127.0.0.1'): ResponseInterface
    {
        $this->passed = null;
        $request = (new ServerRequest($method, 'http://127.0.0.1:7420' . $target, $headers + ['Host' => '127.0.0.1:7420'], '', '1.1', ['REMOTE_ADDR' => $peer]))
            ->withAttribute(HostAndOriginGuard::ATTR_ORIGIN, $origin);
        $result = ($this->middleware)($request, function (ServerRequestInterface $r): ResponseInterface {
            $this->passed = $r;

            return new Response(204);
        });
        self::assertInstanceOf(ResponseInterface::class, $result);

        return $result;
    }

    /** A live cookie session plus the login secret minted for it (ISS-007). */
    private function signedIn(): string
    {
        $session = $this->auth->sessions->open();
        $this->secrets[$session] = $this->middleware->mintLoginSecret($session);

        return $session;
    }

    /**
     * @var array<string, string> cookie session => login secret
     */
    private array $secrets = [];

    /** The port-scoped cookie name the middleware reads for a call() (all dial 7420). */
    private function cookieName(): string
    {
        return AuthMiddleware::cookieName(new ServerRequest('GET', 'http://127.0.0.1:7420/api/ticket', ['Host' => '127.0.0.1:7420']));
    }

    /**
     * @return array<string, string>
     */
    private function cookieHeaders(string $session): array
    {
        return [
            'Cookie' => $this->cookieName() . '=' . $session,
            AuthMiddleware::SECRET_HEADER => $this->secrets[$session],
        ];
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
        foreach ([['GET', '/ws', self::UPGRADE], ['POST', '/api/ticket', []], ['POST', '/api/logout', []], ['GET', '/api/anything', []]] as [$method, $path, $headers]) {
            $response = $this->call($method, $path, $headers);
            self::assertSame(401, $response->getStatusCode(), $path);
            self::assertStringContainsString('"kind":"unauthorized"', (string) $response->getBody());
            self::assertNull($this->passed);
        }
    }

    public function testAPlainGetOfWsIsAnsweredUpgradeRequiredBeforeAnyAuthOrStrike(): void
    {
        // ISS-008: a cross-site <img> pointed at /ws used to burn the shared
        // address budget with junk tickets. It is refused before the limiter
        // is consulted and without striking: even MAX_FAILURES of them leave
        // a real sign-in untouched.
        for ($i = 0; $i < RateLimiter::MAX_FAILURES + 2; ++$i) {
            $response = $this->call('GET', '/ws?ticket=' . 'bogus' . $i);
            self::assertSame(426, $response->getStatusCode());
            self::assertStringContainsString('"kind":"upgrade_required"', (string) $response->getBody());
            self::assertSame('websocket', $response->getHeaderLine('Upgrade'));
        }
        self::assertSame(0, $this->auth->limiter->retryAfter('127.0.0.1'), 'the plain GET door never strikes');

        $session = $this->signedIn();
        self::assertSame(204, $this->call('POST', '/api/ticket', $this->cookieHeaders($session))->getStatusCode(), 'a live sign-in still works after the barrage');
    }

    public function testTheBearerTokenAuthenticatesWithOrWithoutAnOrigin(): void
    {
        self::assertSame(204, $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . self::TOKEN], HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());
        self::assertSame('bearer', $this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));

        self::assertSame(401, $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . \str_repeat('0', 64)])->getStatusCode());
    }

    public function testTheTokenSubprotocolAuthenticatesTheUpgradeOnly(): void
    {
        $offer = self::UPGRADE + ['Sec-WebSocket-Protocol' => 'candy-code.v1, candy-code.auth.' . self::TOKEN];

        self::assertSame(204, $this->call('GET', '/ws', $offer, HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());
        self::assertSame('subprotocol', $this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
        self::assertSame(401, $this->call('POST', '/api/ticket', $offer)->getStatusCode(), 'the subprotocol is a WebSocket credential, not an API one');
    }

    public function testACookieNeedsItsLoginSecretAndCarriesItsSessionId(): void
    {
        $session = $this->signedIn();
        $name = $this->cookieName();

        self::assertSame(401, $this->call('POST', '/api/ticket', ['Cookie' => $name . '=' . $session])->getStatusCode(), 'the cookie alone is not authority (ISS-007)');
        self::assertSame(401, $this->call('POST', '/api/ticket', ['Cookie' => $name . '=' . $session, AuthMiddleware::SECRET_HEADER => 'wrong'])->getStatusCode());
        self::assertSame(204, $this->call('POST', '/api/ticket', $this->cookieHeaders($session))->getStatusCode());
        self::assertSame('cookie', $this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
        self::assertSame($session, $this->passed?->getAttribute(AuthMiddleware::ATTR_SESSION));

        $other = $this->auth->sessions->open();
        self::assertSame(401, $this->call('POST', '/api/ticket', ['Cookie' => $name . '=' . $other, AuthMiddleware::SECRET_HEADER => $this->secrets[$session]])->getStatusCode(), 'a secret is bound to ITS session');
    }

    public function testTheCookieIsRefusedWithoutAnOriginAndOnACrossSiteFetch(): void
    {
        $session = $this->signedIn();
        $cookie = $this->cookieHeaders($session);

        self::assertSame(401, $this->call('POST', '/api/ticket', $cookie, HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());
        self::assertSame(401, $this->call('POST', '/api/ticket', $cookie + ['Sec-Fetch-Site' => 'cross-site'])->getStatusCode());
        self::assertSame(204, $this->call('POST', '/api/ticket', $cookie + ['Sec-Fetch-Site' => 'same-origin'])->getStatusCode());
    }

    public function testATicketOpensTheSocketOnceWhileItsCookieSessionLives(): void
    {
        $session = $this->auth->sessions->open();
        $ticket = $this->auth->tickets->mint($session);

        self::assertSame(204, $this->call('GET', '/ws?ticket=' . $ticket, self::UPGRADE)->getStatusCode());
        self::assertSame('ticket', $this->passed?->getAttribute(AuthMiddleware::ATTR_PRINCIPAL));
        self::assertSame($session, $this->passed?->getAttribute(AuthMiddleware::ATTR_SESSION), 'ISS-009: the socket remembers the session behind its ticket');
        self::assertSame(401, $this->call('GET', '/ws?ticket=' . $ticket, self::UPGRADE)->getStatusCode(), 'single use');

        $revoked = $this->auth->tickets->mint($session);
        $this->auth->sessions->revoke($session);
        self::assertSame(401, $this->call('GET', '/ws?ticket=' . $revoked, self::UPGRADE)->getStatusCode(), 'bound to a live session');

        $live = $this->auth->sessions->open();
        $noOrigin = $this->auth->tickets->mint($live);
        self::assertSame(401, $this->call('GET', '/ws?ticket=' . $noOrigin, self::UPGRADE, HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());

        $allowed = $this->auth->tickets->mint($live);
        self::assertSame(204, $this->call('GET', '/ws?ticket=' . $allowed, self::UPGRADE, HostAndOriginGuard::ORIGIN_ALLOWED)->getStatusCode(), 'an allow-listed origin may open the socket');

        $other = $this->auth->sessions->open();
        $mismatch = $this->auth->tickets->mint($live);
        self::assertSame(
            401,
            $this->call('GET', '/ws?ticket=' . $mismatch, self::UPGRADE + ['Cookie' => $this->cookieName() . '=' . $other])->getStatusCode(),
            'a ticket presented beside a DIFFERENT cookie is refused',
        );
    }

    public function testATicketExpiresAfterThirtySeconds(): void
    {
        $ticket = $this->auth->tickets->mint($this->auth->sessions->open());
        $this->now += 30.5;

        self::assertSame(401, $this->call('GET', '/ws?ticket=' . $ticket, self::UPGRADE)->getStatusCode());
    }

    public function testAJunkTicketWithoutAMatchingOriginNeverStrikes(): void
    {
        // ISS-008: the strike is for failed SIGN-INS. A junk ticket the
        // origin gate never even let reach the store leaves the budget alone.
        for ($i = 0; $i < RateLimiter::MAX_FAILURES + 2; ++$i) {
            self::assertSame(401, $this->call('GET', '/ws?ticket=' . 'bogus' . $i, self::UPGRADE, HostAndOriginGuard::ORIGIN_ABSENT)->getStatusCode());
        }
        self::assertSame(0, $this->auth->limiter->retryAfter('127.0.0.1'));

        $session = $this->signedIn();
        self::assertSame(204, $this->call('POST', '/api/ticket', $this->cookieHeaders($session))->getStatusCode());
    }

    public function testTheOwnerTokenIsNeverLockedOutAndLockoutsAreLogged(): void
    {
        // A missing credential still costs nothing.
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(401, $this->call('POST', '/api/ticket')->getStatusCode());
        }
        self::assertSame(204, $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . self::TOKEN])->getStatusCode(), 'no credential presented, no strike');

        // Wrong bearers strike, and the strike that trips the lockout is ONE log line.
        for ($i = 0; $i < RateLimiter::MAX_FAILURES; ++$i) {
            $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer wrong']);
        }
        self::assertSame(
            1,
            \count($lines = \array_filter($this->logLines, static fn (string $l): bool => \str_starts_with($l, 'locked out 127.0.0.1'))),
            'the lockout is announced once',
        );
        self::assertStringContainsString('for ' . RateLimiter::LOCKOUT_SECONDS . ' s after ' . RateLimiter::MAX_FAILURES . ' failed sign-ins', \reset($lines) ?: '');

        // ISS-008 flipped the old expectation: while the ADDRESS is locked,
        // the RIGHT token passes — every local account shares 127.0.0.1, so a
        // hammerer must not be able to sign the owner out of its own port.
        $this->assertSameNowLocked();
        self::assertSame(204, $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . self::TOKEN])->getStatusCode(), 'the owner is never locked out of its own port');
        self::assertSame(204, $this->call('GET', '/api/health')->getStatusCode(), 'health stays reachable');
        self::assertSame(204, $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . self::TOKEN], HostAndOriginGuard::ORIGIN_SAME, '127.0.0.2')->getStatusCode(), 'per address');

        // Credentials that are NOT the token still wait the lockout out.
        $session = $this->signedIn();
        self::assertSame(429, $this->call('POST', '/api/ticket', $this->cookieHeaders($session))->getStatusCode());
        self::assertSame((string) RateLimiter::LOCKOUT_SECONDS, $this->call('POST', '/api/ticket', $this->cookieHeaders($session))->getHeaderLine('Retry-After'));

        $this->now += RateLimiter::LOCKOUT_SECONDS + 1;
        self::assertSame(204, $this->call('POST', '/api/ticket', $this->cookieHeaders($session))->getStatusCode());
    }

    private function assertSameNowLocked(): void
    {
        self::assertGreaterThan(0, $this->auth->limiter->retryAfter('127.0.0.1'), 'the address is locked out');
    }

    public function testTheCookieNameIsScopedToTheRequestPort(): void
    {
        // ISS-007: browser jars key by HOST; the name is scoped so a second
        // listener of this shape on another port never reads this one's
        // cookie as its own ambient session.
        $dial = static fn (string $uri, array $headers = []): ServerRequestInterface => new ServerRequest('GET', $uri, $headers);

        self::assertSame(CookieSessions::COOKIE . '_7420', AuthMiddleware::cookieName($dial('http://127.0.0.1:7420/api/ticket')));
        self::assertSame(CookieSessions::COOKIE . '_8443', AuthMiddleware::cookieName($dial('http://127.0.0.1/', ['Host' => '127.0.0.1:8443'])), 'the Host port when the URI carries none');
        self::assertSame(CookieSessions::COOKIE . '_443', AuthMiddleware::cookieName($dial('https://example.com/api/ticket')), 'the scheme default when neither names one');
    }

    public function testEveryAuthenticatedUseOfASessionIsObservedForTheExpirySweep(): void
    {
        $session = $this->signedIn();
        $ticket = $this->auth->tickets->mint($session);

        $this->call('GET', '/ws?ticket=' . $ticket, self::UPGRADE);
        $this->call('POST', '/api/ticket', $this->cookieHeaders($session));
        $this->call('GET', '/ws', self::UPGRADE + ['Authorization' => 'Bearer ' . self::TOKEN]);
        $this->call('POST', '/api/ticket', ['Cookie' => 'nope']);

        self::assertSame([$session, $session], $this->seenSessions, 'ticket and cookie uses are stamped once each; token principals have no session');
    }
}
