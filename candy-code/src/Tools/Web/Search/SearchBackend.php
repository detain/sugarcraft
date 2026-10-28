<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web\Search;

/**
 * One place WebSearch queries. Chosen by the `webSearchBackend` setting
 * (user tier, default `searxng`) and built by {@see SearchBackends::make()}.
 *
 * Every implementation answers the same three questions in the same order
 * {@see \SugarCraft\Code\Tools\BuiltIn\WebSearch} uses them: is the backend
 * configured at all (an unconfigured search is a LOUD refusal naming what to
 * set, never a fallback and never an empty digest — the shape audit F-W3
 * settled); does it answer a validated query; and with what digest pieces.
 *
 * A backend owns its wire shape — URL, method, headers, and the mapping from
 * a remote payload to {@see SearchHit} lines — and refuses loudly in its own
 * "Error: …" voice on transport, status, and parse failures. It never throws
 * for remote misbehaviour: a malformed body is the endpoint's story, and the
 * model reads that story, not a stack trace.
 */
interface SearchBackend
{
    /** The `webSearchBackend` spelling that selects this backend. */
    public function name(): string;

    /**
     * False only when the backend cannot possibly answer — no endpoint (the
     * self-hosted path) or no API key (every hosted path). Checked before any
     * network call; refusal wording comes from {@see configurationRefusal()}.
     */
    public function isConfigured(): bool;

    /** The full "Error: …" tool result naming what to configure; only read when {@see isConfigured()} is false. */
    public function configurationRefusal(): string;

    /**
     * Run one search. `$safesearch` and `$timeRange` are the tool's optional
     * narrowing arguments (already cast); a backend whose service cannot
     * honour one ignores it rather than inventing an equivalent.
     * `$maxResults` sizes the request for hosts that take a count; the
     * digest-side slice still belongs to the caller.
     */
    public function search(string $query, ?int $safesearch, ?string $timeRange, int $maxResults): SearchResponse;
}
