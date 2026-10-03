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
     * @param list<array{from: string, to: string, weight: float, ident: string}> $edges
     * @param array<string, float>                                 $personalization
     * @param array<string, array<string, list<Tag>>>              $definitions file => ident => definition tags
     * @param array<string, true>                                  $focus
     */
    private function __construct(
        private readonly array $files,
        private readonly array $edges,
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
        $files = \array_map('strval', \array_keys($tagsByFile));
        \sort($files, \SORT_STRING);
        $focus = \array_fill_keys(\array_map('strval', $focusFiles), true);
        $mentionedFileSet = \array_fill_keys(\array_map('strval', $mentionedFiles), true);
        $mentioned = \array_fill_keys(\array_map('strval', $mentionedIdents), true);

        // ident => [file => true], ident => [file => count], file => ident => tags.
        $definers = [];
        $references = [];
        $definitions = [];
        foreach ($tagsByFile as $file => $tags) {
            $file = (string) $file;
            foreach ($tags as $tag) {
                if ($tag->isDefinition()) {
                    $definers[$tag->name][$file] = true;
                    $definitions[$file][$tag->name][] = $tag;
                } else {
                    $references[$tag->name][$file] = ($references[$tag->name][$file] ?? 0) + 1;
                }
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

        $edges = [];
        foreach ($definers as $ident => $set) {
            $ident = (string) $ident;
            $definerFiles = \array_map('strval', \array_keys($set));
            \sort($definerFiles, \SORT_STRING);

            if (!isset($references[$ident])) {
                foreach ($definerFiles as $definer) {
                    $edges[] = ['from' => $definer, 'to' => $definer, 'weight' => self::UNREFERENCED_SELF_WEIGHT, 'ident' => $ident];
                }

                continue;
            }

            $mul = self::identMultiplier($ident, isset($mentioned[$ident]), \count($definerFiles));
            $referencers = $references[$ident];
            \ksort($referencers, \SORT_STRING);
            foreach ($referencers as $referencer => $count) {
                $referencer = (string) $referencer;
                $useMul = isset($focus[$referencer]) ? $mul * self::FOCUS_REFERENCER_MULTIPLIER : $mul;
                $weight = $useMul * \sqrt((float) $count);
                foreach ($definerFiles as $definer) {
                    $edges[] = ['from' => $referencer, 'to' => $definer, 'weight' => $weight, 'ident' => $ident];
                }
            }
        }

        return new self(
            $files,
            $edges,
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

    /** @return list<array{from: string, to: string, weight: float, ident: string}> */
    public function edges(): array
    {
        return $this->edges;
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
        foreach ($this->edges as $edge) {
            $nodes[$edge['from']] = true;
            $nodes[$edge['to']] = true;
        }

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
        foreach ($this->edges as $edge) {
            $weights[$edge['from']][$edge['to']] = ($weights[$edge['from']][$edge['to']] ?? 0.0) + $edge['weight'];
        }

        return $weights;
    }

    /** @return array<string, float> file => PageRank over this graph */
    public function fileRanks(?PageRank $pageRank = null): array
    {
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

        $outWeight = [];
        foreach ($this->edges as $edge) {
            $outWeight[$edge['from']] = ($outWeight[$edge['from']] ?? 0.0) + $edge['weight'];
        }

        // "definer\0ident" => [definer, ident, rank]
        $credited = [];
        foreach ($this->edges as $edge) {
            $total = $outWeight[$edge['from']];
            if ($total <= 0.0) {
                continue;
            }
            $key = $edge['to'] . "\0" . $edge['ident'];
            $credited[$key] ??= [$edge['to'], $edge['ident'], 0.0];
            $credited[$key][2] += ($ranks[$edge['from']] ?? 0.0) * $edge['weight'] / $total;
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
}
