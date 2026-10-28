<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web\Search;

/**
 * The self-hosted path: query a SearXNG instance and digest its JSON.
 *
 * This is where {@see \SugarCraft\Code\Tools\BuiltIn\WebSearch}'s network and
 * formatting code moved in C-TOOL-2.b, deliberately byte-faithful: the URL
 * shape (`{endpoint}?` + `http_build_query` of q/format/safesearch/time_range,
 * in that order), every "Error: …" refusal sentence, the resolve-once-then-
 * dial guard, the redirect refusal with its bounded echo, the 5 MB bound, the
 * JSON depth, and the digest's answers/suggestions/corrections/infoboxes/
 * unresponsive-engines blocks all answer to the byte pins in
 * `WebSearchToolTest` and `WebSearchTransportTest` — at the default
 * `webSearchBackend` = `searxng` the observable behaviour of the tool did not
 * change one character.
 *
 * THERE IS NO DEFAULT ENDPOINT (audit F-W3(b)); the endpoint arrives already
 * resolved (argument > `CANDY_CODE_SEARCH_ENDPOINT` > `webSearchEndpoint`,
 * empty counts as unset — that chain still lives in the tool's constructor,
 * where `/websearch` and Bootstrap keep reading it), and until one is set
 * every search refuses loudly via {@see configurationRefusal()} rather than
 * falling back anywhere.
 *
 * Not final, unlike its hosted siblings: it is the class with the F-W3
 * history of guard pins, and a deployment that wants to answer from a cache
 * may subclass it — but the tests never needed that seam, since a transport
 * closure is injected through {@see SearchBackends::make()}.
 */
class SearxngBackend implements SearchBackend
{
    /** Hostnames refused before ANY resolver is asked (kept from F-W3). */
    public const BLOCKED_HOSTNAMES = [
        'localhost',
        '127.0.0.1',
        '::1',
    ];

    /** Bounds the redirect target echoed in the refusal; it is remote text. */
    public const MAX_LOCATION_ECHO_BYTES = 256;

    private ?string $endpoint;

    /** @var \Closure(array, string): array{0: string|false, 1: list<string>} */
    private \Closure $transport;

    /** @var \Closure(string): list<string> */
    private \Closure $resolveAddresses;

    /** @var \Closure(string): bool */
    private \Closure $isBlockedAddress;

    /**
     * @param (callable(array, string): array{0: string|false, 1: list<string>})|null $transport
     * @param (callable(string): list<string>)|null                                     $resolveAddresses
     * @param (callable(string): bool)|null                                             $isBlockedAddress
     */
    public function __construct(
        ?string $endpoint,
        private readonly int $timeout,
        ?callable $transport = null,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
    ) {
        $this->endpoint = is_string($endpoint) && trim($endpoint) !== '' ? $endpoint : null;
        $this->transport = $transport === null
            ? static fn (array $request, string $dial): array => SearchBackends::openHttp($request, $dial)
            : \Closure::fromCallable($transport);
        $this->resolveAddresses = $resolveAddresses === null
            ? static fn (string $host): array => \SugarCraft\Code\Tools\BuiltIn\WebFetch::resolveViaSystemDns($host)
            : \Closure::fromCallable($resolveAddresses);
        $this->isBlockedAddress = $isBlockedAddress === null
            ? static fn (string $address): bool => \SugarCraft\Code\Tools\BuiltIn\WebFetch::addressIsBlocked($address)
            : \Closure::fromCallable($isBlockedAddress);
    }

    public function name(): string
    {
        return SearchBackends::BACKEND_SEARXNG;
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== null;
    }

    /**
     * The loud failure for a session with no search endpoint, worded exactly
     * as the tool worded it before backends existed. An error rather than an
     * empty digest, because "no results" would read to the model as a fact
     * about the web; and it names the variable, because the person who can
     * fix it reads this through the model's reply or `/websearch`.
     */
    public function configurationRefusal(): string
    {
        return 'Error: no search endpoint is configured, so WebSearch cannot run. Set CANDY_CODE_SEARCH_ENDPOINT '
            . '(or the webSearchEndpoint setting) to the search URL of a SearXNG instance you trust '
            . '(for example https://searx.example.org/search) and restart; there is no built-in default.';
    }

    public function search(string $query, ?int $safesearch, ?string $timeRange, int $maxResults): SearchResponse
    {
        if ($this->endpoint === null) {
            return SearchResponse::failed($this->configurationRefusal());
        }

        $params = [
            'q' => $query,
            'format' => 'json',
        ];

        if ($safesearch !== null) {
            $params['safesearch'] = $safesearch;
        }

        if ($timeRange !== null) {
            $params['time_range'] = $timeRange;
        }

        $url = $this->endpoint . '?' . http_build_query($params);

        if (!str_starts_with($this->endpoint, 'http://') && !str_starts_with($this->endpoint, 'https://')) {
            return SearchResponse::failed('Error: search endpoint must use http:// or https://');
        }

        // A host-less endpoint used to skip every address check below and go
        // straight to the fetch; there is nothing to vet, so there is nothing
        // to dial either.
        $parsedEndpoint = parse_url($this->endpoint);
        if ($parsedEndpoint === false || ($parsedEndpoint['host'] ?? '') === '') {
            return SearchResponse::failed('Error: search endpoint is not a valid URL');
        }

        $endpointHost = strtolower(trim((string) $parsedEndpoint['host'], '[]'));
        if (in_array($endpointHost, self::BLOCKED_HOSTNAMES, true)) {
            return SearchResponse::failed('Error: search endpoint cannot be localhost');
        }

        [$refusal, $dialAddress] = SearchBackends::guardedDialAddress($endpointHost, $this->resolveAddresses, $this->isBlockedAddress);
        if ($refusal !== null) {
            return SearchResponse::failed($refusal);
        }

        [$body, $responseHeaders] = ($this->transport)([
            'url' => $url,
            'method' => 'GET',
            'headers' => [],
            'body' => null,
            'timeout' => $this->timeout,
        ], (string) $dialAddress);

        if ($body !== false && strlen($body) > SearchBackends::MAX_RESPONSE_SIZE) {
            return SearchResponse::failed('Error: search response exceeds maximum size limit');
        }

        if ($body === false) {
            return SearchResponse::failed('Error: failed to connect to search endpoint. Check network connectivity.');
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
                return SearchResponse::failed("Error: HTTP {$code} — search endpoint answered with a redirect"
                    . SearchBackends::locationSuffix($responseHeaders)
                    . '. Redirects are not followed; set CANDY_CODE_SEARCH_ENDPOINT to the final URL.');
            }
            if ($code >= 400 && $code < 500) {
                return SearchResponse::failed("Error: HTTP {$code} — bad request to search endpoint. Check endpoint configuration.");
            }
            if ($code >= 500) {
                return SearchResponse::failed("Error: HTTP {$code} — search endpoint server error. Try again later.");
            }
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // The parser's own reason is diagnostic gold here (rv-php-10):
            // "Syntax error" vs "Malformed UTF-8" vs "Recursion limit reached"
            // point at a broken endpoint, a corrupt proxy, or a 512-deep
            // payload respectively. Clipped like the Workspace gateway's
            // wire errors so a runaway message cannot bloat the transcript.
            return SearchResponse::failed('Error: search endpoint returned invalid JSON response: ' . substr($e->getMessage(), 0, 300));
        }

        if (!is_array($data)) {
            // A bare JSON scalar is valid JSON and not a search response; the
            // digest must not chase array keys through a string.
            return SearchResponse::failed('Error: search endpoint returned invalid JSON response: top-level value is not an object');
        }

        return $this->digest($data);
    }

    /**
     * Split a SearXNG payload into digest pieces. Every block reproduces the
     * formatter this class arrived with — the query echo and answers above
     * the results, suggestions, corrections, infoboxes and the
     * unavailable-engines note below it — so the assembled text is byte-for-
     * byte the old tool output. Hits are mapped ONE-per-raw-entry (no
     * filtering): the old code tolerated scalar entries via `??` defaults,
     * and the "(N):" header counted the raw array.
     *
     * @param array<mixed> $data
     */
    private function digest(array $data): SearchResponse
    {
        $leading = [];

        if (isset($data['query'])) {
            $leading[] = "Search query: {$data['query']}";
        }

        if (!empty($data['answers'])) {
            $leading[] = "Direct answers:";
            foreach ($data['answers'] as $answer) {
                if (is_array($answer)) {
                    $leading[] = '  • ' . ($answer['answer'] ?? json_encode($answer));
                } elseif (is_string($answer)) {
                    $leading[] = '  • ' . $answer;
                } elseif (is_bool($answer)) {
                    $leading[] = '  • ' . ($answer ? 'true' : 'false');
                } elseif ($answer === null) {
                    $leading[] = '  • (no answer)';
                } else {
                    $leading[] = '  • ' . (is_scalar($answer) ? (string) $answer : json_encode($answer));
                }
            }
        }

        $hits = [];
        $total = 0;
        if (!empty($data['results'])) {
            $total = count($data['results']);
            foreach ($data['results'] as $result) {
                $hits[] = SearchHit::new(
                    is_array($result) ? (string) ($result['title'] ?? 'Untitled') : 'Untitled',
                    is_array($result) ? (string) ($result['url'] ?? 'No URL') : 'No URL',
                    is_array($result) ? (string) ($result['content'] ?? '') : '',
                );
            }
        }

        $trailing = [];

        if (!empty($data['suggestions'])) {
            $trailing[] = 'Suggestions: ' . implode(', ', self::flattenScalars($data['suggestions']));
        }

        if (!empty($data['corrections'])) {
            $trailing[] = 'Corrections: ' . implode(', ', self::flattenScalars($data['corrections']));
        }

        if (!empty($data['infoboxes'])) {
            $trailing[] = "Info boxes:";
            foreach ($data['infoboxes'] as $ib) {
                $label = $ib['infobox'] ?? $ib['title'] ?? 'Information';
                $trailing[] = "  • {$label}";
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
                $trailing[] = 'Note: Some search engines were unavailable: ' . implode(', ', $engines);
            }
        }

        return SearchResponse::ok($leading, $hits, $total, $trailing);
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
}
