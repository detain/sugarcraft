<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who sent a request, and over what, as far as this server may believe.
 *
 * `X-Forwarded-For` / `X-Forwarded-Proto` are honoured ONLY when the TCP peer
 * is a listed trusted proxy (`server.trustedProxies`, Appendix O §8.3): any
 * client can write those headers, so believing them from anyone else would let
 * a caller pick the address the auth rate limiter keys on, or claim TLS it
 * never had and be handed a `Secure` cookie flag.
 */
final class ClientAddress
{
    private function __construct()
    {
    }

    /**
     * The client's IP: the TCP peer, or — behind a trusted proxy — the
     * right-most `X-Forwarded-For` entry that is not itself a trusted proxy.
     *
     * @param list<string> $trustedProxies
     */
    public static function of(ServerRequestInterface $request, array $trustedProxies): string
    {
        $peer = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($peer === '' || !self::inAny($peer, $trustedProxies)) {
            return $peer;
        }

        $chain = \array_map('trim', \explode(',', $request->getHeaderLine('X-Forwarded-For')));
        for ($i = \count($chain) - 1; $i >= 0; --$i) {
            $hop = $chain[$i];
            if ($hop === '' || @\inet_pton($hop) === false) {
                return $peer;
            }
            if (!self::inAny($hop, $trustedProxies)) {
                return $hop;
            }
        }

        return $peer;
    }

    /**
     * Whether the browser reached this server over TLS: only a trusted proxy's
     * `X-Forwarded-Proto: https` can say so, since the listener itself never
     * speaks TLS in v1.
     *
     * @param list<string> $trustedProxies
     */
    public static function isSecure(ServerRequestInterface $request, array $trustedProxies): bool
    {
        $peer = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        return $peer !== ''
            && self::inAny($peer, $trustedProxies)
            && \strtolower(\trim(\explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0])) === 'https';
    }

    /** Whether $range is an IP address or an `address/prefix` CIDR. */
    public static function isValidRange(string $range): bool
    {
        [$address, $prefix] = \array_pad(\explode('/', \trim($range), 2), 2, null);
        $packed = @\inet_pton((string) $address);
        if ($packed === false) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }

        return \ctype_digit($prefix) && (int) $prefix <= \strlen($packed) * 8;
    }

    /**
     * Whether $ip falls in any of $ranges (IPs or CIDRs; IPv4 and IPv6 never
     * match each other).
     *
     * @param list<string> $ranges
     */
    public static function inAny(string $ip, array $ranges): bool
    {
        $packed = @\inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($ranges as $range) {
            [$address, $prefix] = \array_pad(\explode('/', \trim($range), 2), 2, null);
            $net = @\inet_pton((string) $address);
            if ($net === false || \strlen($net) !== \strlen($packed)) {
                continue;
            }
            $bits = $prefix === null ? \strlen($net) * 8 : (int) $prefix;
            $bytes = \intdiv($bits, 8);
            if (\substr($packed, 0, $bytes) !== \substr($net, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((\ord($packed[$bytes]) & $mask) === (\ord($net[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
