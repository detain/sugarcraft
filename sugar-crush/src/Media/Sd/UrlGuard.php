<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

/**
 * Origin pin for all media SD egress (plan W1.3).
 *
 * WHY THIS EXISTS INSTEAD OF WebFetch'S DIALING (mystage fact (c), W0
 * finding #3 — binding law): `src/Tools/BuiltIn/WebFetch.php` carries an SSRF
 * blocklist whose whole purpose is refusing LAN and private hosts — which is
 * EXACTLY where an SD server legitimately lives (a box on the local network).
 * An operator who configured `sd.baseUrl` already made the trust decision; the
 * only failure this guard prevents is a request drifting OFF the configured
 * origin (a hostile redirect, a bug composing URLs, a caller smuggling an
 * absolute URL through a path slot). It is deliberately NOT a blocklist: no
 * address-class screening, no DNS-rebinding heuristics, nothing imported from
 * the WebFetch/FetchTarget policy — a machine-wide test pins that absence.
 *
 * The invariant: once a base origin is configured, every URL that leaves the
 * transport — initial request or followed redirect — has the same scheme,
 * host and port as that origin, and carries no userinfo (credentials travel
 * as headers, never inside the URL, so they cannot be echoed into logs or
 * survive a redirect).
 */
final readonly class UrlGuard
{
    /** Schemes an SD server may be reached over; anything else is refused at the base. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private function __construct(
        private string $scheme,
        private string $host,
        private int $port,
        private string $pathPrefix,
    ) {
    }

    /**
     * Parse-and-freeze the configured base (Parse-don't-validate: the check
     * happens once here, every later request rides the trusted origin).
     *
     * @throws SdException for a base that is not an http(s) origin URL
     */
    public static function forBase(string $base): self
    {
        $trimmed = trim($base);

        if ($trimmed === '') {
            throw SdException::protocol('url-guard', 'base URL is empty');
        }

        $parts = parse_url($trimmed);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw SdException::protocol('url-guard', 'base URL is not an absolute http(s) origin: ' . self::redact($trimmed));
        }

        $scheme = strtolower($parts['scheme']);

        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw SdException::protocol('url-guard', 'base URL scheme must be http or https, got ' . $scheme);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw SdException::protocol(
                'url-guard',
                'base URL must not embed credentials; configure the API key separately so it travels as a header',
            );
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return new self(
            scheme: $scheme,
            host: strtolower($parts['host']),
            port: $port,
            pathPrefix: rtrim($parts['path'] ?? '', '/'),
        );
    }

    /** The pinned origin, e.g. `http://skynet2.lan:30001` (default ports elided). */
    public function origin(): string
    {
        $port = $this->isDefaultPort() ? '' : ':' . $this->port;

        return $this->scheme . '://' . $this->host . $port;
    }

    public function baseUrl(): string
    {
        return $this->origin() . $this->pathPrefix;
    }

    /**
     * Compose the final absolute URL from a SERVER-RELATIVE path. A path that
     * smuggles its own scheme, an authority (`//host`), or userinfo is a
     * caller trying to steer egress off-origin — refused, never normalized.
     */
    public function absolute(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw SdException::protocol('url-guard', 'path must be server-root-relative, got: ' . self::redact($path));
        }

        // '//' is a scheme-relative authority; a bare 'http:' style prefix or
        // embedded '@' (userinfo bait) both try to move the origin. parse_url
        // on the composed URL is the final word either way — these guards make
        // the refusal message precise.
        if (str_starts_with($path, '//') || str_contains($path, "\\") || str_contains($path, '@')) {
            throw SdException::protocol('url-guard', 'path may not carry an authority, backslash or userinfo: ' . self::redact($path));
        }

        $url = $this->baseUrl() . $path;
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw SdException::protocol('url-guard', 'composed URL does not parse: ' . self::redact($url));
        }

        $this->assertSameOrigin($url, $parts, 'request');

        return $url;
    }

    /**
     * Validate a redirect target the server handed us: relative Locations are
     * composed onto the base (and re-checked), absolute ones must already be
     * the configured origin. Off-origin → refused, full stop.
     */
    public function redirectTarget(string $location): string
    {
        $trimmed = trim($location);

        if ($trimmed === '') {
            throw SdException::protocol('url-guard', 'redirect with empty Location refused');
        }

        if (str_starts_with($trimmed, '/')) {
            // A rooted path stays inside absolute()'s authority/userinfo refusals.
            return $this->absolute($trimmed);
        }

        $parts = parse_url($trimmed);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw SdException::protocol('url-guard', 'relative redirect target is not resolvable: ' . self::redact($trimmed));
        }

        return $this->assertSameOrigin($trimmed, $parts, 'redirect');
    }

    /**
     * @param array<string, mixed> $parts  parse_url output of an absolute URL
     * @return string the same URL, once proven same-origin
     */
    private function assertSameOrigin(string $url, array $parts, string $what): string
    {
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw SdException::protocol('url-guard', $what . ' URL carries userinfo; credentials travel as headers only');
        }

        if ($scheme !== $this->scheme || $host !== $this->host || $port !== $this->port) {
            throw SdException::protocol(
                'url-guard',
                $what . ' targets ' . $scheme . '://' . $host . ':' . $port
                    . ', outside the configured origin ' . $this->origin() . ' — refused',
            );
        }

        return $url;
    }

    private function isDefaultPort(): bool
    {
        return $this->port === ($this->scheme === 'https' ? 443 : 80);
    }

    /** Keep any userinfo a caller pasted from leaking into an exception message. */
    private static function redact(string $url): string
    {
        return preg_replace('#(://)[^/@]+@#', '$1***@', $url) ?? $url;
    }
}
