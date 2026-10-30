<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web\Search;

use SugarCraft\Code\Tools\BuiltIn\WebFetch;

/**
 * The search-backends module: pick a backend, dial it.
 *
 * {@see make()} maps the `webSearchBackend` setting to an instance. A null or
 * empty name means "unchanged behaviour", which IS searxng; an unknown
 * spelling reaches here only from a hand-built caller (the settings layer
 * validates it as an enum first) and is refused loudly, never degraded.
 *
 * The rest of the class is the ONE copy of the HTTP plumbing every backend
 * shares: {@see guardedDialAddress()} vets a host once through the injected
 * resolver and blocklist (production uses WebFetch's, audit F-W3), and
 * {@see openHttp()} performs the vetted dial — redirects off, body bounded,
 * name kept for `Host:` and SNI. Both arrived here as a byte-faithful move
 * out of {@see \SugarCraft\Code\Tools\BuiltIn\WebSearch}; a test replaces
 * them by injecting a transport closure, never by subclassing.
 *
 * Hosted backends dial FIXED https hosts and take no user URL — the endpoint
 * is a constant per class, so the SSRF surface is the resolver question only
 * (the API host itself resolving to a private address is still refused by the
 * same guard, same shape as the self-hosted path).
 */
final class SearchBackends
{
    public const BACKEND_SEARXNG = 'searxng';

    public const BACKEND_BRAVE = 'brave';

    public const BACKEND_TAVILY = 'tavily';

    public const BACKEND_EXA = 'exa';

    /**
     * C-TOOL-2.c: the server-side choice. It is NOT an HTTP backend — the
     * search runs provider-side inside one Anthropic Messages reply (see
     * {@see \SugarCraft\Code\Providers\AnthropicProvider}), so this factory
     * builds a refusal sentinel for it: a client-side WebSearch call under
     * this spelling has nothing to dial, and says so by name.
     */
    public const BACKEND_ANTHROPIC = 'anthropic';

    /** The accepted `webSearchBackend` spellings, in menu order. */
    public const NAMES = [
        self::BACKEND_SEARXNG,
        self::BACKEND_BRAVE,
        self::BACKEND_TAVILY,
        self::BACKEND_EXA,
        self::BACKEND_ANTHROPIC,
    ];

    /** Bytes one search response may carry before the read is abandoned. */
    public const MAX_RESPONSE_SIZE = 5 * 1024 * 1024; // 5MB

    private const READ_CHUNK_BYTES = 65536;

    /**
     * Build the backend `$name` asks for.
     *
     * `$endpoint` matters only to the self-hosted path (there is no default,
     * by the F-W3(b) ruling); the hosted backends ignore it and dial their
     * fixed service host, reading their API key from the environment.
     *
     * The three trailing seams mirror {@see WebFetch}'s and WebSearch's:
     * production passes none and gets the system resolver and the full
     * blocklist; tests inject a fake transport so no socket opens, plus fake
     * resolver/blocklist pairs so the guard path itself stays drivable.
     *
     * @param (callable(array{url: string, method: string, headers: list<string>, body: ?string, timeout: int}, string): array{0: string|false, 1: list<string>})|null $transport
     * @param (callable(string): list<string>)|null                                                                                                                            $resolveAddresses
     * @param (callable(string): bool)|null                                                                                                                                    $isBlockedAddress
     */
    public static function make(
        ?string $name,
        ?string $endpoint,
        int $timeout,
        ?callable $transport = null,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
    ): SearchBackend {
        $send = $transport === null
            ? static fn (array $request, string $dial): array => self::openHttp($request, $dial)
            : \Closure::fromCallable($transport);
        $resolve = $resolveAddresses === null
            ? static fn (string $host): array => WebFetch::resolveViaSystemDns($host)
            : \Closure::fromCallable($resolveAddresses);
        $blocked = $isBlockedAddress === null
            ? static fn (string $address): bool => WebFetch::addressIsBlocked($address)
            : \Closure::fromCallable($isBlockedAddress);

        return match ($name) {
            null, '', self::BACKEND_SEARXNG => new SearxngBackend($endpoint, $timeout, $send, $resolve, $blocked),
            self::BACKEND_BRAVE => new BraveBackend($timeout, transport: $send, resolveAddresses: $resolve, isBlockedAddress: $blocked),
            self::BACKEND_TAVILY => new TavilyBackend($timeout, transport: $send, resolveAddresses: $resolve, isBlockedAddress: $blocked),
            self::BACKEND_EXA => new ExaBackend($timeout, transport: $send, resolveAddresses: $resolve, isBlockedAddress: $blocked),
            // C-TOOL-2.c: the server-side choice has no client dial at all —
            // an anonymous SearchBackend (no new PSR-4 type for a typeless
            // role) that refuses by name. The Anthropic arm never reaches
            // this object for searching: its request body swaps the local
            // WebSearch definition for the provider's `web_search` server
            // tool, so the model here has no client call to make; every
            // OTHER provider's call lands on this refusal, which is the
            // named reason the design asks for.
            self::BACKEND_ANTHROPIC => new class implements SearchBackend {
                public function name(): string
                {
                    return SearchBackends::BACKEND_ANTHROPIC;
                }

                public function isConfigured(): bool
                {
                    return false;
                }

                public function configurationRefusal(): string
                {
                    return 'Error: the `anthropic` web search backend runs server-side inside the '
                        . 'direct Anthropic Messages provider\'s own reply, not as a client search — '
                        . 'pick an HTTP backend (set webSearchBackend to searxng, brave, tavily or exa) '
                        . 'for any other provider.';
                }

                public function search(string $query, ?int $safesearch, ?string $timeRange, int $maxResults): SearchResponse
                {
                    return SearchResponse::failed($this->configurationRefusal());
                }
            },
            default => throw new \InvalidArgumentException(
                'unknown web search backend ' . $name . '; expected one of: ' . implode(', ', self::NAMES)
            ),
        };
    }

    /**
     * Resolve the host once and refuse it if ANY answer is blocked, else
     * return the first answer to dial. The check this replaced asked
     * `gethostbyname()` for one v4 answer, so an AAAA-only name at `::1`, or
     * a record set mixing public and private, passed; it also read an
     * unresolvable name as "not blocked" and let the fetch resolve again.
     *
     * @param \Closure(string): list<string> $resolve
     * @param \Closure(string): bool         $blocked
     *
     * @return array{0: ?string, 1: ?string} refusal message, else dial address
     */
    public static function guardedDialAddress(string $host, \Closure $resolve, \Closure $blocked): array
    {
        $dial = null;
        foreach ($resolve($host) as $address) {
            // The resolver seam must yield IP literals: this string becomes
            // the dial authority, so anything else is refused, not trusted.
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                return ["Error: could not resolve search endpoint host: $host", null];
            }
            if ($blocked($address)) {
                return ['Error: search endpoint cannot resolve to a private or link-local address', null];
            }
            $dial ??= $address;
        }

        return $dial === null
            ? ["Error: could not resolve search endpoint host: $host", null]
            : [null, $dial];
    }

    /**
     * The default transport: dial the vetted literal, never the hostname —
     * the same resolve-once-then-dial shape as {@see WebFetch} (audit F-W3):
     * handing the name to the stream layer resolved it a second time, so a
     * TTL≈0 record could answer public to the guard and private to the dial.
     * The name stays where the peer needs it, in `Host:` and in
     * `ssl.peer_name` for SNI and certificate checks. Redirects are switched
     * off at the wrapper (it otherwise follows 20, unchecked); the caller
     * turns the 3xx it now sees into a refusal. The body is read in chunks
     * and abandoned once past MAX_RESPONSE_SIZE, so a hostile endpoint costs
     * at most one chunk over the cap in memory rather than whatever it
     * chooses to stream.
     *
     * `$http_response_header` is the magic local the http wrapper writes into
     * the calling scope, so it must be read HERE and returned rather than
     * left for the caller to find — moving the call moved the variable.
     *
     * @param array{url: string, method: string, headers: list<string>, body: ?string, timeout: int} $request
     *
     * @return array{0: string|false, 1: list<string>} body (false on connect
     *                                                 failure) and raw
     *                                                 response header lines
     */
    public static function openHttp(array $request, string $dialAddress): array
    {
        $parsed = parse_url($request['url']);
        if ($parsed === false || ($parsed['host'] ?? '') === '') {
            return [false, []];
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? 'http'));
        $host = strtolower(trim((string) $parsed['host'], '[]'));
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int) ($parsed['port'] ?? $defaultPort);
        $authority = (str_contains($dialAddress, ':') ? "[$dialAddress]" : $dialAddress) . ':' . $port;

        $origin = ($parsed['path'] ?? '') === '' ? '/' : (string) $parsed['path'];
        if (isset($parsed['query'])) {
            $origin .= '?' . $parsed['query'];
        }
        $credentials = isset($parsed['user'])
            ? rawurlencode((string) $parsed['user']) . ':' . rawurlencode((string) ($parsed['pass'] ?? '')) . '@'
            : '';
        $hostHeader = $port === $defaultPort ? $host : "$host:$port";

        $http = [
            'method' => $request['method'],
            'timeout' => $request['timeout'],
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => "Host: $hostHeader\r\n"
                . implode('', array_map(static fn (string $h): string => "$h\r\n", $request['headers'])),
        ];
        if ($request['body'] !== null) {
            $http['content'] = $request['body'];
        }

        $context = stream_context_create([
            'http' => $http,
            'ssl' => [
                'peer_name' => $host,
            ],
        ]);

        $stream = @fopen("$scheme://$credentials$authority$origin", 'rb', false, $context);
        if ($stream === false) {
            return [false, []];
        }

        $headers = $http_response_header ?? [];

        $body = '';
        while (!feof($stream) && strlen($body) <= self::MAX_RESPONSE_SIZE) {
            $chunk = fread($stream, self::READ_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        fclose($stream);

        return [$body, array_values(is_array($headers) ? $headers : [])];
    }

    /**
     * The hosted-path twin of {@see guardedDialAddress()}: a fixed service
     * host, the same resolve-once-then-dial discipline, refusal wording that
     * names a SERVICE rather than a configured endpoint (nobody configured
     * this host — the build did).
     *
     * @param \Closure(string): list<string> $resolve
     * @param \Closure(string): bool         $blocked
     *
     * @return array{0: ?string, 1: ?string} refusal message, else dial address
     */
    public static function serviceDial(string $host, \Closure $resolve, \Closure $blocked): array
    {
        $dial = null;
        foreach ($resolve($host) as $address) {
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                return ["Error: could not resolve search service host: $host", null];
            }
            if ($blocked($address)) {
                return ['Error: search service cannot resolve to a private or link-local address', null];
            }
            $dial ??= $address;
        }

        return $dial === null
            ? ["Error: could not resolve search service host: $host", null]
            : [null, $dial];
    }

    /**
     * The shared failure read of a hosted response: connect failure, the 5 MB
     * bound, then the status classes, in the order WebSearch has always
     * applied them (size BEFORE connect-false, because a partial read is a
     * different story from no story). Null means the response is answerable.
     *
     * @param list<string> $headers
     */
    public static function hostedFailure(string|false $body, array $headers, string $service): ?string
    {
        if ($body !== false && strlen($body) > self::MAX_RESPONSE_SIZE) {
            return 'Error: search response exceeds maximum size limit';
        }

        if ($body === false) {
            return "Error: failed to connect to the $service service. Check network connectivity.";
        }

        if (isset($headers[0]) && preg_match('/^HTTP\/\d+(?:\.\d+)?\s+(\d{3})/', $headers[0], $m)) {
            $code = (int) $m[1];
            if ($code >= 300 && $code < 400) {
                // Fixed hosts over https should never redirect a query; if a
                // proxy in front of the socket answers with one, following it
                // would re-open the exact F-W3 hole the self-hosted path
                // refused for a decade.
                return "Error: HTTP {$code} — the $service service answered with a redirect"
                    . self::locationSuffix($headers)
                    . '. Redirects are not followed.';
            }
            if ($code === 401 || $code === 403) {
                return "Error: HTTP {$code} — the $service service refused the API key. "
                    . 'Check the key the `webSearchBackend` in use reads from the environment.';
            }
            if ($code >= 400 && $code < 500) {
                return "Error: HTTP {$code} — bad request to the $service service. Check the query and configuration.";
            }
            if ($code >= 500) {
                return "Error: HTTP {$code} — the $service service errored. Try again later.";
            }
        }

        return null;
    }

    /**
     * Decode a hosted JSON body: the array on success, the refusal sentence
     * otherwise (same parser bound and clipping as the SearXNG path).
     *
     * @return array<mixed>|string
     */
    public static function decode(string $body): array|string
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return 'Error: search response was not valid JSON: ' . substr($e->getMessage(), 0, 300);
        }

        return is_array($data) ? $data : 'Error: search response was not a JSON object';
    }

    /**
     * " to <Location>" for a redirect refusal, so the user can see what to
     * configure. The value is remote text headed for the model, so control
     * bytes are stripped and its length is bounded.
     *
     * @param list<string> $headers
     */
    public static function locationSuffix(array $headers): string
    {
        foreach ($headers as $header) {
            if (!str_starts_with(strtolower($header), 'location:')) {
                continue;
            }
            $location = (string) preg_replace('/[\x00-\x1f\x7f]/', '', trim(substr($header, 9)));
            if ($location === '') {
                return '';
            }
            if (strlen($location) > SearxngBackend::MAX_LOCATION_ECHO_BYTES) {
                $location = mb_strcut($location, 0, SearxngBackend::MAX_LOCATION_ECHO_BYTES) . '...';
            }

            return " to {$location}";
        }

        return '';
    }
}
