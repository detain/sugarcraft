<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web\Search;

/**
 * Brave's hosted Web Search API — a fixed https host, chosen by setting
 * `webSearchBackend` to `brave` and putting the key in
 * `BRAVE_SEARCH_API_KEY`. No URL ever comes from settings or arguments on
 * this path: only the query does, and the endpoint is a constant, so the
 * F-W3 surface reduces to "does the vetted host resolve publicly".
 *
 * The mapper is deliberately tolerant of the payload's shape (comparison
 * risk row): `web.results` lines become hits, anything narrower degrades to
 * the empty digest, and the "no results" fallback stays the caller's — a
 * foreign shape must never fatal nor fabricate.
 */
final class BraveBackend implements SearchBackend
{
    public const HOST = 'api.search.brave.com';

    private const PATH = '/res/v1/web/search';

    /** Brave caps `count` at 20. */
    private const MAX_COUNT = 20;

    private ?string $apiKey;

    /** @var \Closure(array, string): array{0: string|false, 1: list<string>} */
    private \Closure $transport;

    /** @var \Closure(string): list<string> */
    private \Closure $resolveAddresses;

    /** @var \Closure(string): bool */
    private \Closure $isBlockedAddress;

    /**
     * @param string|null $apiKey null reads `BRAVE_SEARCH_API_KEY` from the environment; '' never does (tests pass '' to force the refusal).
     * @param (callable(array, string): array{0: string|false, 1: list<string>})|null $transport
     * @param (callable(string): list<string>)|null                                     $resolveAddresses
     * @param (callable(string): bool)|null                                             $isBlockedAddress
     */
    public function __construct(
        private readonly int $timeout,
        ?string $apiKey = null,
        ?callable $transport = null,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
    ) {
        $read = $apiKey ?? getenv('BRAVE_SEARCH_API_KEY');
        $this->apiKey = is_string($read) && $read !== '' ? $read : null;
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
        return SearchBackends::BACKEND_BRAVE;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null;
    }

    public function configurationRefusal(): string
    {
        return 'Error: the WebSearch backend `brave` needs the BRAVE_SEARCH_API_KEY environment variable, '
            . 'which is not set; set it and restart, or set webSearchBackend back to searxng.';
    }

    public function search(string $query, ?int $safesearch, ?string $timeRange, int $maxResults): SearchResponse
    {
        if ($this->apiKey === null) {
            return SearchResponse::failed($this->configurationRefusal());
        }

        $params = [
            'q' => $query,
            'count' => max(1, min(self::MAX_COUNT, $maxResults)),
        ];
        if ($safesearch !== null) {
            $params['safesearch'] = match (true) {
                $safesearch >= 2 => 'strict',
                $safesearch <= 0 => 'off',
                default => 'moderate',
            };
        }
        if ($timeRange !== null) {
            $params['freshness'] = match ($timeRange) {
                'day' => 'pd',
                'month' => 'pm',
                'year' => 'py',
                default => null,
            } ?? $timeRange; // an unknown spelling is passed through; Brave answers or ignores it
        }

        [$refusal, $dialAddress] = SearchBackends::serviceDial(self::HOST, $this->resolveAddresses, $this->isBlockedAddress);
        if ($refusal !== null) {
            return SearchResponse::failed($refusal);
        }

        [$body, $responseHeaders] = ($this->transport)([
            'url' => 'https://' . self::HOST . self::PATH . '?' . http_build_query($params),
            'method' => 'GET',
            'headers' => [
                'Accept: application/json',
                'X-Subscription-Token: ' . $this->apiKey,
            ],
            'body' => null,
            'timeout' => $this->timeout,
        ], (string) $dialAddress);

        if ($refusal = SearchBackends::hostedFailure($body, (array) $responseHeaders, self::NAME_LABEL)) {
            return SearchResponse::failed($refusal);
        }

        $data = SearchBackends::decode((string) $body);
        if (!is_array($data)) {
            return SearchResponse::failed($data);
        }

        // Brave nests the list under `web.results`; the tolerant read is that
        // anything not shaped like that carries no results, and the caller's
        // empty-digest fallback says so — never a fatal, never a guess.
        $rows = is_array($data['web'] ?? null) && is_array($data['web']['results'] ?? null)
            ? $data['web']['results']
            : [];

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = SearchHit::new(
                is_array($row) ? (string) ($row['title'] ?? 'Untitled') : 'Untitled',
                is_array($row) ? (string) ($row['url'] ?? 'No URL') : 'No URL',
                is_array($row) ? (string) ($row['description'] ?? '') : '',
            );
        }

        return SearchResponse::ok([], $hits, count($rows), []);
    }

    /** The service name in refusals: Brave's own spelling. */
    public const NAME_LABEL = 'brave search';
}
