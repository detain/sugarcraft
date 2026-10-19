<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\CookieSessions;
use SugarCraft\Code\Server\Auth\RateLimiter;
use SugarCraft\Code\Server\ServerConfig;

/**
 * The second middleware: every request that can read or change state carries
 * a credential, loopback included (Appendix O §8.1, §8.2).
 *
 * PUBLIC, and nothing else: `GET /api/health` (which says only `{ok,
 * protocol}`), `POST /api/login` (the exchange that MINTS a credential — it
 * rate-limits itself in {@see ApiController}, where the owner token in the
 * JSON body can be seen) and the static UI files (the page must load before
 * it can sign in, and it holds no secret).
 *
 * CREDENTIALS, each tagged on the request as {@see ATTR_PRINCIPAL}:
 * - `bearer` — `Authorization: Bearer <owner token>`, for scripts and tools;
 * - `subprotocol` — the WebSocket upgrade offering `candy-code.auth.<token>`,
 *   for a non-browser WebSocket client that cannot set headers;
 * - `ticket` — `/ws?ticket=…`, single use, bound to a live cookie session;
 * - `cookie` — the HttpOnly session cookie `/api/login` set; API only, never
 *   the upgrade. Since ISS-007 the cookie is NOT authority by itself: every
 *   request the `cookie` principal authenticates must also carry, in
 *   {@see SECRET_HEADER}, the per-login secret the same response handed the
 *   page. Browsers key their cookie jars by host, not port, so the cookie
 *   reaches every listener on this machine; localStorage does not, so only
 *   this server's own origin can present the secret back.
 *
 * The two a BROWSER carries ambiently (cookie, ticket) are refused when
 * {@see HostAndOriginGuard} saw no `Origin` on a state-changing request or
 * upgrade, and the cookie is refused outright on `Sec-Fetch-Site: cross-site`
 * — a cross-site page can make the browser send the cookie, never read the
 * answer, and must not get to change anything with it either.
 *
 * LOCKOUTS, per ISS-008, in this exact order of checking:
 * - a non-upgrade `/ws` is answered 426 before anything else: a cross-site
 *   `<img>` pointed at it used to burn the shared address budget for free;
 * - a MATCHING bearer or subprotocol token passes before the lockout is even
 *   consulted — the owner is never locked out of its own port;
 * - a `/ws` ticket failure strikes only on a real upgrade whose `Origin` the
 *   guard matched (same or allowed): an origin-less junk ticket cannot reach
 *   the store, let alone the budget;
 * - every other presented-but-wrong credential strikes as before, and the
 *   strike that TRIPS a lockout is one log line (the 429 answers themselves
 *   ride the request log as status lines).
 */
final class AuthMiddleware
{
    public const ATTR_PRINCIPAL = 'candy-code.principal';

    /** The cookie session id behind a `cookie` or `ticket` principal. */
    public const ATTR_SESSION = 'candy-code.cookieSession';

    /** The header carrying the per-login secret (ISS-007), as named with the web client. */
    public const SECRET_HEADER = 'X-Candy-Code-Auth';

    /**
     * Paths under `/api/` that answer without a session. AUT-6 hook routes are
     * not in this exact-match list because they are per-name: they pass through
     * on {@see isHookRoute()} instead and carry their own secret, strike count
     * and rate inside {@see HookWebhooks}.
     */
    public const PUBLIC_API = ['/api/health', '/api/login'];

    /** Live login secrets held at once; the oldest give way beyond this. */
    private const MAX_LOGIN_SECRETS = 4096;

    /**
     * sha256(cookie session id) => sha256(login secret). Only ever consulted
     * after the session itself validated, so an entry outliving its session
     * is inert; the cap bounds growth, oldest login first.
     *
     * @var array<string, string>
     */
    private array $loginSecrets = [];

    /**
     * @param (\Closure(string): void)|null              $log             one line per refused credential and lockout
     * @param (\Closure(string): void)|null              $sessionObserver every successfully authenticated use of a cookie session, for the server's expiry sweep (ISS-009)
     */
    public function __construct(
        private readonly AuthContext $auth,
        private readonly ServerConfig $config,
        private readonly ?\Closure $log = null,
        private readonly ?\Closure $sessionObserver = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): mixed
    {
        $path = $request->getUri()->getPath();
        $address = ClientAddress::of($request, $this->config->trustedProxies);

        // A `/ws` that is not an upgrade never authenticates and never strikes:
        // 426 first, exactly what the Router would answer one stage later.
        if ($path === '/ws' && !HostAndOriginGuard::isUpgrade($request)) {
            return Responses::error(426, 'upgrade_required', 'this endpoint speaks WebSocket only', ['Upgrade' => 'websocket']);
        }

        // The owner token, header or subprotocol, clears every gate sight
        // unseen by the limiter (ISS-008): wrong ones still strike below.
        $authorization = $request->getHeaderLine('Authorization');
        $bearer = \preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m) === 1 ? $m[1] : null;
        if ($bearer !== null && $this->auth->tokens->matches($bearer)) {
            return $next($request->withAttribute(self::ATTR_PRINCIPAL, 'bearer'));
        }
        $subprotocolToken = $path === '/ws' ? self::subprotocolToken($request) : null;
        if ($subprotocolToken !== null && $this->auth->tokens->matches($subprotocolToken)) {
            return $next($request->withAttribute(self::ATTR_PRINCIPAL, 'subprotocol'));
        }

        $retry = $this->auth->limiter->retryAfter($address);
        if ($retry > 0 && self::isProtected($path) && $path !== '/api/health' && $path !== '/api/login' && !self::isHookRoute($path)) {
            return Responses::error(429, 'rate_limited', 'too many failed sign-ins from this address', ['Retry-After' => (string) $retry]);
        }

        if (\in_array($path, self::PUBLIC_API, true) || !self::isProtected($path) || self::isHookRoute($path)) {
            return $next($request);
        }

        $origin = $request->getAttribute(HostAndOriginGuard::ATTR_ORIGIN);
        $originMatched = $origin === HostAndOriginGuard::ORIGIN_SAME || $origin === HostAndOriginGuard::ORIGIN_ALLOWED;
        $originPresent = $origin !== HostAndOriginGuard::ORIGIN_ABSENT;
        $presented = $bearer !== null || $subprotocolToken !== null;

        if ($path === '/ws' && $originMatched) {
            $ticket = $request->getQueryParams()['ticket'] ?? null;
            if (\is_string($ticket) && $ticket !== '') {
                $presented = true;
                $session = $this->auth->tickets->consume($ticket);
                $cookie = self::cookie($request);
                if (
                    $session !== null
                    && $this->auth->sessions->validate($session)
                    && ($cookie === null || \hash_equals($session, $cookie))
                ) {
                    $this->observeSession($session);

                    return $next($request
                        ->withAttribute(self::ATTR_PRINCIPAL, 'ticket')
                        ->withAttribute(self::ATTR_SESSION, $session));
                }
            }
        }

        // Not on `/ws`: the upgrade takes a ticket, the token or the token
        // subprotocol (Appendix O §8.2). A browser sends the cookie on every
        // same-site upgrade by itself, so accepting it there would make the
        // single-use ticket decorative.
        $cookie = $path === '/ws' ? null : self::cookie($request);
        if ($cookie !== null) {
            $presented = true;
            $crossSite = \strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'cross-site';
            if (
                $originPresent
                && !$crossSite
                && $this->auth->sessions->validate($cookie)
                && $this->loginSecretMatches($request, $cookie)
            ) {
                $this->observeSession($cookie);

                return $next($request
                    ->withAttribute(self::ATTR_PRINCIPAL, 'cookie')
                    ->withAttribute(self::ATTR_SESSION, $cookie));
            }
        }

        if ($presented) {
            $this->strike($address);
        }

        return Responses::error(401, 'unauthorized', 'authentication required');
    }

    /** `/ws` and every `/api/` path; the static UI is public. */
    public static function isProtected(string $path): bool
    {
        return $path === '/ws' || \str_starts_with($path, '/api/');
    }

    /**
     * AUT-6: `POST /api/hooks/{name}` — protected in the sense of never free
     * (it authenticates by secret), public in the sense of this middleware:
     * no cookie, no ticket, and no share of the login lockout bucket, because
     * hammering a webhook must not lock the operator out of signing in.
     */
    public static function isHookRoute(string $path): bool
    {
        return \str_starts_with($path, ServerConfig::HOOK_ROUTE_PREFIX);
    }

    /**
     * The session cookie's name: {@see CookieSessions::COOKIE} scoped to the
     * port the request arrived on (ISS-007). The browser will still SEND the
     * cookie to other ports on this host — jars key by host alone — but a
     * second listener of this shape no longer reads it as its own ambient
     * session, and the login secret, origin-scoped where cookies are not,
     * carries the actual authority.
     *
     * Behind HTTPS the name should gain the `__Host-` prefix (Secure, Path=/,
     * no Domain — all already true here); this server does not terminate TLS,
     * so the prefix would be silently dropped by the browsers it serves.
     */
    public static function cookieName(ServerRequestInterface $request): string
    {
        $port = $request->getUri()->getPort();
        if ($port === null && \preg_match('/:(\d{1,5})$/', $request->getHeaderLine('Host'), $m) === 1) {
            $port = (int) $m[1];
        }
        if ($port === null) {
            $port = $request->getUri()->getScheme() === 'https' ? 443 : 80;
        }

        return CookieSessions::COOKIE . '_' . $port;
    }

    /** The session cookie's value, or null when the request carries none. */
    public static function cookie(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[self::cookieName($request)] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** The token in an offered `candy-code.auth.<token>` subprotocol, if any. */
    public static function subprotocolToken(ServerRequestInterface $request): ?string
    {
        foreach (\explode(',', $request->getHeaderLine('Sec-WebSocket-Protocol')) as $offered) {
            $offered = \trim($offered);
            if (\str_starts_with($offered, ServerConfig::AUTH_SUBPROTOCOL_PREFIX)) {
                return \substr($offered, \strlen(ServerConfig::AUTH_SUBPROTOCOL_PREFIX));
            }
        }

        return null;
    }

    /**
     * Mint the login secret for a fresh cookie session (ISS-007): what
     * `/api/login` hands the page beside the cookie, and what every request
     * the cookie authenticates must present back in {@see SECRET_HEADER}.
     * The secret itself is stored only as a SHA-256 hash, like every other
     * credential here.
     */
    public function mintLoginSecret(string $session): string
    {
        $secret = \bin2hex(\random_bytes(32));
        if (\count($this->loginSecrets) >= self::MAX_LOGIN_SECRETS) {
            \array_shift($this->loginSecrets);
        }
        $this->loginSecrets[self::secretKey($session)] = \hash('sha256', $secret);

        return $secret;
    }

    /** Whether $request carries the login secret bound to cookie session $session. */
    public function loginSecretMatches(ServerRequestInterface $request, string $session): bool
    {
        $stored = $this->loginSecrets[self::secretKey($session)] ?? null;
        if ($stored === null) {
            return false;
        }
        $presented = $request->getHeaderLine(self::SECRET_HEADER);

        return $presented !== '' && \hash_equals($stored, \hash('sha256', $presented));
    }

    /**
     * One failed credential for $address, with the lockout event written to
     * the log (ISS-008). Shared with {@see ApiController} so both failure
     * doors strike and announce exactly alike.
     */
    public function strike(string $address): void
    {
        if ($this->auth->limiter->recordFailure($address) && $this->log !== null) {
            ($this->log)(\sprintf(
                'locked out %s for %d s after %d failed sign-ins',
                $address,
                RateLimiter::LOCKOUT_SECONDS,
                RateLimiter::MAX_FAILURES,
            ));
        }
    }

    private function observeSession(string $session): void
    {
        if ($this->sessionObserver !== null) {
            ($this->sessionObserver)($session);
        }
    }

    private static function secretKey(string $session): string
    {
        return \hash('sha256', $session);
    }
}
