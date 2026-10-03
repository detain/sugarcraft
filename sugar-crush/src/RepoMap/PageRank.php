<?php

declare(strict_types=1);

namespace SugarCraft\Crush\RepoMap;

/**
 * Personalised, weighted PageRank by power iteration (roadmap 5.5-3).
 *
 * It mirrors the `networkx.pagerank(G, weight="weight", personalization=p,
 * dangling=p)` call Aider's `RepoMap.get_ranked_tags()` makes, with the same
 * semantics, so the ranking matches the one that tool's users know:
 *
 *  - each node's out-weights are normalised to sum to 1 (parallel edges of a
 *    multigraph are summed first, which is what networkx's sparse conversion
 *    does), so an edge's share of its source's rank is its weight's share;
 *  - a node with no out-edges (dangling) hands its whole rank to the
 *    personalisation vector, not to every node uniformly — Aider passes the
 *    personalisation as `dangling` too, so a dead-end file still pushes rank
 *    toward the files the user is working on;
 *  - with no personalisation (or one that weighs none of the nodes) both the
 *    teleport and the dangling vectors are uniform, which is plain PageRank;
 *  - the iteration stops when the L1 change drops under `N × tolerance`.
 *
 * WHERE THIS DIFFERS: networkx raises when it does not converge within its
 * iteration cap; this returns the last iterate instead. A repo map ranks files
 * for a prompt, and a ranking that moved by more than 1e-6 per node on the
 * hundredth step is still the best ordering available — refusing to answer
 * would only turn a slightly unconverged map into no map at all.
 *
 * Pure: no I/O, no state between calls. The rank vector sums to 1.
 */
final class PageRank
{
    public const DAMPING = 0.85;

    public const MAX_ITERATIONS = 100;

    public const TOLERANCE = 1.0e-6;

    private function __construct(
        private readonly float $damping,
        private readonly int $maxIterations,
        private readonly float $tolerance,
    ) {
    }

    public static function new(
        float $damping = self::DAMPING,
        int $maxIterations = self::MAX_ITERATIONS,
        float $tolerance = self::TOLERANCE,
    ): self {
        if ($damping <= 0.0 || $damping >= 1.0) {
            throw new \InvalidArgumentException("PageRank damping must be strictly between 0 and 1; got {$damping}.");
        }
        if ($maxIterations < 1) {
            throw new \InvalidArgumentException("PageRank needs at least one iteration; got {$maxIterations}.");
        }
        if ($tolerance <= 0.0) {
            throw new \InvalidArgumentException("PageRank tolerance must be positive; got {$tolerance}.");
        }

        return new self($damping, $maxIterations, $tolerance);
    }

    public function damping(): float
    {
        return $this->damping;
    }

    /**
     * The rank of every node in $nodes.
     *
     * An edge naming a node outside $nodes is ignored, as is a personalisation
     * entry for one. A zero weight contributes nothing; a negative one is a
     * caller bug and throws.
     *
     * @param list<string>                       $nodes
     * @param array<string, array<string, float>> $weights from => to => weight
     * @param array<string, float>               $personalization node => non-negative weight
     * @return array<string, float> node => rank, summing to 1, in $nodes order
     */
    public function rank(array $nodes, array $weights, array $personalization = []): array
    {
        $nodes = \array_values(\array_unique($nodes));
        $n = \count($nodes);
        if ($n === 0) {
            return [];
        }

        $index = \array_flip($nodes);

        // Row-normalised out-edges: from => [to => share].
        $out = [];
        foreach ($weights as $from => $targets) {
            if (!isset($index[$from])) {
                continue;
            }
            $total = 0.0;
            $row = [];
            foreach ($targets as $to => $weight) {
                $weight = (float) $weight;
                if ($weight < 0.0) {
                    throw new \InvalidArgumentException("A PageRank edge weight cannot be negative ({$from} -> {$to}).");
                }
                if (!isset($index[$to]) || $weight === 0.0) {
                    continue;
                }
                $row[$to] = ($row[$to] ?? 0.0) + $weight;
                $total += $weight;
            }
            if ($total > 0.0) {
                foreach ($row as $to => $weight) {
                    $row[$to] = $weight / $total;
                }
                $out[(string) $from] = $row;
            }
        }

        $teleport = $this->teleportVector($nodes, $personalization);

        $rank = \array_fill_keys($nodes, 1.0 / $n);
        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $danglingMass = 0.0;
            foreach ($nodes as $node) {
                if (!isset($out[$node])) {
                    $danglingMass += $rank[$node];
                }
            }

            $next = [];
            foreach ($nodes as $node) {
                $next[$node] = (1.0 - $this->damping) * $teleport[$node] + $this->damping * $danglingMass * $teleport[$node];
            }
            foreach ($out as $from => $row) {
                $share = $this->damping * $rank[$from];
                foreach ($row as $to => $fraction) {
                    $next[$to] += $share * $fraction;
                }
            }

            $change = 0.0;
            foreach ($nodes as $node) {
                $change += \abs($next[$node] - $rank[$node]);
            }
            $rank = $next;

            if ($change < $n * $this->tolerance) {
                break;
            }
        }

        return $rank;
    }

    /**
     * The personalisation normalised to sum to 1 over $nodes, or uniform when
     * it weighs none of them — Aider's retry-unpersonalised on a zero sum, made
     * the definition instead of an exception handler.
     *
     * @param list<string>         $nodes
     * @param array<string, float> $personalization
     * @return array<string, float>
     */
    private function teleportVector(array $nodes, array $personalization): array
    {
        $vector = [];
        $total = 0.0;
        foreach ($nodes as $node) {
            $weight = (float) ($personalization[$node] ?? 0.0);
            if ($weight < 0.0) {
                throw new \InvalidArgumentException("A PageRank personalisation weight cannot be negative ({$node}).");
            }
            $vector[$node] = $weight;
            $total += $weight;
        }

        if ($total <= 0.0) {
            return \array_fill_keys($nodes, 1.0 / \count($nodes));
        }

        foreach ($vector as $node => $weight) {
            $vector[$node] = $weight / $total;
        }

        return $vector;
    }
}
