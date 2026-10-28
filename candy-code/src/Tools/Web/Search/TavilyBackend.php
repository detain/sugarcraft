<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web\Search;

/**
 * Tavily's hosted search API — a fixed https host, chosen by setting
 * `webSearchBackend` to `tavily` and putting the key in `TAVILY_API_KEY`.
 * POSTed JSON, `Authorization: Bearer`; the query is the only user data that
 * leaves, and the service host is a constant (audit F-W3 by construction).
 *
 * Tavily has no safesearch dial; `time_range` maps to its `days` window
 * (day→1, month→30, year→365) and an unmapped spelling is dropped rather
 * than invented. A returned `answer` rides the digest's existing
 * "Direct answers:" block so the shape of a WebSearch result is the same on
 * every backend.
 */
final class TavilyBackend implements SearchBackend
{
    public const HOST = 'api.tavily.com';

    public const NAME_LABEL = 'tavily';

    private const PATH = '/search';

    private ?string $apiKey;

    /** @var \Closure(array, string): array{0: string|false, 1: list<string>} */
    private \Closure $transport;

    /** @var \Closure(string): list<string> */
    private \Closure $resolveAddresses;

    /** @var \Closure(string): bool */
    private \Closure $isBlockedAddress;

    /**
     * @param string|null $apiKey null reads `TAVILY_API_KEY` from the environment; '' never does (tests pass '' to force the refusal).
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
        $read = $apiKey ?? getenv('TAVILY_API_KEY');
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
        return SearchBackends::BACKEND_TAVILY;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null;
    }

    public function configurationRefusal(): string
    {
        return 'Error: the WebSearch backend `tavily` needs the TAVILY_API_KEY environment variable, '
            . 'which is not set; set it and restart, or set webSearchBackend back to searxng.';
    }

    public function search(string $query, ?int $safesearch, ?string $timeRange, int $maxResults): SearchResponse
    {
        if ($this->apiKey === null) {
            return SearchResponse::failed($this->configurationRefusal());
        }

        $body = [
            'query' => $query,
            'max_results' => $maxResults,
            'include_answer' => true,
        ];
        if ($timeRange !== null) {
            $days = match ($timeRange) {
                'day' => 1,
                'month' => 30,
                'year' => 365,
                default => null,
            };
            if ($days !== null) {
                $body['days'] = $days;
            }
        }

        [$refusal, $dialAddress] = SearchBackends::serviceDial(self::HOST, $this->resolveAddresses, $this->isBlockedAddress);
        if ($refusal !== null) {
            return SearchResponse::failed($refusal);
        }

        [$responseBody, $responseHeaders] = ($this->transport)([
            'url' => 'https://' . self::HOST . self::PATH,
            'method' => 'POST',
            'headers' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            'body' => (string) json_encode($body),
            'timeout' => $this->timeout,
        ], (string) $dialAddress);

        if ($refusal = SearchBackends::hostedFailure($responseBody, (array) $responseHeaders, self::NAME_LABEL)) {
            return SearchResponse::failed($refusal);
        }

        $data = SearchBackends::decode((string) $responseBody);
        if (!is_array($data)) {
            return SearchResponse::failed($data);
        }

        $rows = is_array($data['results'] ?? null) ? $data['results'] : [];

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = SearchHit::new(
                is_array($row) ? (string) ($row['title'] ?? 'Untitled') : 'Untitled',
                is_array($row) ? (string) ($row['url'] ?? 'No URL') : 'No URL',
                is_array($row) ? (string) ($row['content'] ?? '') : '',
            );
        }

        $leading = [];
        $answer = $data['answer'] ?? null;
        if (is_string($answer) && trim($answer) !== '') {
            $leading[] = 'Direct answers:';
            $leading[] = '  • ' . $answer;
        }

        return SearchResponse::ok($leading, $hits, count($rows), []);
    }
}
