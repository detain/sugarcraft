<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web\Search;

/**
 * Exa's neural search API — a fixed https host, chosen by setting
 * `webSearchBackend` to `exa` and putting the key in `EXA_API_KEY`. POSTed
 * JSON, `x-api-key`; the query is the only user data that leaves, and the
 * service host is a constant (audit F-W3 by construction).
 *
 * Exa answers neither safesearch nor a day/month/year window in WebSearch's
 * vocabulary, so both are ignored rather than approximated — the interface
 * contract a tolerant mapper honours, pinned by the shapes fixture. Its
 * per-result `text` arrives long; the caller's 200-character snippet cut is
 * the single bound for every backend, so it rides through unmapped.
 */
final class ExaBackend implements SearchBackend
{
    public const HOST = 'api.exa.ai';

    public const NAME_LABEL = 'exa';

    private const PATH = '/search';

    private ?string $apiKey;

    /** @var \Closure(array, string): array{0: string|false, 1: list<string>} */
    private \Closure $transport;

    /** @var \Closure(string): list<string> */
    private \Closure $resolveAddresses;

    /** @var \Closure(string): bool */
    private \Closure $isBlockedAddress;

    /**
     * @param string|null $apiKey null reads `EXA_API_KEY` from the environment; '' never does (tests pass '' to force the refusal).
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
        $read = $apiKey ?? getenv('EXA_API_KEY');
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
        return SearchBackends::BACKEND_EXA;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null;
    }

    public function configurationRefusal(): string
    {
        return 'Error: the WebSearch backend `exa` needs the EXA_API_KEY environment variable, '
            . 'which is not set; set it and restart, or set webSearchBackend back to searxng.';
    }

    public function search(string $query, ?int $safesearch, ?string $timeRange, int $maxResults): SearchResponse
    {
        if ($this->apiKey === null) {
            return SearchResponse::failed($this->configurationRefusal());
        }

        [$refusal, $dialAddress] = SearchBackends::serviceDial(self::HOST, $this->resolveAddresses, $this->isBlockedAddress);
        if ($refusal !== null) {
            return SearchResponse::failed($refusal);
        }

        [$body, $responseHeaders] = ($this->transport)([
            'url' => 'https://' . self::HOST . self::PATH,
            'method' => 'POST',
            'headers' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-api-key: ' . $this->apiKey,
            ],
            'body' => (string) json_encode([
                'query' => $query,
                'numResults' => $maxResults,
            ]),
            'timeout' => $this->timeout,
        ], (string) $dialAddress);

        if ($refusal = SearchBackends::hostedFailure($body, (array) $responseHeaders, self::NAME_LABEL)) {
            return SearchResponse::failed($refusal);
        }

        $data = SearchBackends::decode((string) $body);
        if (!is_array($data)) {
            return SearchResponse::failed($data);
        }

        $rows = is_array($data['results'] ?? null) ? $data['results'] : [];

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = SearchHit::new(
                is_array($row) ? (string) ($row['title'] ?? 'Untitled') : 'Untitled',
                is_array($row) ? (string) ($row['url'] ?? 'No URL') : 'No URL',
                is_array($row) ? (string) ($row['text'] ?? '') : '',
            );
        }

        return SearchResponse::ok([], $hits, count($rows), []);
    }
}
