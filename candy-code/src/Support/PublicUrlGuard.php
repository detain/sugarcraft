<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The SSRF egress rule, in one place.
 *
 * The range list, the resolver and the mixed-answer verdict moved here VERBATIM
 * from {@see \SugarCraft\Code\Tools\BuiltIn\WebFetch} (C-CTX-4.d), and WebFetch
 * delegates to this class, so the forced-instruction URL source
 * ({@see \SugarCraft\Code\Context\InstructionFileLoader::loadForced()}) consults
 * the SAME rule as the model-facing fetch tool rather than a second list that
 * drifts — the exact failure the F-W3 note below describes, which is why one
 * list exists at all. WebFetch's own end-to-end suite
 * (tests/Tools/ToolSecurityTest.php) pins that the delegation wire holds.
 *
 * The {@see fetch()} arm is the instruction-source transport: HTTPS only (a
 * remote document enters the system prompt with no further gate, so it never
 * rides plaintext), URL credentials refused, per-hop fixed dial like WebFetch's
 * own, and a per-read socket budget — E646's ban is on a blanket wall-clock
 * kill, and this is the same per-read `timeout` shape WebFetch has always used,
 * just a tighter number for a document that only has to be small.
 */
final class PublicUrlGuard
{
    /**
     * Hostnames refused by name before any resolver is consulted.
     */
    public const BLOCKED_HOSTNAMES = [
        'localhost',
        '127.0.0.1',
        '::1',
    ];

    /**
     * Every range a fetch must never reach. The v4 half is everything that is
     * not ordinary public unicast; the v6 half adds the transition prefixes
     * whose packets a gateway re-emits as IPv4 (audit F-W1, 2026-10-01 — the
     * pre-audit list stopped at RFC 1918 + loopback + link-local, so the
     * Alibaba metadata service, every Tailscale peer and NAT64-wrapped
     * loopback were all dialable without a prompt).
     */
    public const BLOCKED_IP_RANGES = [
        // 'this host' on Linux — absent pre-wave (audit M7's second half).
        '0.0.0.0/8',
        '127.0.0.0/8',
        '10.0.0.0/8',
        // Shared address space (RFC 6598): carrier-grade NAT, every Tailscale
        // tailnet address, and 100.100.100.200 — Alibaba Cloud's metadata
        // service, the one cloud credential endpoint outside 169.254/16.
        '100.64.0.0/10',
        '172.16.0.0/12',
        // IETF protocol assignments (RFC 6890): DS-Lite B4/AFTR, the NAT64
        // discovery literals 192.0.0.170/171 — host-adjacent, never a site.
        '192.0.0.0/24',
        '192.168.0.0/16',
        '169.254.0.0/16',
        // Benchmarking (RFC 2544), which internal and VPN fabrics borrow
        // because it is guaranteed never to collide with the internet.
        '198.18.0.0/15',
        // Multicast, then reserved-for-future-use, which also holds the
        // limited broadcast 255.255.255.255. Neither carries a TCP fetch, so
        // refusing them costs nothing and keeps the list "not public unicast".
        '224.0.0.0/4',
        '240.0.0.0/4',
        // The unspecified v6 address dials the local host, like 0.0.0.0.
        '::/128',
        '::1/128',
        // NAT64 well-known prefix (RFC 6052) and its local-use sibling (RFC
        // 8215). On a host with DNS64/NAT64 the gateway re-dials the embedded
        // IPv4, so `64:ff9b::7f00:1` is loopback by another name. The whole
        // prefix is refused (fail-closed), not only the private embeddings
        // that canonicalAddress() unwraps: the local-use /48 places the v4
        // bits operator-defined, and a public v4 target never NEEDS the
        // synthesized spelling while its own A record exists.
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        // Teredo (RFC 4380): the server and client v4 are embedded obfuscated
        // and a relay forwards on our behalf — no prefix-level way to vet it.
        '2001::/32',
        // 6to4 (RFC 3056): a relay unwraps bytes 2-5 as the v4 destination.
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        // Deprecated site-local (RFC 3879) — still routed internally by stacks
        // that never dropped it.
        'fec0::/10',
        // Multicast.
        'ff00::/8',
    ];

    /**
     * Redirect hops {@see fetch()} will follow for an instruction source,
     * re-decided through this guard at every hop. The same budget WebFetch's
     * hop loop enforces for its own redirects, which consults this class for
     * the address verdict.
     */
    public const MAX_REDIRECTS = 3;

    /**
     * Per-read socket budget for an instruction-source fetch. A document, not
     * an LLM call: 15 seconds of silence on one socket read is already far
     * past any honest static file's first byte. This is the same per-read
     * `timeout` context option WebFetch's transport has always set, under the
     * E646 reading that only blanket wall-clock kills of model calls are
     * banned — no alarm, no fork, nothing here can interrupt an in-flight
     * request at an arbitrary instant; the bound simply makes the next read
     * return.
     */
    public const FETCH_BUDGET_SECONDS = 15;

    private function __construct()
    {
    }

    /**
     * Resolve once, refuse the name if ANY answer is blocked, and return the
     * first validated literal to dial. The pre-wave check only validated when
     * `gethostbyname()` CHANGED the string, so literal-IP URLs (e.g. the cloud
     * metadata endpoint `http://169.254.169.254/`) bypassed validation
     * entirely — locally the connection just fails, but on cloud CI runners
     * the metadata service answers and the fetch "succeeded".
     *
     * @param (callable(string): list<string>)|null $resolveAddresses resolver seam ({@see resolveViaSystemDns()} by default)
     * @param (callable(string): bool)|null         $isBlockedAddress blocklist seam ({@see addressIsBlocked()} by default)
     *
     * @return array{0: ?string, 1: ?string} refusal message, else dial address
     */
    public static function guardedDialAddress(
        string $host,
        bool $redirected,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
    ): array {
        $blockedMessage = $redirected
            ? 'Error: redirect to private/link-local address is not allowed'
            : 'Error: fetching from private/link-local addresses is not allowed';

        $addresses = ($resolveAddresses ?? static fn (string $host): array => self::resolveViaSystemDns($host))($host);

        $dial = null;
        foreach ($addresses as $address) {
            // Contract: the resolver seam yields IP literals. Anything else is
            // refused here, because this exact string becomes the URL
            // authority on the next line of the trust chain.
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                return ["Error: could not resolve host: $host", null];
            }
            if (($isBlockedAddress ?? static fn (string $address): bool => self::addressIsBlocked($address))($address)) {
                return [$blockedMessage, null];
            }
            $dial ??= $address;
        }

        return $dial === null
            ? ["Error: could not resolve host: $host", null]
            : [null, $dial];
    }

    /**
     * Fetch a document over the guarded egress rule, for the forced-instruction
     * URL source.
     *
     * Every refusal WebFetch answers for its own hops is answered here too
     * (same constants, same {@see guardedDialAddress()} decision, re-consulted
     * per redirect hop), plus the two tightenings that belong to a document
     * that reaches the system prompt unreviewed: HTTPS only, and no URL
     * credentials. Locations are followed only as absolute https references —
     * RFC 3986 reference resolution stays in WebFetch, where its relative-form
     * test suite lives, rather than being copied a second time to drift.
     *
     * @param int                                     $maxBytes        hard wire bound; a body that grows past it abandons the stream mid-read
     * @param (callable(string): list<string>)|null   $resolveAddresses resolver seam
     * @param (callable(string): bool)|null           $isBlockedAddress blocklist seam
     * @param (callable(string, string, array<string,mixed>, string, int, int): ?array{0: string, 1: list<string>, 2: bool})|null $transfer transfer seam — ({@see transferPinned()} by default): scheme, host, parse of the hop URL, validated dial address, budget, wire bound; returns body + response headers + completeness, null on dial failure
     *
     * @return array{ok: bool, error: ?string, body: ?string}
     */
    public static function fetch(
        string $url,
        int $maxBytes,
        int $budgetSeconds = self::FETCH_BUDGET_SECONDS,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
        ?callable $transfer = null,
    ): array {
        $failure = static fn (string $error): array => ['ok' => false, 'error' => $error, 'body' => null];

        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $redirected = $hop > 0;

            $parsed = parse_url($current);
            if ($parsed === false) {
                return $failure($redirected ? 'Error: invalid redirect target' : 'Error: invalid URL');
            }

            $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
            if ($scheme !== 'https') {
                return $failure($redirected
                    ? 'Error: redirect to a non-https scheme is not allowed for instruction sources'
                    : 'Error: instruction sources must start with https://');
            }

            if (($parsed['host'] ?? '') === '') {
                return $failure($redirected ? 'Error: invalid redirect target' : 'Error: invalid URL');
            }

            if (isset($parsed['user']) || isset($parsed['pass'])) {
                return $failure('Error: instruction sources may not carry URL credentials');
            }

            // Bracketed IPv6 literal hosts: parse_url keeps the brackets (the
            // same extraction WebFetch's hop loop performs).
            $host = strtolower(trim((string) $parsed['host'], '[]'));
            if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
                return $failure($redirected
                    ? 'Error: redirect to localhost is not allowed'
                    : 'Error: fetching from localhost is not allowed');
            }

            [$refusal, $dialAddress] = self::guardedDialAddress($host, $redirected, $resolveAddresses, $isBlockedAddress);
            if ($refusal !== null) {
                return $failure($refusal);
            }

            $hopResult = ($transfer ?? static fn (string $scheme, string $host, array $parsed, string $address, int $budget, int $bound): ?array
                => self::transferPinned($scheme, $host, $parsed, (string) $address, $budget, $bound))(
                $scheme, $host, $parsed, (string) $dialAddress, $budgetSeconds, $maxBytes
            );
            if ($hopResult === null) {
                return $failure("Error fetching URL: $current");
            }
            [$body, $headers, $complete] = $hopResult;

            if (!$complete || strlen($body) > $maxBytes) {
                return $failure("Error: document exceeds the $maxBytes byte instruction-source cap");
            }

            $code = self::statusCode($headers);
            if ($code !== null && $code >= 300 && $code < 400) {
                $location = self::locationHeader($headers);
                if ($location === null) {
                    return $failure("Error: redirect not followed: no usable Location header (HTTP $code)");
                }
                $spelling = strtolower($location);
                if (!str_starts_with($spelling, 'https://') && !str_starts_with($spelling, 'http://')) {
                    return $failure('Error: redirect to a relative reference is not followed for instruction sources');
                }
                $current = $location;
                continue;
            }

            if ($code === null || $code >= 400) {
                return $failure($code === null
                    ? 'Error: instruction source returned no usable status line'
                    : "Error: instruction source returned HTTP $code");
            }

            return ['ok' => true, 'error' => null, 'body' => $body];
        }

        return $failure('Error: too many instruction-source redirects');
    }

    /**
     * System DNS for both address families. `dns_get_record()` speaks raw DNS
     * and misses /etc/hosts entries, so an empty answer falls back to the
     * `gethostby*` resolvers (which return v4 only — the best this wrapper
     * can do when record access is unavailable).
     *
     * Public, with {@see addressIsBlocked()}, so
     * {@see \SugarCraft\Code\Tools\BuiltIn\WebFetch}, {@see \SugarCraft\Code\Tools\BuiltIn\WebSearch}
     * and its hosted backends guard their endpoints with the SAME resolver and
     * range list rather than a second copy: the copy WebSearch used to carry
     * answered v4 only and lacked even `0.0.0.0/8` (audit F-W3), which is the
     * drift two lists invite.
     *
     * @internal shared by the built-in web surfaces, not a public API
     *
     * @return list<string>
     */
    public static function resolveViaSystemDns(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }
        if ($addresses !== []) {
            return $addresses;
        }

        $v4 = gethostbynamel($host);
        if (is_array($v4) && $v4 !== []) {
            return $v4;
        }

        $single = gethostbyname($host);
        return $single === $host ? [] : [$single];
    }

    /**
     * The default blocklist decision. Both the address as answered and its
     * canonical IPv4 form are compared against every CIDR, and either match
     * refuses it: the canonical pass lets the v4 list govern every v4-in-v6
     * spelling, while the raw pass keeps the v6 prefix entries (NAT64, 6to4)
     * live — canonicalising alone would turn `64:ff9b::/96` into a dead entry,
     * since no address could still be in it by the time the list is read.
     *
     * @internal shared by the built-in web surfaces; see {@see resolveViaSystemDns()}
     */
    public static function addressIsBlocked(string $address): bool
    {
        $forms = array_unique([$address, self::canonicalAddress($address)]);

        foreach ($forms as $form) {
            foreach (self::BLOCKED_IP_RANGES as $cidr) {
                if (self::ipInCidr($form, $cidr)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Maps every IPv6 shape that carries a dialable IPv4 destination back to
     * IPv4 text, so the v4 ranges decide what the packet actually reaches:
     *
     *  - `::ffff:a.b.c.d` (mapped) and the deprecated `::a.b.c.d`
     *    (compatibility, which `::1` and `::` also fall into) — Linux dials
     *    these as the embedded v4 itself;
     *  - `64:ff9b::a.b.c.d` (NAT64 well-known prefix, last 4 bytes) — a
     *    NAT64 gateway re-dials the embedded v4;
     *  - `2002:aabb:ccdd::/48` (6to4, bytes 2-5) — a 6to4 relay delivers to
     *    `aa.bb.cc.dd`.
     *
     * Without this the family-length compare in {@see ipInCidr()} wave-passes
     * `[::ffff:127.0.0.1]` or `[64:ff9b::a9fe:a9fe]` past every v4 range.
     * The NAT64 and 6to4 prefixes are ALSO refused whole in the range list;
     * the unwrap keeps the v4 verdict authoritative should either prefix
     * entry ever be narrowed. The local-use NAT64 /48 and Teredo are not
     * unwrapped — their embedding is operator-defined or obfuscated — and
     * are refused by prefix alone.
     */
    private static function canonicalAddress(string $address): string
    {
        $binary = @inet_pton($address);
        if ($binary === false || strlen($binary) !== 16) {
            return $address;
        }

        $embedded = null;
        $head = substr($binary, 10, 2);
        if (substr($binary, 0, 10) === str_repeat("\x00", 10)
            && ($head === "\xff\xff" || $head === "\x00\x00")
        ) {
            $embedded = substr($binary, 12, 4);
        } elseif (substr($binary, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\x00", 8)) {
            $embedded = substr($binary, 12, 4);
        } elseif (substr($binary, 0, 2) === "\x20\x02") {
            $embedded = substr($binary, 2, 4);
        }

        if ($embedded !== null) {
            $v4 = inet_ntop($embedded);
            if ($v4 !== false) {
                return $v4;
            }
        }

        return $address;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // address-family mismatch (v4 vs v6)
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = ~(0xFF >> $remainder) & 0xFF;

        return ((ord($ipBin[$fullBytes]) ^ ord($subnetBin[$fullBytes])) & $mask) === 0;
    }

    /**
     * The guarded transfer: dial the VALIDATED literal, never the hostname,
     * so the name cannot answer one way to the guard and another way to the
     * socket (DNS rebinding). The `Host:` header keeps virtual hosting and
     * redirects working against the literal (omitting the port unless it is
     * the scheme default), and `ssl.peer_name` keeps SNI and certificate
     * verification bound to the name that passed the guard rather than the
     * bracketed literal in the dial URL.
     *
     * The body is read through a bounded loop so a hostile server cannot make
     * the wrapper buffer more than one chunk past the wire bound before it
     * stops. `complete` is false only when that bound stopped the read with
     * the stream still open — the one case where the body's size is unknown.
     *
     * Instruction sources carry no URL credentials ({@see fetch()} refuses
     * them), so — unlike WebFetch's own transfer — there is no credentials arm
     * here to keep in sync.
     *
     * @param array<string,mixed> $parsed parse_url() of the hop URL
     *
     * @return array{0: string, 1: list<string>, 2: bool}|null body, response headers, complete; null on failure
     */
    private static function transferPinned(
        string $scheme,
        string $host,
        array $parsed,
        string $address,
        int $budgetSeconds,
        int $maxBytes,
    ): ?array {
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int) ($parsed['port'] ?? $defaultPort);
        $authority = (str_contains($address, ':') ? "[$address]" : $address) . ':' . $port;

        $origin = ($parsed['path'] ?? '') === '' ? '/' : (string) $parsed['path'];
        if (isset($parsed['query'])) {
            $origin .= '?' . $parsed['query'];
        }

        $hostHeader = $port === $defaultPort ? $host : "$host:$port";

        $context = stream_context_create([
            'http' => [
                'timeout' => $budgetSeconds,
                'ignore_errors' => true,
                'max_redirects' => 0,
                'header' => "Host: $hostHeader\r\n",
            ],
            'ssl' => [
                'peer_name' => $host,
            ],
        ]);

        $stream = @fopen("$scheme://$authority$origin", 'rb', false, $context);
        if ($stream === false) {
            return null;
        }

        // fopen() populates the magic response-header variable in this scope.
        $headers = $http_response_header;

        $body = '';
        while (!feof($stream) && strlen($body) <= $maxBytes) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        $complete = strlen($body) <= $maxBytes || feof($stream);
        fclose($stream);

        return [$body, array_values($headers), $complete];
    }

    /**
     * @param list<string> $headers
     */
    private static function statusCode(array $headers): ?int
    {
        if (isset($headers[0]) && preg_match('/^HTTP\/\d+(?:\.\d+)?\s+(\d{3})/', $headers[0], $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * The first `Location:` value, or null when there is none or it is empty.
     *
     * @param list<string> $headers
     */
    private static function locationHeader(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower($header), 'location:')) {
                $location = trim(substr($header, 9));

                return $location === '' ? null : $location;
            }
        }

        return null;
    }
}
