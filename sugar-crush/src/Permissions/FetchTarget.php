<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * What a `WebFetch` URL would reach and what it would carry there, read the
 * way {@see \SugarCraft\Crush\Tools\BuiltIn\WebFetch::execute()} reads it.
 *
 * Two policy surfaces ask about a fetch URL, and both must see the URL the
 * tool will dial rather than a second interpretation of it:
 *
 * - {@see PermissionRule}'s `WebFetch(domain:…)` patterns (audit F-P6), which
 *   match {@see $host};
 * - {@see SafetyClassifier}'s `auto` mode (audit F-P3(b)), which treats a URL
 *   that carries data — a query string or userinfo — as an
 *   `external-endpoint` call.
 *
 * SAME PARSER AS THE TOOL, on purpose: the literal `http://`/`https://` prefix
 * test, then `parse_url()`, then a lowercased, bracket-trimmed host. A gate
 * that parsed differently from the tool could be shown one host while the tool
 * dials another — `https://github.com@evil.example/` is `evil.example` to
 * both here, which is the point. A URL the tool would refuse as invalid is
 * {@see fromUrl()}`=== null`, and each caller decides what an unparseable URL
 * means in its own direction (a restrictive rule fires, a grant does not).
 *
 * HONEST LIMIT: this judges the FIRST hop. WebFetch follows up to three
 * redirects, re-checking each target's address but not any permission rule,
 * so `Allow WebFetch(domain:example.com)` also reaches wherever example.com
 * redirects to. Granting a domain is granting its redirects.
 */
final class FetchTarget
{
    private function __construct(
        /** Lowercased, bracket-trimmed, with a trailing root-label dot removed. */
        public readonly string $host,
        /** A non-empty query string is present — data the server receives. */
        public readonly bool $carriesQuery,
        /** `user[:pass]@` is present — sent as credentials, i.e. data too. */
        public readonly bool $carriesUserInfo,
    ) {}

    /**
     * Parse a model-supplied `url` argument, or null when WebFetch itself
     * would refuse it before dialling (not a string, empty, not http(s), no
     * host).
     */
    public static function fromUrl(mixed $url): ?self
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return null;
        }

        $parsed = parse_url($url);
        if ($parsed === false || ($parsed['host'] ?? '') === '') {
            return null;
        }

        // `github.com.` is the fully-qualified spelling of `github.com` and
        // resolves to the same host, so a rule about one is about the other.
        $host = rtrim(strtolower(trim((string) $parsed['host'], '[]')), '.');
        if ($host === '') {
            return null;
        }

        return new self(
            host: $host,
            carriesQuery: ($parsed['query'] ?? '') !== '',
            carriesUserInfo: isset($parsed['user']) || isset($parsed['pass']),
        );
    }

    /**
     * Does {@see $host} match a `domain:` pattern's value?
     *
     * The pattern is an `fnmatch()` glob over the host, case-insensitive, so
     * `domain:github.com` is that exact host and `domain:*.github.com` is its
     * subdomains (not the apex — write both to grant both).
     *
     * `$includeSubdomains` is the class-wide asymmetry of {@see PermissionRule}
     * applied to hosts: a restrictive rule (`deny`/`ask`) about `evil.example`
     * also covers `x.evil.example`, because a deny the attacker can step around
     * with one label is not a deny; a grant covers exactly what it spells,
     * because `Allow …(domain:github.io)` silently granting every
     * `attacker.github.io` would be a grant nobody wrote.
     */
    public function matchesDomain(string $pattern, bool $includeSubdomains): bool
    {
        $pattern = rtrim(strtolower(trim($pattern)), '.');
        if ($pattern === '') {
            return false;
        }

        if (fnmatch($pattern, $this->host)) {
            return true;
        }

        return $includeSubdomains && fnmatch('*.' . $pattern, $this->host);
    }
}
