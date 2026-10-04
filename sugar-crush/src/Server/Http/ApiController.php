<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Crush\Server\Auth\AuthContext;
use SugarCraft\Crush\Server\Auth\CookieSessions;
use SugarCraft\Crush\Server\Auth\Tickets;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * The HTTP half of the transport (Appendix O §8.2, §8.3): health, sign-in,
 * WebSocket tickets and sign-out. Everything a session DOES travels over the
 * WebSocket; these four exist to get a browser onto it safely.
 *
 * - `GET /api/health` — public; `{ok, protocol}` and nothing else, so an
 *   unauthenticated prober learns neither the version nor the project root.
 * - `POST /api/login` — `{"code": …}` (the one-time code from the URL
 *   fragment) or `{"token": …}` (the owner token) for an HttpOnly,
 *   SameSite=Strict session cookie. A wrong one counts against the address.
 * - `POST /api/ticket` — cookie only: a single-use, 30-second ticket for
 *   `/ws?ticket=…`, bound to that cookie's session.
 * - `POST /api/logout` — ends the cookie session.
 */
final class ApiController
{
    public function __construct(
        private readonly AuthContext $auth,
        private readonly ServerConfig $config,
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

        $granted = (\is_string($code) && $this->auth->loginCodes->consume($code))
            || (\is_string($token) && $this->auth->tokens->matches($token));
        if (!$granted) {
            $this->auth->limiter->recordFailure(ClientAddress::of($request, $this->config->trustedProxies));

            return Responses::error(401, 'unauthorized', 'that login code or token is not valid');
        }

        $session = $this->auth->sessions->open();

        return Responses::json(200, ['ok' => true], ['Set-Cookie' => $this->cookieHeader($request, $session, CookieSessions::LIFETIME_SECONDS)]);
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
        }

        return Responses::json(200, ['ok' => true], ['Set-Cookie' => $this->cookieHeader($request, '', 0)]);
    }

    private function cookieHeader(ServerRequestInterface $request, string $value, int $maxAge): string
    {
        return CookieSessions::COOKIE . '=' . $value
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
