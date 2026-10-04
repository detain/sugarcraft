<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Http;

use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\CookieSessions;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * The second middleware: every request that can read or change state carries
 * a credential, loopback included (Appendix O §8.1, §8.2).
 *
 * PUBLIC, and nothing else: `GET /api/health` (which says only `{ok,
 * protocol}`), `POST /api/login` (the exchange that MINTS a credential, rate
 * limited like any failure) and the static UI files (the page must load
 * before it can sign in, and it holds no secret).
 *
 * CREDENTIALS, each tagged on the request as {@see ATTR_PRINCIPAL}:
 * - `bearer` — `Authorization: Bearer <owner token>`, for scripts and tools;
 * - `subprotocol` — the WebSocket upgrade offering `sugarcrush.auth.<token>`,
 *   for a non-browser WebSocket client that cannot set headers;
 * - `ticket` — `/ws?ticket=…`, single use, bound to a live cookie session;
 * - `cookie` — the HttpOnly session cookie `/api/login` set; API only, never
 *   the upgrade.
 *
 * The two a BROWSER carries ambiently (cookie, ticket) are refused when
 * {@see HostAndOriginGuard} saw no `Origin` on a state-changing request or
 * upgrade, and the cookie is refused outright on `Sec-Fetch-Site: cross-site`
 * — a cross-site page can make the browser send the cookie, never read the
 * answer, and must not get to change anything with it either.
 *
 * A presented-but-wrong credential counts against the address in the
 * {@see \SugarCraft\Crush\Server\Auth\RateLimiter}; a locked-out address is
 * answered 429 before any credential is looked at.
 */
final class AuthMiddleware
{
    public const ATTR_PRINCIPAL = 'sugarcrush.principal';

    /** The cookie session id behind a `cookie` or `ticket` principal. */
    public const ATTR_SESSION = 'sugarcrush.cookieSession';

    public const PUBLIC_API = ['/api/health', '/api/login'];

    public function __construct(
        private readonly AuthContext $auth,
        private readonly ServerConfig $config,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): mixed
    {
        $path = $request->getUri()->getPath();
        $address = ClientAddress::of($request, $this->config->trustedProxies);

        $retry = $this->auth->limiter->retryAfter($address);
        if ($retry > 0 && ($path === '/ws' || \str_starts_with($path, '/api/')) && $path !== '/api/health') {
            return Responses::error(429, 'rate_limited', 'too many failed sign-ins from this address', ['Retry-After' => (string) $retry]);
        }

        if (\in_array($path, self::PUBLIC_API, true) || !self::isProtected($path)) {
            return $next($request);
        }

        $originPresent = $request->getAttribute(HostAndOriginGuard::ATTR_ORIGIN) !== HostAndOriginGuard::ORIGIN_ABSENT;
        $presented = false;

        $authorization = $request->getHeaderLine('Authorization');
        if (\preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m) === 1) {
            $presented = true;
            if ($this->auth->tokens->matches($m[1])) {
                return $next($request->withAttribute(self::ATTR_PRINCIPAL, 'bearer'));
            }
        }

        if ($path === '/ws') {
            $subprotocolToken = self::subprotocolToken($request);
            if ($subprotocolToken !== null) {
                $presented = true;
                if ($this->auth->tokens->matches($subprotocolToken)) {
                    return $next($request->withAttribute(self::ATTR_PRINCIPAL, 'subprotocol'));
                }
            }

            $ticket = $request->getQueryParams()['ticket'] ?? null;
            if (\is_string($ticket) && $ticket !== '') {
                $presented = true;
                $session = $this->auth->tickets->consume($ticket);
                $cookie = self::cookie($request);
                if (
                    $originPresent
                    && $session !== null
                    && $this->auth->sessions->validate($session)
                    && ($cookie === null || \hash_equals($session, $cookie))
                ) {
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
            if ($originPresent && !$crossSite && $this->auth->sessions->validate($cookie)) {
                return $next($request
                    ->withAttribute(self::ATTR_PRINCIPAL, 'cookie')
                    ->withAttribute(self::ATTR_SESSION, $cookie));
            }
        }

        if ($presented) {
            $this->auth->limiter->recordFailure($address);
        }

        return Responses::error(401, 'unauthorized', 'authentication required');
    }

    /** `/ws` and every `/api/` path; the static UI is public. */
    public static function isProtected(string $path): bool
    {
        return $path === '/ws' || \str_starts_with($path, '/api/');
    }

    /** The session cookie's value, or null when the request carries none. */
    public static function cookie(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[CookieSessions::COOKIE] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** The token in an offered `sugarcrush.auth.<token>` subprotocol, if any. */
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
}
