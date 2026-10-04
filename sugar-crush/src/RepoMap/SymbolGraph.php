<?php

declare(strict_types=1);

namespace SugarCraft\Crush\RepoMap;

/**
 * The file graph a repo map ranks (roadmap 5.5-3): one weighted edge from each
 * file that REFERENCES an identifier to each file that DEFINES it, built from
 * the {@see Tag}s {@see PhpSymbolExtractor} (through {@see TagCache}) yields.
 *
 * It mirrors Aider's `RepoMap.get_ranked_tags()` (repomap.py), weights and
 * all, because those multipliers are the tuned part of that design:
 *
 *  - edge weight is `mul × sqrt(references)`, so a file that names a symbol
 *    forty times is a stronger link than one that names it once, but not
 *    forty times stronger;
 *  - `mul` starts at 1 and is ×10 when the user MENTIONED the identifier,
 *    ×10 for a SPECIFIC name (snake_case, kebab-case or camelCase, no shorter
 *    than {@see SPECIFIC_NAME_MIN_LENGTH} — `handlePermissionKey` says more
 *    than `get`), ×0.1 for a `_`-prefixed private name, ×0.1 when more than
 *    five files define it (a generic name like `render` or `__construct`
 *    links everything to everything), and ×50 on an edge whose REFERENCER is
 *    a focus file — the files the user is working in pull rank toward what
 *    they use;
 *  - a definition nothing references gets a 0.1 self-edge, so it still ranks;
 *  - personalisation is `100 / files` per focus or mentioned file, plus the
 *    same again when a path component or basename (with or without its
 *    extension) equals a mentioned identifier.
 *
 * Focus files are left OUT of {@see rankedEntries()}: their text is already
 * in front of the model, so the map spends its budget on the rest.
 *
 * Exact-case identifiers, as Aider: references are lexical, and folding PHP's
 * case-insensitive class and function names would merge distinct properties
 * and constants that only differ in case.
 *
 * AGGREGATED, NOT ENUMERATED (roadmap 5.5-5). An edge's weight depends on the
 * referencer and the identifier — never on which definer it points at — so
 * the graph is held per identifier: its multiplier, its definers, and each
 * referencer's count. Aider's multigraph has one edge per (referencer,
 * definer, identifier); on sugar-crush's own tree that is ~570,000 edges
 * (~300 MB as PHP arrays) for ~340,000 distinct file pairs. {@see weights()}
 * sums straight into the pairs and {@see rankedEntries()} credits each
 * (definer, identifier) from one per-identifier sum, walking the same
 * sequence the enumerated edges would have, so every float — and therefore
 * every rank and every tie — is the one the edge list produced.
 * {@see edges()} still enumerates them on demand.
 */
final class SymbolGraph
{
    /** Aider's multipliers, named so a retune is one edit with a reason. */
    public const MENTIONED_MULTIPLIER = 10.0;

    public const SPECIFIC_NAME_MULTIPLIER = 10.0;

    public const SPECIFIC_NAME_MIN_LENGTH = 8;

    public const PRIVATE_NAME_MULTIPLIER = 0.1;

    public const GENERIC_NAME_MULTIPLIER = 0.1;

    /** More definers than this makes a name generic. */
    public const GENERIC_DEFINER_COUNT = 5;

    public const FOCUS_REFERENCER_MULTIPLIER = 50.0;

    public const UNREFERENCED_SELF_WEIGHT = 0.1;

    /** The personalisation mass shared across every file. */
    public const PERSONALIZATION_MASS = 100.0;

    /**
     * @param list<string>                                         $files      every file in the map, sorted
     * @param array<string, array{definers: list<string>, referencers: array<string, float>}> $idents
     *        ident => its sorted definers and each referencer's edge weight
     *        (in referencer order); no referencers means the self-edges of an
     *        unreferenced definition
     * @param array<string, float>                                 $personalization
     * @param array<string, array<string, list<Tag>>>              $definitions file => ident => definition tags
     * @param array<string, true>                                  $focus
     */
    private function __construct(
        private readonly array $files,
        private readonly array $idents,
        private readonly array $personalization,
        private readonly array $definitions,
        private readonly array $focus,
    ) {
    }

    /**
     * @param array<string, list<Tag>> $tagsByFile      root-relative path => that file's tags
     * @param list<string>             $focusFiles      files the user is working in (Aider's chat files)
     * @param list<string>             $mentionedFiles  files the user named
     * @param list<string>             $mentionedIdents identifiers the user named
     */
    public static function new(
        array $tagsByFile,
        array $focusFiles = [],
        array $mentionedFiles = [],
        array $mentionedIdents = [],
    ): self {
        $definitionsByFile = [];
        $referencesByFile = [];
        foreach ($tagsByFile as $file => $tags) {
            $file = (string) $file;
            $definitionsByFile[$file] = [];
            $referencesByFile[$file] = [];
            foreach ($tags as $tag) {
                if ($tag->isDefinition()) {
                    $definitionsByFile[$file][] = $tag;
                } else {
                    $referencesByFile[$file][$tag->name] = ($referencesByFile[$file][$tag->name] ?? 0) + 1;
                }
            }
        }

        return self::fromSummaries($definitionsByFile, $referencesByFile, $focusFiles, $mentionedFiles, $mentionedIdents);
    }

    /**
     * The graph from per-file summaries — each file's definition tags and its
     * reference COUNTS per identifier ({@see TagCache::summary()}) — so a
     * caller mapping a large checkout never holds a reference tag as an
     * object. {@see new()} is this, after counting.
     *
     * @param array<string, list<Tag>>           $definitionsByFile root-relative path => its definition tags
     * @param array<string, array<string, int>>  $referencesByFile  root-relative path => ident => times referenced
     * @param list<string>                       $focusFiles
     * @param list<string>                       $mentionedFiles
     * @param list<string>                       $mentionedIdents
     */
    public static function fromSummaries(
        array $definitionsByFile,
        array $referencesByFile,
        array $focusFiles = [],
        array $mentionedFiles = [],
        array $mentionedIdents = [],
    ): self {
        $files = \array_map('strval', \array_keys($definitionsByFile + $referencesByFile));
        \sort($files, \SORT_STRING);
        $focus = \array_fill_keys(\array_map('strval', $focusFiles), true);
        $mentionedFileSet = \array_fill_keys(\array_map('strval', $mentionedFiles), true);
        $mentioned = \array_fill_keys(\array_map('strval', $mentionedIdents), true);

        // ident => [file => true], ident => [file => count], file => ident => tags.
        // Built in the order new() always walked the tags — files in input
        // order, definitions before references within a file is immaterial
        // since the two maps are separate — so ident order is unchanged.
        $definers = [];
        $references = [];
        $definitions = [];
        foreach ($definitionsByFile as $file => $tags) {
            $file = (string) $file;
            foreach ($tags as $tag) {
                $definers[$tag->name][$file] = true;
                $definitions[$file][$tag->name][] = $tag;
            }
        }
        foreach ($referencesByFile as $file => $counts) {
            $file = (string) $file;
            foreach ($counts as $ident => $count) {
                $references[(string) $ident][$file] = ($references[(string) $ident][$file] ?? 0) + (int) $count;
            }
        }

        // Aider: a tag source that yields definitions but no references at all
        // falls back to treating each definition as its own reference, so the
        // graph still has something to rank.
        if ($references === []) {
            foreach ($definers as $ident => $set) {
                foreach (\array_keys($set) as $file) {
                    $references[$ident][(string) $file] = 1;
                }
            }
        }

        $idents = [];
        foreach ($definers as $ident => $set) {
            $ident = (string) $ident;
            $definerFiles = \array_map('strval', \array_keys($set));
            \sort($definerFiles, \SORT_STRING);

            if (!isset($references[$ident])) {
                $idents[$ident] = ['definers' => $definerFiles, 'referencers' => []];

                continue;
            }

            $mul = self::identMultiplier($ident, isset($mentioned[$ident]), \count($definerFiles));
            $referencers = $references[$ident];
            \ksort($referencers, \SORT_STRING);
            $weights = [];
            foreach ($referencers as $referencer => $count) {
                $referencer = (string) $referencer;
                $useMul = isset($focus[$referencer]) ? $mul * self::FOCUS_REFERENCER_MULTIPLIER : $mul;
                $weights[$referencer] = $useMul * \sqrt((float) $count);
            }
            $idents[$ident] = ['definers' => $definerFiles, 'referencers' => $weights];
        }

        return new self(
            $files,
            $idents,
            self::personalizationFor($files, $focus, $mentionedFileSet, $mentioned),
            $definitions,
            $focus,
        );
    }

    /**
     * Aider's per-identifier multiplier, before the focus-referencer factor.
     */
    public static function identMultiplier(string $ident, bool $mentioned, int $definerCount): float
    {
        $mul = 1.0;
        if ($mentioned) {
            $mul *= self::MENTIONED_MULTIPLIER;
        }
        if (self::isSpecificName($ident) && \strlen($ident) >= self::SPECIFIC_NAME_MIN_LENGTH) {
            $mul *= self::SPECIFIC_NAME_MULTIPLIER;
        }
        if (\str_starts_with($ident, '_')) {
            $mul *= self::PRIVATE_NAME_MULTIPLIER;
        }
        if ($definerCount > self::GENERIC_DEFINER_COUNT) {
            $mul *= self::GENERIC_NAME_MULTIPLIER;
        }

        return $mul;
    }

    /** snake_case, kebab-case or camelCase — Aider's `is_snake or is_kebab or is_camel`. */
    private static function isSpecificName(string $ident): bool
    {
        $hasAlpha = \preg_match('/[A-Za-z]/', $ident) === 1;
        $snake = \str_contains($ident, '_') && $hasAlpha;
        $kebab = \str_contains($ident, '-') && $hasAlpha;
        $camel = \preg_match('/[A-Z]/', $ident) === 1 && \preg_match('/[a-z]/', $ident) === 1;

        return $snake || $kebab || $camel;
    }

    /**
     * @param list<string>        $files
     * @param array<string, true> $focus
     * @param array<string, true> $mentionedFiles
     * @param array<string, true> $mentionedIdents
     * @return array<string, float>
     */
    private static function personalizationFor(array $files, array $focus, array $mentionedFiles, array $mentionedIdents): array
    {
        if ($files === []) {
            return [];
        }

        $unit = self::PERSONALIZATION_MASS / \count($files);
        $personalization = [];
        foreach ($files as $file) {
            $weight = 0.0;
            if (isset($focus[$file])) {
                $weight += $unit;
            }
            if (isset($mentionedFiles[$file])) {
                $weight = \max($weight, $unit);
            }

            if ($mentionedIdents !== []) {
                $basename = \basename($file);
                $dot = \strrpos($basename, '.');
                $components = \explode('/', $file);
                $components[] = $basename;
                $components[] = $dot === false || $dot === 0 ? $basename : \substr($basename, 0, $dot);
                foreach ($components as $component) {
                    if (isset($mentionedIdents[$component])) {
                        $weight += $unit;

                        break;
                    }
                }
            }

            if ($weight > 0.0) {
                $personalization[$file] = $weight;
            }
        }

        return $personalization;
    }

    /** @return list<string> every file the graph was built from, sorted */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Every edge of Aider's multigraph, enumerated — one per (referencer,
     * definer, identifier), plus an unreferenced definition's self-edge. The
     * graph itself never holds this list (see the class docblock); it is
     * built here, in the order the ranking walks it, for a caller that wants
     * to read the edges.
     *
     * @return list<array{from: string, to: string, weight: float, ident: string}>
     */
    public function edges(): array
    {
        $edges = [];
        $this->walk(static function (string $from, string $to, float $weight, string $ident) use (&$edges): void {
            $edges[] = ['from' => $from, 'to' => $to, 'weight' => $weight, 'ident' => $ident];
        });

        return $edges;
    }

    /** @return array<string, float> */
    public function personalization(): array
    {
        return $this->personalization;
    }

    /**
     * The files that take part in at least one edge, in first-seen order — the
     * nodes Aider's graph has, since `add_edge` is what creates a node there.
     *
     * @return list<string>
     */
    public function nodes(): array
    {
        $nodes = [];
        $this->walk(static function (string $from, string $to) use (&$nodes): void {
            $nodes[$from] = true;
            $nodes[$to] = true;
        });

        return \array_map('strval', \array_keys($nodes));
    }

    /**
     * Summed edge weights, from => to => weight — the multigraph collapsed
     * the way {@see PageRank} consumes it.
     *
     * @return array<string, array<string, float>>
     */
    public function weights(): array
    {
        $weights = [];
        $this->walk(static function (string $from, string $to, float $weight) use (&$weights): void {
            $weights[$from][$to] = ($weights[$from][$to] ?? 0.0) + $weight;
        });

        return $weights;
    }

    /** @return array<string, float> file => PageRank over this graph */
    public function fileRanks(?PageRank $pageRank = null): array
    {
        // weights() is passed as a temporary, never held here: PageRank
        // normalises it in place, so the pair map exists once, not twice.
        return ($pageRank ?? PageRank::new())->rank($this->nodes(), $this->weights(), $this->personalization);
    }

    /**
     * The ranked map entries, best first: each definition tag of each
     * (definer, identifier) pair in order of the rank flowing into it, then
     * every remaining file as a bare entry (`tag` null) — ranked graph nodes
     * first, then the rest by name. Focus files never appear.
     *
     * Aider's distribution step: a file's rank is split over its out-edges in
     * proportion to their weights, and each edge's share is credited to the
     * (definer, identifier) it points at. Ties break on file then identifier,
     * both descending, as Python's `sorted(..., reverse=True)` over the same
     * tuples does — so the order is deterministic.
     *
     * @return list<array{path: string, tag: ?Tag}>
     */
    public function rankedEntries(?PageRank $pageRank = null): array
    {
        $ranks = $this->fileRanks($pageRank);

        // Each source file's total out-weight, summed edge by edge in walk
        // order, so the shares below are the enumerated graph's to the bit.
        $outWeight = [];
        $this->walk(static function (string $from, string $to, float $weight) use (&$outWeight): void {
            $outWeight[$from] = ($outWeight[$from] ?? 0.0) + $weight;
        });

        // "definer\0ident" => [definer, ident, rank]. An edge's share depends
        // on its referencer and identifier only, so each identifier's credit
        // is summed once over its referencers and handed to every definer.
        $credited = [];
        foreach ($this->idents as $ident => $node) {
            $ident = (string) $ident;
            if ($node['referencers'] === []) {
                foreach ($node['definers'] as $definer) {
                    $total = $outWeight[$definer] ?? 0.0;
                    if ($total <= 0.0) {
                        continue;
                    }
                    $key = $definer . "\0" . $ident;
                    $credited[$key] ??= [$definer, $ident, 0.0];
                    $credited[$key][2] += ($ranks[$definer] ?? 0.0) * self::UNREFERENCED_SELF_WEIGHT / $total;
                }

                continue;
            }

            foreach ($node['referencers'] as $referencer => $weight) {
                $referencer = (string) $referencer;
                $total = $outWeight[$referencer] ?? 0.0;
                if ($total <= 0.0) {
                    continue;
                }
                $share = ($ranks[$referencer] ?? 0.0) * $weight / $total;
                foreach ($node['definers'] as $definer) {
                    $key = $definer . "\0" . $ident;
                    $credited[$key] ??= [$definer, $ident, 0.0];
                    $credited[$key][2] += $share;
                }
            }
        }

        $credited = \array_values($credited);
        \usort($credited, static fn (array $a, array $b): int => [$b[2], $b[0], $b[1]] <=> [$a[2], $a[0], $a[1]]);

        $entries = [];
        $included = [];
        foreach ($credited as [$file, $ident]) {
            if (isset($this->focus[$file])) {
                continue;
            }
            foreach ($this->definitions[$file][$ident] ?? [] as $tag) {
                $entries[] = ['path' => $file, 'tag' => $tag];
                $included[$file] = true;
            }
        }

        $byRank = [];
        foreach ($ranks as $file => $rank) {
            $byRank[] = [(string) $file, $rank];
        }
        \usort($byRank, static fn (array $a, array $b): int => [$b[1], $b[0]] <=> [$a[1], $a[0]]);
        foreach ($byRank as [$file]) {
            if (!isset($included[$file]) && !isset($this->focus[$file])) {
                $entries[] = ['path' => $file, 'tag' => null];
                $included[$file] = true;
            }
        }

        foreach ($this->files as $file) {
            if (!isset($included[$file]) && !isset($this->focus[$file])) {
                $entries[] = ['path' => $file, 'tag' => null];
            }
        }

        return $entries;
    }

    /**
     * Visit every edge of the multigraph in Aider's enumeration order —
     * identifiers in first-definition order; an unreferenced one's definers'
     * self-edges; otherwise referencers in path order, each against every
     * definer in path order — without ever materialising the list.
     *
     * @param \Closure(string, string, float, string): void $visit from, to, weight, ident
     */
    private function walk(\Closure $visit): void
    {
        foreach ($this->idents as $ident => $node) {
            $ident = (string) $ident;
            if ($node['referencers'] === []) {
                foreach ($node['definers'] as $definer) {
                    $visit($definer, $definer, self::UNREFERENCED_SELF_WEIGHT, $ident);
                }

                continue;
            }
            foreach ($node['referencers'] as $referencer => $weight) {
                foreach ($node['definers'] as $definer) {
                    $visit((string) $referencer, $definer, $weight, $ident);
                }
            }
        }
    }
}
