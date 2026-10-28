<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolLimits;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Web\Search\SearchBackend;
use SugarCraft\Code\Tools\Web\Search\SearchBackends;
use SugarCraft\Code\Tools\Web\Search\SearchResponse;

/**
 * Deliberately NOT final, unlike its sibling built-ins: {@see isParallelSafe()}
 * answers `static::class === self::class`, and the suite's loopback-fixture
 * harnesses subclass this tool to drive it. The network seam, though, is no
 * longer the subclass hook it used to be — the old `fetch()` override — it is
 * the transport closure the constructor accepts and hands to
 * {@see SearchBackends::make()}, so a test stubs one HTTP call without
 * reaching through the class hierarchy, and every other step (query
 * validation, the SSRF host checks, status mapping, JSON decode, formatting)
 * stays on the real code path.
 *
 * The tool now owns only the query contract and the digest assembly. Where a
 * search actually goes is decided by `webSearchBackend` (user tier, default
 * `searxng`): the self-hosted SearXNG path is {@see SearchBackends}
 * mapping that name to the moved, byte-faithful old behaviour, and the
 * hosted names (brave, tavily, exa) dial fixed https service hosts with an
 * API key read from the environment — no user-supplied URL is ever involved
 * on those paths.
 */
#[BuiltInTool(name: 'WebSearch', permission: ToolPermissionClass::Ask, position: 8, gloss: 'against `$CANDY_CODE_SEARCH_ENDPOINT`')]
class WebSearch implements Tool, ParallelSafe, BuildsFromCatalog
{
    /** Seconds one search request may take — the default of `webSearchTimeoutSeconds`. */
    public const DEFAULT_TIMEOUT_SECONDS = 30;

    /** Results one digest lists — the default of `webSearchMaxResults`. */
    public const DEFAULT_MAX_RESULTS = 10;

    private int $timeout;

    private int $maxResults;

    /**
     * The resolved self-host endpoint, or null when nothing configured one.
     * Only the `searxng` backend consults it; the hosted backends dial fixed
     * service hosts and read their keys from the environment instead.
     */
    private ?string $endpoint;

    /** The backend `webSearchBackend` selects; the tool performs no wire work itself. */
    private SearchBackend $backend;

    /**
     * THERE IS NO DEFAULT ENDPOINT (audit F-W3(b)). The constructor argument
     * wins, then `CANDY_CODE_SEARCH_ENDPOINT`, then the `webSearchEndpoint`
     * setting (roadmap N-P4e: user tier only, since it decides where every
     * model-composed query is sent); an empty value counts as unset, and a
     * setting that is not an http(s) URL is ignored rather than dialled.
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
     * The backend name is read the same way (roadmap C-TOOL-2): the `backend`
     * argument wins, then the `webSearchBackend` setting, defaulting to
     * `searxng` so behaviour at the default is this tool's old one. The
     * hosted backends' keys arrive from the environment at dial time, by
     * design — they are secrets, not settings.
     *
     * $timeout and $maxResults, when not passed, are the `webSearchTimeoutSeconds`
     * and `webSearchMaxResults` settings (roadmap N-P4c), else
     * {@see DEFAULT_TIMEOUT_SECONDS} / {@see DEFAULT_MAX_RESULTS}. Read HERE,
     * at construction, rather than rebound per turn like the other tools'
     * bounds, because `/websearch` builds its own instance and must honour
     * the same keys — so they apply from the next launch.
     *
     * The three trailing seams mirror {@see WebFetch}'s: production passes
     * none and gets WebFetch's system resolver, its full range list (audit
     * F-W3 — this class used to keep its own shorter copy of both) and the
     * default stream transport; tests pass a fake transport so no socket
     * opens, plus a fake resolver so no lookup leaves the machine, and a
     * blocklist that admits only a loopback fixture.
     *
     * @param (callable(string): list<string>)|null $resolveAddresses
     * @param (callable(string): bool)|null         $isBlockedAddress
     * @param (callable(array{url: string, method: string, headers: list<string>, body: ?string, timeout: int}, string): array{0: string|false, 1: list<string>})|null $transport
     */
    public function __construct(
        ?string $endpoint = null,
        ?int $timeout = null,
        ?int $maxResults = null,
        ?callable $resolveAddresses = null,
        ?callable $isBlockedAddress = null,
        ?callable $transport = null,
        ?string $backend = null,
    ) {
        $limits = $endpoint === null || $timeout === null || $maxResults === null || $backend === null
            ? ToolLimits::current()
            : null;
        $this->timeout = $timeout ?? $limits?->int(ToolLimits::WEB_SEARCH_TIMEOUT_KEY) ?? self::DEFAULT_TIMEOUT_SECONDS;
        $this->maxResults = $maxResults ?? $limits?->int(ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY) ?? self::DEFAULT_MAX_RESULTS;
        $configured = $endpoint ?? getenv('CANDY_CODE_SEARCH_ENDPOINT');
        if (!is_string($configured) || ($endpoint === null && trim($configured) === '')) {
            $configured = $limits?->string(ToolLimits::WEB_SEARCH_ENDPOINT_KEY);
        }
        $this->endpoint = is_string($configured) && trim($configured) !== '' ? $configured : null;
        $chosen = $backend ?? $limits?->string(ToolLimits::WEB_SEARCH_BACKEND_KEY);
        $this->backend = SearchBackends::make(
            \is_string($chosen) && $chosen !== '' ? $chosen : null,
            $this->endpoint,
            $this->timeout,
            $transport,
            $resolveAddresses,
            $isBlockedAddress,
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

        // The unconfigured search is refused HERE, before anything is
        // composed, in the chosen backend's own voice naming what to set —
        // the loud-failure shape audit F-W3 settled, unchanged for the
        // self-hosted path and extended to the hosted ones (the missing key).
        if (!$this->backend->isConfigured()) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $this->backend->configurationRefusal(),
                isError: true,
            );
        }

        $safesearch = isset($args['safesearch']) ? (int) $args['safesearch'] : null;
        $timeRange = isset($args['time_range']) ? (string) $args['time_range'] : null;

        $start = hrtime(true);
        $response = $this->backend->search($query, $safesearch, $timeRange, $this->maxResults);

        if ($response->error !== null) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: $response->error,
                isError: true,
            );
        }

        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $this->digest($response),
            isError: false,
            durationMs: $durationMs,
        );
    }

    /**
     * Assemble one digest from a backend's pieces. The formatting is the
     * shape every WebSearch result has carried since the tool existed — the
     * "(N):" header counts raw results, the list is sliced to
     * `webSearchMaxResults`, snippets are cut at 200 characters, and an empty
     * response is the sentence "No results found for the query.", never an
     * empty string. It lives here, and nowhere in a backend, so one format
     * serves every backend.
     */
    private function digest(SearchResponse $response): string
    {
        $parts = $response->leading;

        if ($response->hits !== []) {
            $parts[] = "Search results ({$response->total}):";
            $shown = array_slice($response->hits, 0, $this->maxResults);
            foreach ($shown as $i => $hit) {
                $i++;
                $content = $hit->content;
                // mb_* because a byte-based cut lands mid-codepoint on any
                // non-ASCII snippet and puts a broken character in the transcript.
                if (mb_strlen($content) > 200) {
                    $content = mb_substr($content, 0, 200) . '...';
                }
                $parts[] = "  {$i}. {$hit->title}";
                $parts[] = "     URL: {$hit->url}";
                if ($content !== '') {
                    $parts[] = "     {$content}";
                }
            }
            if ($response->total > count($shown)) {
                $parts[] = '  ... and ' . ($response->total - count($shown)) . ' more results';
            }
        }

        foreach ($response->trailing as $line) {
            $parts[] = $line;
        }

        return count($parts) > 0 ? implode("\n", $parts) : 'No results found for the query.';
    }
}
