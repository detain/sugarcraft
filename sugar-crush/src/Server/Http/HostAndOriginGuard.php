<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Http;

use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * The first middleware: refuses a request whose `Host` this server does not
 * answer to, and a state-changing request or WebSocket upgrade whose `Origin`
 * it does not trust (Appendix O §8.3).
 *
 * - **Host — DNS rebinding.** A page at `evil.example` whose name is re-pointed
 *   at 127.0.0.1 reaches this port with `Host: evil.example`. Only the
 *   loopback names, the bind address itself (a `--allow-remote` bind), this
 *   machine's own interface IPs on a wildcard `--allow-remote` bind
 *   ({@see ServerConfig::reachableHosts()} — literal IPs of this host, which
 *   no rebinding page can send) and `--allowed-host` / `server.allowedHosts`
 *   are answered; anything else is 421, naming the host and the way to
 *   allow it.
 * - **Origin — CSWSH and CSRF.** On every upgrade and every non-GET request a
 *   present `Origin` must be this server's own origin (the validated `Host`,
 *   over http or https) or one of `server.allowedOrigins`; anything else,
 *   `null` included, is 403. A MISSING `Origin` is let through but recorded
 *   ({@see ATTR_ORIGIN} = {@see ORIGIN_ABSENT}): non-browser clients send none,
 *   and {@see AuthMiddleware} then refuses the two credentials a browser
 *   carries ambiently (the cookie and a ticket), so only an explicit bearer
 *   token or token subprotocol can authenticate an origin-less request.
 */
final class HostAndOriginGuard
{
    /** Request attribute: how the Origin check came out. */
    public const ATTR_ORIGIN = 'sugarcrush.origin';

    public const ORIGIN_SAME = 'same';

    public const ORIGIN_ALLOWED = 'allowed';

    public const ORIGIN_ABSENT = 'absent';

    /** A safe request (GET/HEAD) that is not an upgrade: Origin is not consulted. */
    public const ORIGIN_UNCHECKED = 'unchecked';

    private const LOOPBACK_NAMES = ['127.0.0.1', 'localhost', '[::1]'];

    /**
     * @param (\Closure(string): void)|null $log one line per refused host
     */
    public function __construct(
        private readonly ServerConfig $config,
        private readonly ?\Closure $log = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): mixed
    {
        $host = self::normaliseAuthority($request->getHeaderLine('Host'));
        if ($host === null) {
            return Responses::error(421, 'host_refused', 'this server does not answer to that host name');
        }
        if (!$this->answersTo($host)) {
            // The name passed normaliseAuthority()'s pattern: no quote, space
            // or control byte can ride into the message or the log.
            if ($this->log !== null) {
                ($this->log)(\sprintf('refused host "%s": start it with --allowed-host %s or add it to server.allowedHosts', $host['name'], $host['name']));
            }

            return Responses::error(421, 'host_refused', Lang::t('cli.serve.host_refused', [
                'host' => $host['name'],
                'fix' => Lang::t('cli.serve.host_refused_fix', ['host' => $host['name']]),
            ]));
        }

        if (!self::isStateChanging($request)) {
            return $next($request->withAttribute(self::ATTR_ORIGIN, self::ORIGIN_UNCHECKED));
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $next($request->withAttribute(self::ATTR_ORIGIN, self::ORIGIN_ABSENT));
        }

        $normalised = self::normaliseOrigin($origin);
        if ($normalised !== null && ($normalised === 'http://' . $host['authority'] || $normalised === 'https://' . $host['authority'])) {
            return $next($request->withAttribute(self::ATTR_ORIGIN, self::ORIGIN_SAME));
        }
        if ($normalised !== null && \in_array($normalised, $this->config->allowedOrigins, true)) {
            return $next($request->withAttribute(self::ATTR_ORIGIN, self::ORIGIN_ALLOWED));
        }

        return Responses::error(403, 'origin_refused', 'cross-origin requests are not accepted');
    }

    /** A WebSocket upgrade, or any method that is not GET/HEAD. */
    public static function isStateChanging(ServerRequestInterface $request): bool
    {
        return self::isUpgrade($request) || !\in_array($request->getMethod(), ['GET', 'HEAD'], true);
    }

    public static function isUpgrade(ServerRequestInterface $request): bool
    {
        return \strtolower($request->getHeaderLine('Upgrade')) === 'websocket';
    }

    /**
     * `Origin` as `scheme://host[:port]`, default ports dropped, lower-cased;
     * null for anything else (`null`, a path, a non-http scheme).
     */
    public static function normaliseOrigin(string $origin): ?string
    {
        if (\preg_match('#^(https?)://(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?/?$#i', \trim($origin), $m) !== 1) {
            return null;
        }
        $scheme = \strtolower($m[1]);
        $port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . \strtolower($m[2]) . ($port === null || $port === $default ? '' : ':' . $port);
    }

    /**
     * @param array{name: string, port: ?int, authority: string} $host
     */
    private function answersTo(array $host): bool
    {
        $names = self::LOOPBACK_NAMES;
        if (!$this->config->isLoopback()) {
            $names[] = $this->config->hostForUrl();
            \array_push($names, ...$this->config->reachableHosts());
        }
        if (\in_array($host['name'], $names, true)) {
            return true;
        }

        foreach ($this->config->allowedHosts as $allowed) {
            if ($allowed === $host['name'] || $allowed === $host['authority']) {
                return true;
            }
        }

        return false;
    }

    /**
     * `Host` split into name and port, lower-cased; null when it is absent or
     * not a host at all.
     *
     * @return array{name: string, port: ?int, authority: string}|null
     */
    private static function normaliseAuthority(string $host): ?array
    {
        if (\preg_match('#^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?$#i', \trim($host), $m) !== 1) {
            return null;
        }
        $name = \strtolower($m[1]);
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

        return ['name' => $name, 'port' => $port, 'authority' => $name . ($port === null ? '' : ':' . $port)];
    }
}
