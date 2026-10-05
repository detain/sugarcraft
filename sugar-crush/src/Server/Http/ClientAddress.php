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
    /** The first 12 bytes of an IPv4-mapped IPv6 address (`::ffff:0:0/96`). */
    private const MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

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
        return self::parseRange($range) !== null;
    }

    /**
     * $ip in its canonical text form, an IPv4-mapped IPv6 address
     * (`::ffff:192.0.2.7`, what a dual-stack `::` listener reports for an
     * IPv4 peer) as the IPv4 address it carries; '' when it is not an IP.
     */
    public static function normalise(string $ip): string
    {
        $packed = self::pack($ip);

        return $packed === null ? '' : (string) \inet_ntop($packed);
    }

    /**
     * $range (an IP or a CIDR) in canonical form — the address normalised as
     * {@see normalise()} does, a mapped prefix shifted onto the IPv4 address
     * (`::ffff:10.0.0.0/104` is `10.0.0.0/8`), a full-length prefix dropped;
     * null when it is neither.
     */
    public static function normaliseRange(string $range): ?string
    {
        $parsed = self::parseRange($range);
        if ($parsed === null) {
            return null;
        }
        [$packed, $bits] = $parsed;
        $text = (string) \inet_ntop($packed);

        return $bits === \strlen($packed) * 8 ? $text : $text . '/' . $bits;
    }

    /**
     * Whether $ip falls in any of $ranges (IPs or CIDRs; IPv4 and IPv6 never
     * match each other, except that an IPv4-mapped IPv6 address — on either
     * side — counts as the IPv4 address it carries).
     *
     * @param list<string> $ranges
     */
    public static function inAny(string $ip, array $ranges): bool
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return false;
        }

        foreach ($ranges as $range) {
            $parsed = self::parseRange($range);
            if ($parsed === null || \strlen($parsed[0]) !== \strlen($packed)) {
                continue;
            }
            [$net, $bits] = $parsed;
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

    /** $ip packed, an IPv4-mapped IPv6 address as its 4 IPv4 bytes; null when it is not an IP. */
    private static function pack(string $ip): ?string
    {
        $packed = @\inet_pton(\trim($ip));
        if ($packed === false) {
            return null;
        }
        if (\strlen($packed) === 16 && \str_starts_with($packed, self::MAPPED_PREFIX)) {
            return \substr($packed, 12);
        }

        return $packed;
    }

    /**
     * $range as [packed address, prefix bits], a mapped range shifted onto
     * IPv4; null when it is not an IP or `address/prefix` CIDR.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function parseRange(string $range): ?array
    {
        [$address, $prefix] = \array_pad(\explode('/', \trim($range), 2), 2, null);
        $raw = @\inet_pton((string) $address);
        if ($raw === false) {
            return null;
        }
        $max = \strlen($raw) * 8;
        if ($prefix !== null && (!\ctype_digit($prefix) || (int) $prefix > $max)) {
            return null;
        }
        $bits = $prefix === null ? $max : (int) $prefix;
        if (\strlen($raw) === 16 && \str_starts_with($raw, self::MAPPED_PREFIX)) {
            if ($bits < 96) {
                // Wider than the mapped block: it names IPv6 space, kept as is.
                return [$raw, $bits];
            }

            return [\substr($raw, 12), $bits - 96];
        }

        return [$raw, $bits];
    }
}
