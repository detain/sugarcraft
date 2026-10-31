<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

use SugarCraft\Code\Permissions\FetchTarget;
use SugarCraft\Code\Tools\BuiltIn\WebFetch;

/**
 * Host-grant policy for the sandboxed egress proxy.
 *
 * Entries are the `sandboxAllowedDomains` shape: a host glob with an optional
 * `:port` suffix. Without a port the entry grants the web defaults (80, 443).
 * Matching reuses the exact predicate {@see FetchTarget::matchesDomain()}
 * applies on its grant side with subdomain auto-inclusion switched OFF, so a
 * bare `github.com` entry never admits `api.github.com` — subdomains are an
 * explicit `*.github.com` grant, and `*.example.com` matches one label plus
 * deeper labels alike. The proxy-side call feeds a synthetic origin URL whose
 * host is the client-supplied target, which is also what rejects IPv6 brackets
 * as never-grantable (the same rule the direct-fetch path applies).
 *
 * A granted host is resolved exactly once through the system resolver and the
 * grant is refused if ANY answer is a blocked address — an allowed name
 * pointed at 127.0.0.1 or 169.254.169.254 by hosts-file or DNS trickery
 * therefore never egresses. The vetted address is the one the caller must
 * dial: re-resolving at dial time would re-open the window between validation
 * and connection (DNS rebinding) that this method exists to close.
 */
final class EgressPolicy
{
    /**
     * Ports an entry without an explicit `:port` suffix grants.
     *
     * @var list<int>
     */
    public const DEFAULT_PORTS = [80, 443];

    /**
     * @var list<array{pattern: string, ports: list<int>}>
     */
    private readonly array $grants;

    /**
     * Host => resolved addresses, recorded once per instance.
     *
     * @var array<string, list<string>>
     */
    private array $resolved = [];

    /** @var (callable(string): list<string>) */
    private $resolver;

    /** @var (callable(string): bool) */
    private $blockedChecker;

    /**
     * @param list<string> $entries raw `sandboxAllowedDomains` entries
     * @param (callable(string): list<string>)|null $resolver system DNS stand-in for tests
     * @param (callable(string): bool)|null $blockedChecker address blocklist stand-in for tests
     */
    public function __construct(
        array $entries,
        ?callable $resolver = null,
        ?callable $blockedChecker = null,
    ) {
        $this->resolver = $resolver ?? static fn (string $host): array => self::systemResolver($host);
        $this->blockedChecker = $blockedChecker ?? static fn (string $address): bool => self::systemBlocklist($address);
        $grants = [];
        foreach ($entries as $entry) {
            $grant = self::parseEntry($entry);
            if ($grant !== null) {
                $grants[] = $grant;
            }
        }
        $this->grants = $grants;
    }

    /**
     * Vets a CONNECT/absolute-form target and returns the address to dial.
     *
     * @return string|null the vetted IP literal to dial, or null when the target is refused
     */
    public function vet(string $host, int $port): ?string
    {
        if (!$this->granted($host, $port)) {
            return null;
        }
        $answers = $this->addresses($host);
        foreach ($answers as $address) {
            if (($this->blockedChecker)($address) === true) {
                return null;
            }
        }
        // The blocklist sweeps every answer, so the first is vetted; it is the
        // exact literal dialled — never the name — closing the rebinding gap.
        return $answers[0] ?? null;
    }

    /**
     * Whether any entry grants this host:port pair (no DNS consulted).
     */
    public function granted(string $host, int $port): bool
    {
        if ($host === '' || $port < 1 || $port > 65535) {
            return false;
        }
        // The host arrives from a request line, so it is normalised with the
        // identical ladder FetchTarget applies to URL hosts, expressed as a
        // synthetic origin URL whose path carries no user-info or query to
        // reject: "http://" + host.
        $target = FetchTarget::fromUrl('http://' . $host);
        if ($target === null) {
            return false;
        }
        foreach ($this->grants as $grant) {
            if (!in_array($port, $grant['ports'], true)) {
                continue;
            }
            if ($target->matchesDomain($grant['pattern'], false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves a host once per instance and memoises the answer list.
     *
     * @return list<string>
     */
    private function addresses(string $host): array
    {
        $key = strtolower($host);
        if (!array_key_exists($key, $this->resolved)) {
            $answers = ($this->resolver)($key);
            $this->resolved[$key] = is_array($answers) ? array_values(array_map('strval', $answers)) : [];
        }
        return $this->resolved[$key];
    }

    /**
     * Parses one `sandboxAllowedDomains` entry into a grant, or null when the
     * entry grants nothing (blank pattern, non-numeric or out-of-range port).
     *
     * A colon with a non-digit tail is NOT a port — an IPv6-style or mistyped
     * suffix must never silently widen or narrow the web defaults; the whole
     * string stays the host glob, which then fails to match anything real (or
     * matches its literal bracketed form, which {@see FetchTarget} renders
     * ungrantable) rather than guessing.
     *
     * @return array{pattern: string, ports: list<int>}|null
     */
    private static function parseEntry(string $entry): ?array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }
        $pattern = $entry;
        $ports = self::DEFAULT_PORTS;
        $colon = strrpos($entry, ':');
        if ($colon !== false && $colon > 0) {
            $suffix = substr($entry, $colon + 1);
            if ($suffix !== '' && ctype_digit($suffix)) {
                $port = (int) $suffix;
                if ($port < 1 || $port > 65535) {
                    return null;
                }
                $pattern = substr($entry, 0, $colon);
                $ports = [$port];
            }
        }
        if ($pattern === '' || str_contains($pattern, '/')) {
            return null;
        }
        return ['pattern' => $pattern, 'ports' => $ports];
    }

    /**
     * The installation's system resolver — the same path WebFetch dials
     * through, so proxy grants and direct-fetch grants see identical DNS.
     *
     * @return list<string>
     */
    public static function systemResolver(string $host): array
    {
        return WebFetch::resolveViaSystemDns($host);
    }

    /**
     * The installation's SSRF blocklist predicate.
     */
    public static function systemBlocklist(string $address): bool
    {
        return WebFetch::addressIsBlocked($address);
    }
}
