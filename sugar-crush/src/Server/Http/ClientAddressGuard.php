<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Http;

use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * The client-address allow-list (`--allowed-ips` / `server.allowedIps`): the
 * first guard, ahead of the Host/Origin checks, the sign-in code and every
 * credential, so a refused address learns nothing but that it is refused.
 *
 * - **Off when the list is empty** — the default, and the behaviour before the
 *   list existed: any address that reaches the port may try to sign in.
 * - **Loopback is always admitted** (127.0.0.0/8, ::1): `serve url`, the
 *   control socket's neighbours, `sugarcrush attach` and a reverse proxy on
 *   this machine keep working whatever the list says.
 * - **The client is the TCP peer** — or, when the peer is one of
 *   `server.trustedProxies`, the forwarded address {@see ClientAddress::of()}
 *   believes. An IPv4-mapped IPv6 peer (`::ffff:192.0.2.7`, what a dual-stack
 *   `::` bind reports) is the IPv4 address it carries.
 *
 * A refusal is 403 `ip_refused` — for an HTTP request and a WebSocket upgrade
 * alike, since the upgrade IS an HTTP request — naming the address and how to
 * admit it, and one log line says the same.
 */
final class ClientAddressGuard
{
    /** Always admitted, whatever the list says. */
    public const LOOPBACK = ['127.0.0.0/8', '::1'];

    /**
     * @param (\Closure(string): void)|null $log one line per refused client
     */
    public function __construct(
        private readonly ServerConfig $config,
        private readonly ?\Closure $log = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): mixed
    {
        if ($this->config->allowedIps === []) {
            return $next($request);
        }

        $client = ClientAddress::normalise(ClientAddress::of($request, $this->config->trustedProxies));
        if ($client !== '' && (ClientAddress::inAny($client, self::LOOPBACK) || ClientAddress::inAny($client, $this->config->allowedIps))) {
            return $next($request);
        }

        // normalise() answers inet_ntop()'s text or '': nothing a client
        // wrote can ride into the message or the log.
        $address = $client === '' ? Lang::t('serve.ip_refused.unknown') : $client;
        if ($this->log !== null) {
            ($this->log)(Lang::t('serve.ip_refused.log', ['address' => $address]));
        }

        return Responses::error(403, 'ip_refused', Lang::t('serve.ip_refused', ['address' => $address]));
    }
}
