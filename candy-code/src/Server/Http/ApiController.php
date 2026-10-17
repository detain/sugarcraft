<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\CookieSessions;
use SugarCraft\Code\Server\Auth\Tickets;
use SugarCraft\Code\Server\ServerConfig;


/**
 * The HTTP half of the transport (Appendix O §8.2, §8.3): health, sign-in,
 * WebSocket tickets and sign-out. Everything a session DOES travels over the
 * WebSocket; these four exist to get a browser onto it safely.
 *
 * - `GET /api/health` — public; `{ok, protocol}` and nothing else, so an
 *   unauthenticated prober learns neither the version nor the project root.
 * - `POST /api/login` — `{"code": …}` (the one-time code from the URL
 *   fragment) or `{"token": …}` (the owner token) for an HttpOnly,
 *   SameSite=Strict session cookie PLUS a `loginSecret` in the JSON body
 *   (ISS-007): the page keeps the secret in localStorage — origin-scoped
 *   where the cookie is not — and {@see AuthMiddleware} requires it on
 *   everything the cookie authenticates. A wrong one counts against the
 *   address; a matching owner token never does (ISS-008).
 * - `POST /api/ticket` — cookie plus login secret only: a single-use,
 *   30-second ticket for `/ws?ticket=…`, bound to that cookie's session.
 * - `POST /api/logout` — ends the cookie session, and with it every
 *   WebSocket that session minted a ticket for (ISS-009).
 */
final class ApiController
{
    /**
     * @param \Closure(string): void|null $onSessionRevoked the server's hook to close the sockets a revoked session opened
     */
    public function __construct(
        private readonly AuthContext $auth,
        private readonly ServerConfig $config,
        private readonly AuthMiddleware $credentials,
        private readonly ?\Closure $onSessionRevoked = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        return match ($path) {
            '/api/health' => $method === 'GET' || $method === 'HEAD'
                ? Responses::json(200, ['ok' => true, 'protocol' => ServerConfig::PROTOCOL_MAJOR])
                : self::methodNotAllowed('GET'),
            '/api/login' => $method === 'POST' ? $this->login($request) : self::methodNotAllowed('POST'),
            '/api/ticket' => $method === 'POST' ? $this->ticket($request) : self::methodNotAllowed('POST'),
            '/api/logout' => $method === 'POST' ? $this->logout($request) : self::methodNotAllowed('POST'),
            default => Responses::error(404, 'not_found', 'no such endpoint'),
        };
    }

    private function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = self::jsonBody($request);
        $code = $body['code'] ?? null;
        $token = $body['token'] ?? null;
        $address = ClientAddress::of($request, $this->config->trustedProxies);

        // ISS-008: the owner token is tested before the lockout gate — this
        // endpoint is the one place the token hides in the BODY, where only
        // the handler can see it, which is why AuthMiddleware lets the whole
        // path through to here. A code never gets spent while locked out; a
        // refusal here strikes and locks like any other failed credential.
        $owner = \is_string($token) && $this->auth->tokens->matches($token);
        if (!$owner) {
            $retry = $this->auth->limiter->retryAfter($address);
            if ($retry > 0) {
                return Responses::error(429, 'rate_limited', 'too many failed sign-ins from this address', ['Retry-After' => (string) $retry]);
            }
        }

        $granted = $owner || (\is_string($code) && $this->auth->loginCodes->consume($code));
        if (!$granted) {
            $this->credentials->strike($address);

            return Responses::error(401, 'unauthorized', 'that login code or token is not valid');
        }

        $session = $this->auth->sessions->open();

        // ISS-007: the cookie alone is ambient to every port on this host;
        // the secret rides the JSON body, into localStorage, and comes back
        // on every request that wants the cookie's authority.
        $secret = $this->credentials->mintLoginSecret($session);

        return Responses::json(200, ['ok' => true, 'loginSecret' => $secret], ['Set-Cookie' => $this->cookieHeader($request, $session, CookieSessions::LIFETIME_SECONDS)]);
    }

    private function ticket(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        if ($request->getAttribute(AuthMiddleware::ATTR_PRINCIPAL) !== 'cookie' || !\is_string($session)) {
            return Responses::error(400, 'cookie_required', 'tickets are for signed-in browsers; other clients authenticate the upgrade directly');
        }

        return Responses::json(
            200,
            ['ticket' => $this->auth->tickets->mint($session), 'expiresInSeconds' => Tickets::TTL_SECONDS],
            // The session slid when it was validated; the cookie slides with it.
            ['Set-Cookie' => $this->cookieHeader($request, $session, CookieSessions::LIFETIME_SECONDS)],
        );
    }

    private function logout(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        if (\is_string($session)) {
            $this->auth->sessions->revoke($session);
            // ISS-009: revoking the session must also end what it opened —
            // the sockets keep their principal for life unless someone closes them.
            if ($this->onSessionRevoked !== null) {
                ($this->onSessionRevoked)($session);
            }
        }

        return Responses::json(200, ['ok' => true], ['Set-Cookie' => $this->cookieHeader($request, '', 0)]);
    }

    private function cookieHeader(ServerRequestInterface $request, string $value, int $maxAge): string
    {
        // Port-scoped name (ISS-007): see AuthMiddleware::cookieName() for
        // why the name is hygiene and the login secret is authority, and for
        // the `__Host-` prefix owed here once this server speaks HTTPS.
        return AuthMiddleware::cookieName($request) . '=' . $value
            . '; Path=/; HttpOnly; SameSite=Strict; Max-Age=' . $maxAge
            . (ClientAddress::isSecure($request, $this->config->trustedProxies) ? '; Secure' : '');
    }

    /**
     * The request body as a JSON object; empty when it is not one. Depth-capped
     * like every client payload (Appendix O §8.7).
     *
     * @return array<string, mixed>
     */
    private static function jsonBody(ServerRequestInterface $request): array
    {
        try {
            $decoded = \json_decode((string) $request->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) && !\array_is_list($decoded) ? $decoded : [];
    }

    private static function methodNotAllowed(string $allow): ResponseInterface
    {
        return Responses::error(405, 'method_not_allowed', 'method not allowed', ['Allow' => $allow]);
    }
}
