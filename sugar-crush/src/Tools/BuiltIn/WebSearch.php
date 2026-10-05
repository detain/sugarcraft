<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolLimits;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

/**
 * Deliberately NOT final, unlike its sibling built-ins: the class performs its
 * own HTTP fetch with no injected client, so subclassing is the only seam the
 * tests have to stub a response. Marking it final breaks every WebSearch test
 * with ClassIsFinalException. Injecting a client would be the tidier fix and
 * would let this join the others as final.
 *
 * That seam is {@see fetch()} — the single method that touches the network.
 * It was extracted because the suite used to exercise the success path by
 * really querying the configured SearXNG endpoint, which made three tests a
 * function of one host's uptime: they went red in CI the first day the box
 * was unreachable, and cost a 30s connect timeout apiece on every other day.
 */
#[BuiltInTool(name: 'WebSearch', permission: ToolPermissionClass::Ask, position: 8, gloss: 'against `$SUGARCRUSH_SEARCH_ENDPOINT`')]
class WebSearch implements Tool, ParallelSafe, BuildsFromCatalog
{
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        '127.0.0.1',
        '::1',
    ];

    private const MAX_RESPONSE_SIZE = 5 * 1024 * 1024; // 5MB

    private const READ_CHUNK_BYTES = 65536;

    /** Bounds the redirect target echoed in the refusal; it is remote text. */
    private const MAX_LOCATION_ECHO_BYTES = 256;

    /** Seconds one search request may take — the default of `webSearchTimeoutSeconds`. */
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    /** Results one digest lists — the default of `webSearchMaxResults`. */
    public const DEFAULT_MAX_RESULTS = 10;

    private int $timeout;

    private int $maxResults;

    /**
     * Null when nothing configured one — and then every search fails loudly
     * (see {@see unconfiguredRefusal()}) rather than falling back anywhere.
     */
    private ?string $endpoint;

    /** @var \Closure(string): list<string> */
    private \Closure $resolveAddresses;

    /** @var \Closure(string): bool */
    private \Closure $isBlockedAddress;

    /**
     * THERE IS NO DEFAULT ENDPOINT (audit F-W3(b)). The constructor argument
     * wins, then `SUGARCRUSH_SEARCH_ENDPOINT`; an empty value counts as unset.
     * Until one is set the tool still constructs — Bootstrap, Chat and
     * `/websearch` build it unconditionally, and the model is better told why
     * a search failed than shown no tool — but every call is an error naming
     * the variable. The shipped default used to be
     * `http://skynet2.interserver.net:8080/search`: a private host, over
     * CLEARTEXT http, so every model-composed query (which routinely quotes
     * code, file names and error text from the user's repository) crossed the
     * network unencrypted to a third party, unprompted under the default
     * `bypass-permissions`. Switching it to https would still have sent every
     * user's queries to one maintainer's box; the honest default for a
     * third-party data flow is none.
     *
     * $timeout and $maxResults, when not passed, are the `webSearchTimeoutSeconds`
     * and `webSearchMaxResults` settings (roadmap N-P4c), else
     * {@see DEFAULT_TIMEOUT_SECONDS} / {@see DEFAULT_MAX_RESULTS}. Read HERE,
     * at construction, rather than rebound per turn like the other tools'
     * bounds, because `/websearch` builds its own instance and must honour
     * the same keys — so they apply from the next launch.
     *
     * The two trailing seams mirror {@see WebFetch}'s: production passes
     * neither and gets WebFetch's system resolver and its full range list
     * (audit F-W3 — this class used to keep its own shorter copy of both);
     * tests pass a fake resolver so no lookup leaves the machine, and a
     * blocklist that admits only a loopback fixture.
     *
     * @param (callable(string): list<string>)|null $resolveAddresses
     * @param (callable(string): bool)|null         $isBlockedAddress
     */
    public function __construct(
        ?string $endpoint = null,
        ?int $timeout = null,
        ?int $maxResults = null,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
    ) {
        $limits = $timeout === null || $maxResults === null ? ToolLimits::current() : null;
        $this->timeout = $timeout ?? $limits?->int(ToolLimits::WEB_SEARCH_TIMEOUT_KEY) ?? self::DEFAULT_TIMEOUT_SECONDS;
        $this->maxResults = $maxResults ?? $limits?->int(ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY) ?? self::DEFAULT_MAX_RESULTS;
        $configured = $endpoint ?? getenv('SUGARCRUSH_SEARCH_ENDPOINT');
        $this->endpoint = is_string($configured) && trim($configured) !== '' ? $configured : null;
        $this->resolveAddresses = $resolveAddresses === null
            ? static fn (string $host): array => WebFetch::resolveViaSystemDns($host)
            : \Closure::fromCallable($resolveAddresses);
        $this->isBlockedAddress = $isBlockedAddress === null
            ? static fn (string $address): bool => WebFetch::addressIsBlocked($address)
            : \Closure::fromCallable($isBlockedAddress);
    }

    /**
     * The loud failure for a session with no search endpoint. An error rather
     * than an empty digest, because "no results" would read to the model as a
     * fact about the web; and it names the variable, because the person who
     * can fix it reads this through the model's reply or `/websearch`.
     *
     * @param array<string, mixed> $args
     */
    private function unconfiguredRefusal(array $args): ToolResult
    {
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: 'Error: no search endpoint is configured, so WebSearch cannot run. Set SUGARCRUSH_SEARCH_ENDPOINT '
                . 'to the search URL of a SearXNG instance you trust '
                . '(for example https://searx.example.org/search) and restart; there is no built-in default.',
            isError: true,
        );
    }

    /**
     * Same reasoning as {@see WebFetch::isParallelSafe()}: a search round-trip
     * is seconds of waiting, writes nothing locally, and strands no
     * session-scoped state in a forked child.
     *
     * True for THIS class only. Every other built-in that answers this
     * question is `final`, so "the class said yes" and "the instance is the
     * code that said yes" are the same statement; this one is subclassable
     * (see the class docblock), and a plain `return true` would have made a
     * subclass — including every PHPUnit mock — inherit the promise without
     * ever making it. That is fail-OPEN, and it inverts
     * {@see ParallelSafe}'s own principle that saying nothing means barrier.
     * A subclass that IS safe re-states it by overriding this method, which is
     * the same act of declaring that every other tool performs by
     * implementing the interface in the first place.
     */
    public function isParallelSafe(): bool
    {
        return static::class === self::class;
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self();
    }

    public function name(): string
    {
        return 'WebSearch';
    }

    public function description(): string
    {
        return 'Search the web by sending a query to a configurable SearXNG endpoint and '
            . 'return a formatted text digest of the response. The digest can include direct '
            . 'answers, the top results with each title, URL and a short snippet, plus '
            . 'suggestions, corrections, infoboxes, and a note listing any engines that did '
            . 'not answer. It returns those snippets only, never the full contents of the '
            . 'pages it lists, and it errors when no endpoint is configured, on an empty or over-long query, a failed '
            . 'connection, an endpoint that replies with a client or server error status, '
            . 'or an endpoint that answers with a redirect, which it never follows. '
            . 'Optional parameters narrow the search by safesearch level, which takes 0, 1, '
            . 'or 2, and by a time_range of day, month, or year.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'The search query (e.g. "PHP 8.3 release date")'],
                'safesearch' => ['type' => 'integer', 'description' => 'Safe search filter: 0 for none, 1 for moderate, 2 for strict', 'minimum' => 0, 'maximum' => 2],
                'time_range' => ['type' => 'string', 'description' => 'Time range limit: day, month, or year', 'enum' => ['day', 'month', 'year']],
                'description' => ['type' => 'string', 'description' => 'Clear, concise 5-10 word description in active voice of what this search is for (e.g. "Find PHP 8.3 release notes", not "searches the web")'],
            ],
            'required' => ['query', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $query = (string) ($args['query'] ?? '');

        if ($query === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: query cannot be empty',
                isError: true,
            );
        }

        if (mb_strlen($query) > 2000) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: query exceeds maximum length of 2000 characters',
                isError: true,
            );
        }

        $params = [
            'q' => $query,
            'format' => 'json',
        ];

        if (isset($args['safesearch'])) {
            $params['safesearch'] = (int) $args['safesearch'];
        }

        if (isset($args['time_range'])) {
            $params['time_range'] = $args['time_range'];
        }

        if ($this->endpoint === null) {
            return $this->unconfiguredRefusal($args);
        }

        $url = $this->endpoint . '?' . http_build_query($params);

        if (!str_starts_with($this->endpoint, 'http://') && !str_starts_with($this->endpoint, 'https://')) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: search endpoint must use http:// or https://',
                isError: true,
            );
        }

        // A host-less endpoint used to skip every address check below and go
        // straight to the fetch; there is nothing to vet, so there is nothing
        // to dial either.
        $parsedEndpoint = parse_url($this->endpoint);
        if ($parsedEndpoint === false || ($parsedEndpoint['host'] ?? '') === '') {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: search endpoint is not a valid URL',
                isError: true,
            );
        }

        $endpointHost = strtolower(trim((string) $parsedEndpoint['host'], '[]'));
        if (in_array($endpointHost, self::BLOCKED_HOSTNAMES, true)) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: search endpoint cannot be localhost',
                isError: true,
            );
        }

        [$refusal, $dialAddress] = $this->guardedDialAddress($endpointHost);
        if ($refusal !== null) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $refusal,
                isError: true,
            );
        }

        $start = hrtime(true);

        [$body, $responseHeaders] = $this->fetch($url, (string) $dialAddress);

        if ($body !== false && strlen($body) > self::MAX_RESPONSE_SIZE) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: search response exceeds maximum size limit',
                isError: true,
            );
        }

        if ($body === false) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: failed to connect to search endpoint. Check network connectivity.',
                isError: true,
            );
        }

        if (isset($responseHeaders[0]) && preg_match('/^HTTP\/\d+(?:\.\d+)?\s+(\d{3})/', $responseHeaders[0], $m)) {
            $code = (int) $m[1];
            // Redirects are refused, never followed (audit F-W3). PHP's
            // wrapper used to chase up to 20 of them with no address re-check,
            // so an endpoint reached over plain HTTP — where anyone on the path
            // can forge the 302 — could aim the tool at 169.254.169.254 or any
            // internal host. A search API has no reason to redirect a query;
            // the fix for one that does is configuring its final URL.
            if ($code >= 300 && $code < 400) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: "Error: HTTP {$code} — search endpoint answered with a redirect"
                        . $this->locationSuffix($responseHeaders)
                        . '. Redirects are not followed; set SUGARCRUSH_SEARCH_ENDPOINT to the final URL.',
                    isError: true,
                );
            }
            if ($code >= 400 && $code < 500) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: "Error: HTTP {$code} — bad request to search endpoint. Check endpoint configuration.",
                    isError: true,
                );
            }
            if ($code >= 500) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: "Error: HTTP {$code} — search endpoint server error. Try again later.",
                    isError: true,
                );
            }
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'Error: search endpoint returned invalid JSON response',
                isError: true,
            );
        }

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $this->formatResults($data),
            isError: false,
            durationMs: $durationMs,
        );
    }

    /**
     * Perform the search request and hand back its body and status line.
     *
     * The ONLY method in the class that opens a socket, and the reason the
     * class is not final: a subclass overrides this to answer from a fixture,
     * so a test can drive the parse/format path — or simply assert that a
     * 1000-character query clears the length gate — without the configured
     * endpoint having to be up. Everything around it (query validation, the
     * SSRF host checks, status-code mapping, JSON decoding, formatting) stays
     * on the real code path.
     *
     * The socket dials $dialAddress — the literal execute() already vetted —
     * never the hostname, the same resolve-once-then-dial shape as
     * {@see WebFetch} (audit F-W3): handing the name to the stream layer
     * resolved it a second time, so a TTL≈0 record could answer public to the
     * guard and private to the dial. The name stays where the peer needs it,
     * in `Host:` and in `ssl.peer_name` for SNI and certificate checks.
     * Redirects are switched off at the wrapper (it otherwise follows 20,
     * unchecked); execute() turns the 3xx it now sees into a refusal. The
     * body is read in chunks and abandoned once past MAX_RESPONSE_SIZE, so a
     * hostile endpoint costs at most one chunk over the cap in memory rather
     * than whatever it chooses to stream.
     *
     * `$http_response_header` is the magic local the http wrapper writes into
     * the calling scope, so it must be read HERE and returned rather than left
     * for execute() to find — moving the call moved the variable.
     *
     * @return array{0: string|false, 1: list<string>} body (false on connect
     *                                                 failure) and raw
     *                                                 response header lines
     */
    protected function fetch(string $url, string $dialAddress): array
    {
        $parsed = parse_url($url);
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

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => "Host: $hostHeader\r\n",
            ],
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
     * Resolve the endpoint host once and refuse it if ANY answer is blocked,
     * else return the first answer to dial. The check this replaced asked
     * `gethostbyname()` for one v4 answer, so an AAAA-only name at `::1`, or
     * a record set mixing public and private, passed; it also read an
     * unresolvable name as "not blocked" and let the fetch resolve again.
     *
     * @return array{0: ?string, 1: ?string} refusal message, else dial address
     */
    private function guardedDialAddress(string $host): array
    {
        $dial = null;
        foreach (($this->resolveAddresses)($host) as $address) {
            // The resolver seam must yield IP literals: this string becomes
            // the dial authority, so anything else is refused, not trusted.
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                return ["Error: could not resolve search endpoint host: $host", null];
            }
            if (($this->isBlockedAddress)($address)) {
                return ['Error: search endpoint cannot resolve to a private or link-local address', null];
            }
            $dial ??= $address;
        }

        return $dial === null
            ? ["Error: could not resolve search endpoint host: $host", null]
            : [null, $dial];
    }

    /**
     * " to <Location>" for the redirect refusal, so the user can see what to
     * configure. The value is remote text headed for the model, so control
     * bytes are stripped and its length is bounded.
     *
     * @param list<string> $headers
     */
    private function locationSuffix(array $headers): string
    {
        foreach ($headers as $header) {
            if (!str_starts_with(strtolower($header), 'location:')) {
                continue;
            }
            $location = (string) preg_replace('/[\x00-\x1f\x7f]/', '', trim(substr($header, 9)));
            if ($location === '') {
                return '';
            }
            if (strlen($location) > self::MAX_LOCATION_ECHO_BYTES) {
                $location = mb_strcut($location, 0, self::MAX_LOCATION_ECHO_BYTES) . '...';
            }

            return " to {$location}";
        }

        return '';
    }

    /**
     * Keep only the scalar entries of a remote list, stringified.
     *
     * SearXNG's list-shaped fields are not guaranteed to hold strings, and
     * array_map('strval', ...) over a nested array raises "Array to string
     * conversion" and emits the literal word "Array" into the model's context.
     *
     * @param  array<mixed> $values
     * @return list<string>
     */
    private static function flattenScalars(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $out[] = (string) $value;
            }
        }

        return $out;
    }

    private function formatResults(array $data): string
    {
        $parts = [];

        if (isset($data['query'])) {
            $parts[] = "Search query: {$data['query']}";
        }

        if (!empty($data['answers'])) {
            $parts[] = "Direct answers:";
            foreach ($data['answers'] as $answer) {
                if (is_array($answer)) {
                    $parts[] = '  • ' . ($answer['answer'] ?? json_encode($answer));
                } elseif (is_string($answer)) {
                    $parts[] = '  • ' . $answer;
                } elseif (is_bool($answer)) {
                    $parts[] = '  • ' . ($answer ? 'true' : 'false');
                } elseif ($answer === null) {
                    $parts[] = '  • (no answer)';
                } else {
                    $parts[] = '  • ' . (is_scalar($answer) ? (string) $answer : json_encode($answer));
                }
            }
        }

        if (!empty($data['results'])) {
            $count = count($data['results']);
            $parts[] = "Search results ({$count}):";
            foreach (array_slice($data['results'], 0, $this->maxResults) as $i => $result) {
                $i++;
                $title = $result['title'] ?? 'Untitled';
                $url = $result['url'] ?? 'No URL';
                $content = (string) ($result['content'] ?? '');
                // mb_* because a byte-based cut lands mid-codepoint on any
                // non-ASCII snippet and puts a broken character in the transcript.
                if (mb_strlen($content) > 200) {
                    $content = mb_substr($content, 0, 200) . '...';
                }
                $parts[] = "  {$i}. {$title}";
                $parts[] = "     URL: {$url}";
                if ($content !== '') {
                    $parts[] = "     {$content}";
                }
            }
            if ($count > $this->maxResults) {
                $parts[] = "  ... and " . ($count - $this->maxResults) . " more results";
            }
        }

        if (!empty($data['suggestions'])) {
            $parts[] = 'Suggestions: ' . implode(', ', self::flattenScalars($data['suggestions']));
        }

        if (!empty($data['corrections'])) {
            $parts[] = 'Corrections: ' . implode(', ', self::flattenScalars($data['corrections']));
        }

        if (!empty($data['infoboxes'])) {
            $parts[] = "Info boxes:";
            foreach ($data['infoboxes'] as $ib) {
                $label = $ib['infobox'] ?? $ib['title'] ?? 'Information';
                $parts[] = "  • {$label}";
            }
        }

        if (!empty($data['unresponsive_engines'])) {
            // SearXNG reports these as [engine, reason] PAIRS, not strings, so a
            // bare implode() raises "Array to string conversion" and prints the
            // word "Array" to the model. Flatten each entry to "engine (reason)".
            $engines = [];
            foreach ($data['unresponsive_engines'] as $entry) {
                if (is_array($entry)) {
                    $name = (string) ($entry[0] ?? 'unknown');
                    $reason = isset($entry[1]) && is_scalar($entry[1]) ? (string) $entry[1] : '';
                    $engines[] = $reason === '' ? $name : "{$name} ({$reason})";
                } elseif (is_scalar($entry)) {
                    $engines[] = (string) $entry;
                }
            }
            if ($engines !== []) {
                $parts[] = 'Note: Some search engines were unavailable: ' . implode(', ', $engines);
            }
        }

        return count($parts) > 0 ? implode("\n", $parts) : 'No results found for the query.';
    }
}
