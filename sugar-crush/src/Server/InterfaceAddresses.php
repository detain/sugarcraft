<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

/**
 * This machine's own addresses, for a server bound to a wildcard address
 * (`0.0.0.0` or `::`) with `--allow-remote`.
 *
 * A wildcard bind is reached through whichever interface address the client
 * dialled, so `0.0.0.0` is neither a URL anyone can open nor a `Host` a
 * browser ever sends. The literal IPs of this host's own interfaces are what
 * the sign-in URL should name and what {@see Http\HostAndOriginGuard} answers
 * to: they are not a DNS-rebinding vector, because a rebinding page reaches
 * the port under its OWN host name, never under one of this machine's IPs.
 *
 * Read once at start ({@see ServerConfig::withInterfaceAddresses()}); an
 * address added to an interface later is answered only after a restart, or
 * through `--allowed-host`.
 */
final class InterfaceAddresses
{
    private function __construct()
    {
    }

    /**
     * Every usable address of this machine's up interfaces, IPv4 first;
     * empty when none can be found.
     *
     * @return list<string>
     */
    public static function detect(): array
    {
        $found = [];
        if (\function_exists('net_get_interfaces')) {
            $interfaces = @\net_get_interfaces();
            foreach (\is_array($interfaces) ? $interfaces : [] as $interface) {
                if (!\is_array($interface) || ($interface['up'] ?? true) === false) {
                    continue;
                }
                foreach (\is_array($interface['unicast'] ?? null) ? $interface['unicast'] : [] as $entry) {
                    if (\is_array($entry) && \is_string($entry['address'] ?? null)) {
                        $found[] = $entry['address'];
                    }
                }
            }
        }
        // net_get_interfaces() can be listed in disable_functions; the
        // resolver's view of this host's own name is the fallback.
        if ($found === []) {
            $hostname = \gethostname();
            $resolved = \is_string($hostname) && $hostname !== '' ? @\gethostbynamel($hostname) : false;
            $found = \is_array($resolved) ? $resolved : [];
        }

        return self::usable($found);
    }

    /**
     * $addresses without what no remote client can dial: anything that is
     * not an IP, loopback, unspecified, and link-local (an IPv6 link-local
     * address needs a zone id no URL can carry). Lower-cased, de-duplicated,
     * IPv4 before IPv6, otherwise in the order given.
     *
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    public static function usable(array $addresses): array
    {
        $v4 = [];
        $v6 = [];
        foreach ($addresses as $address) {
            $address = \strtolower(\trim($address, " \t[]"));
            $packed = @\inet_pton($address);
            if ($packed === false) {
                continue;
            }
            if (\strlen($packed) === 4) {
                $first = \ord($packed[0]);
                if ($first === 127 || $first === 0 || ($first === 169 && \ord($packed[1]) === 254)) {
                    continue;
                }
                $v4[(string) \inet_ntop($packed)] = true;
                continue;
            }
            if (
                $packed === \inet_pton('::') || $packed === \inet_pton('::1')
                || (\ord($packed[0]) === 0xfe && (\ord($packed[1]) & 0xc0) === 0x80)
            ) {
                continue;
            }
            $v6[(string) \inet_ntop($packed)] = true;
        }

        return [...\array_map('strval', \array_keys($v4)), ...\array_map('strval', \array_keys($v6))];
    }
}
