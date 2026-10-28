<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Web\Search;

/**
 * What one {@see SearchBackend} call answers: either a refusal (`error`,
 * already an "Error: …" sentence, the loud-failure shape every WebSearch
 * result has carried since audit F-W3 — "no results" would read to the model
 * as a fact about the web) or the pieces of a digest.
 *
 * The digest pieces are positional on purpose. `leading` carries the lines
 * that sit above the results block (a SearXNG `query` echo, its `answers`),
 * `trailing` the lines below it (suggestions, corrections, infoboxes, the
 * unavailable-engines note, or a hosted answer line). Each backend composes
 * them from its own wire shape; {@see \SugarCraft\Code\Tools\BuiltIn\WebSearch}
 * alone owns how hits are sliced and printed, so the one digest format serves
 * every backend. `total` is the RAW result count — the number the "(N):"
 * header and the "… and M more results" tail have always reported, counted
 * before any snippet mapping, never after.
 *
 * @see SearchBackend
 */
final class SearchResponse
{
    /** @param list<string> $leading @param list<SearchHit> $hits @param list<string> $trailing */
    private function __construct(
        public readonly ?string $error,
        public readonly array $leading,
        public readonly array $hits,
        public readonly int $total,
        public readonly array $trailing,
    ) {
    }

    public static function failed(string $error): self
    {
        return new self($error, [], [], 0, []);
    }

    /**
     * @param list<string>   $leading
     * @param list<SearchHit> $hits
     * @param list<string>   $trailing
     */
    public static function ok(array $leading, array $hits, int $total, array $trailing): self
    {
        return new self(null, $leading, $hits, $total, $trailing);
    }
}
