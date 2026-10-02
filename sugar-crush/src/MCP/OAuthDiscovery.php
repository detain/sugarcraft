<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Audit MCP-6: locate an MCP server's OAuth authorization-server metadata the
 * way RFC 9728 and RFC 8414 place it — the ONE discovery path both
 * `mcp auth add` ({@see \SugarCraft\Crush\Commands\McpAuthCommand}) and
 * `sugarcrush mcp auth login` ({@see OAuthLoopbackFlow}) use, so the two can
 * no longer drift apart.
 *
 * WHY IT EXISTS. Both callers used to append the well-known suffix AFTER the
 * server URL (`https://h/mcp/.well-known/oauth-authorization-server`). Almost
 * every remote MCP server lives at a path, and both RFCs insert the
 * well-known segment BETWEEN the origin and that path, so the old URL hit the
 * MCP endpoint itself and got a 401 — login was impossible for exactly the
 * servers it exists for.
 *
 * THE ORDER, bounded and deterministic (at most six fetches, identical URLs
 * fetched once):
 *
 * 1. RFC 9728 protected-resource metadata — path-inserted
 *    (`<origin>/.well-known/oauth-protected-resource<path>`), then the
 *    origin-only form. This is how the MCP authorization spec says a client
 *    finds the authorization server, which may live on another host
 *    entirely. The first document naming `authorization_servers[0]` wins
 *    this leg, and that issuer is resolved through step 2.
 * 2. RFC 8414 authorization-server metadata for a URL — path-inserted
 *    (`<origin>/.well-known/oauth-authorization-server<path>`, only when the
 *    URL has a path), then the origin-only form, which is where servers that
 *    act as their own authorization server publish it. Applied to the
 *    step-1 issuer first, then to the server URL itself as the fallback.
 *
 * OpenID Connect `openid-configuration` forms are deliberately NOT tried: they
 * would double the worst-case request count for a failing server, and the
 * operator can now pass every endpoint positionally instead.
 *
 * A fetch that THROWS (network error, non-2xx, non-JSON) is "not found here"
 * and the next candidate is tried. A metadata document must be a JSON object
 * carrying at least one of the endpoint keys: a JSON error body such as
 * `{"error":"invalid_token"}` from a 401 is not metadata, while a document
 * that omits only `registration_endpoint` still is — the caller decides
 * whether a positional override fills the gap.
 *
 * Query and fragment are dropped (they are not part of the resource
 * identifier the well-known URL is built from) and so is a trailing slash on
 * the path. The URL the CALLER stores credentials under is untouched: the
 * bearer attaches by exact `.mcp.json` URL match, so normalising happens only
 * inside the discovery URLs built here.
 */
final class OAuthDiscovery
{
    /** RFC 9728 §3 well-known suffix. */
    public const PROTECTED_RESOURCE_SUFFIX = '/.well-known/oauth-protected-resource';

    /** RFC 8414 §3 well-known suffix. */
    public const AUTHORIZATION_SERVER_SUFFIX = '/.well-known/oauth-authorization-server';

    /** The keys whose presence makes a JSON object authorization-server metadata. */
    private const ENDPOINT_KEYS = ['authorization_endpoint', 'token_endpoint', 'registration_endpoint'];

    private readonly \Closure $fetcher;

    /**
     * @param callable(string): array<mixed> $fetcher fetch-and-decode one URL;
     *        throws when nothing usable is there. Production passes
     *        {@see \SugarCraft\Crush\Commands\McpAuthCommand::fetchOAuthMetadata()};
     *        tests pass a table so the suite never touches the network.
     */
    public function __construct(callable $fetcher)
    {
        $this->fetcher = \Closure::fromCallable($fetcher);
    }

    /**
     * The authorization-server metadata document for `$serverUrl`, or `[]`
     * when no candidate yielded one.
     *
     * @return array<string, mixed>
     */
    public function discover(string $serverUrl): array
    {
        $tried = [];

        foreach (self::protectedResourceCandidates($serverUrl) as $url) {
            $document = $this->fetchObject($url, $tried);
            $issuer = $document['authorization_servers'][0] ?? null;
            if (!is_string($issuer) || $issuer === '') {
                continue;
            }

            foreach (self::authorizationServerCandidates($issuer) as $metadataUrl) {
                $metadata = $this->fetchObject($metadataUrl, $tried);
                if (self::isAuthorizationServerMetadata($metadata)) {
                    return $metadata;
                }
            }

            // The resource named its authorization server; a second
            // protected-resource document would only name it again.
            break;
        }

        foreach (self::authorizationServerCandidates($serverUrl) as $metadataUrl) {
            $metadata = $this->fetchObject($metadataUrl, $tried);
            if (self::isAuthorizationServerMetadata($metadata)) {
                return $metadata;
            }
        }

        return [];
    }

    /**
     * RFC 9728 §3.1 candidates for a resource URL: path-inserted, then
     * origin-only. Empty for anything that is not an absolute http(s) URL.
     *
     * @return list<string>
     */
    public static function protectedResourceCandidates(string $resourceUrl): array
    {
        return self::wellKnownCandidates($resourceUrl, self::PROTECTED_RESOURCE_SUFFIX);
    }

    /**
     * RFC 8414 §3.1 candidates for an issuer (or server) URL: path-inserted,
     * then origin-only. Empty for anything that is not an absolute http(s) URL.
     *
     * @return list<string>
     */
    public static function authorizationServerCandidates(string $issuerUrl): array
    {
        return self::wellKnownCandidates($issuerUrl, self::AUTHORIZATION_SERVER_SUFFIX);
    }

    /**
     * @return list<string>
     */
    private static function wellKnownCandidates(string $url, string $suffix): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return [];
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return [];
        }

        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');

        $candidates = [];
        if ($path !== '') {
            $candidates[] = $origin . $suffix . $path;
        }
        $candidates[] = $origin . $suffix;

        return $candidates;
    }

    /**
     * One fetch, at most once per URL per discovery. Anything that is not a
     * non-empty JSON object counts as absent.
     *
     * @param array<string, true> $tried
     * @return array<string, mixed>
     */
    private function fetchObject(string $url, array &$tried): array
    {
        if (isset($tried[$url])) {
            return [];
        }
        $tried[$url] = true;

        try {
            $document = ($this->fetcher)($url);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($document) || $document === [] || array_is_list($document)) {
            return [];
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function isAuthorizationServerMetadata(array $document): bool
    {
        foreach (self::ENDPOINT_KEYS as $key) {
            if (isset($document[$key]) && is_string($document[$key]) && $document[$key] !== '') {
                return true;
            }
        }

        return false;
    }
}
