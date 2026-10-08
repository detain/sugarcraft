<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\ProcRow;

use SugarCraft\Dash\Plot\Braille\DualSampleGraph;

/**
 * Per-pid lifecycle of the 5×1 cpu mini-graphs in a process list.
 *
 * A graph is created the first time a pid shows cpu > 0, receives one
 * sample per fresh data frame, and is dropped after IDLE_LIMIT
 * consecutive samples below IDLE_THRESHOLD; {@see retain()} is btop's
 * periodic sweep of pids that no longer exist. Call {@see observe()} once
 * per pid per fresh collection (btop's `not data_same`) — only for rows
 * the list actually draws, which is where btop runs it.
 *
 * Creation timing: btop also runs the create branch on repeated
 * (`data_same`) redraws, so a pid that first shows cpu > 0 gets an empty
 * graph on whichever frame draws it, fresh or not. Under this tracker's
 * once-per-fresh-collection contract the graph is created on the next
 * fresh frame instead — at most one fresh frame later — with that
 * frame's sample already pushed, which is what btop's graph holds after
 * its next fresh frame anyway.
 *
 * Deviation: btop writes the idle rule as
 * `else if (cpu_p < 0.1 and ++counter >= 10) erase; else counter = 0;`,
 * so an idle sample that leaves the counter below 10 falls through to the
 * reset and the graph is never erased. This tracker counts consecutive
 * idle samples as the rule intends. The render is unaffected: after ten
 * idle samples a 5-cell graph's whole window is zero, i.e. fully
 * transparent over the underlay, exactly like no graph — only the memory
 * of long-idle pids differs.
 *
 * Immutable: every observation returns a new tracker.
 *
 * Mirrors aristocratos/btop Proc::draw's p_graphs / p_counters handling
 * and its 100-update erase_if sweep (src/btop_draw.cpp).
 */
final class ProcGraphTracker
{
    public const IDLE_LIMIT = 10;
    public const IDLE_THRESHOLD = 0.1;

    /** @var array<int, DualSampleGraph> */
    private array $graphs = [];

    /** @var array<int, int> consecutive idle samples per tracked pid */
    private array $idle = [];

    private function __construct(
        private int $width,
        private string $family,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on width < 1 or an unknown family
     */
    public static function new(int $width = ProcColumns::GRAPH, string $family = DualSampleGraph::FAMILY_BRAILLE): self
    {
        // Validates width and family up front rather than on the first
        // busy pid.
        DualSampleGraph::new($width, 1, $family);
        return new self($width, $family);
    }

    /**
     * btop's sample law for the mini-graph: anything in [0.1, 5) is
     * lifted to 5 so a barely-busy process still shows the lowest band
     * (band 1 starts at 5 on a one-row graph); everything else is
     * `round(cpu)`.
     */
    public static function sample(float $cpu): int
    {
        if (!is_finite($cpu) || $cpu <= 0.0) {
            return 0;
        }
        if ($cpu >= self::IDLE_THRESHOLD && $cpu < 5.0) {
            return 5;
        }
        return (int) round(min($cpu, (float) DualSampleGraph::SAMPLE_LIMIT));
    }

    /**
     * Record one fresh cpu reading for a drawn row.
     *
     * @throws \InvalidArgumentException on a non-finite cpu
     */
    public function observe(int $pid, float $cpu): self
    {
        if (!is_finite($cpu)) {
            throw new \InvalidArgumentException('ProcGraphTracker cpu must be finite');
        }
        $clone = clone $this;
        if (!isset($this->idle[$pid])) {
            if ($cpu <= 0.0) {
                return $this;
            }
            $clone->graphs[$pid] = DualSampleGraph::new($this->width, 1, $this->family);
            $clone->idle[$pid] = 0;
        } elseif ($cpu < self::IDLE_THRESHOLD) {
            $clone->idle[$pid]++;
            if ($clone->idle[$pid] >= self::IDLE_LIMIT) {
                unset($clone->graphs[$pid], $clone->idle[$pid]);
                return $clone;
            }
        } else {
            $clone->idle[$pid] = 0;
        }
        $clone->graphs[$pid] = $clone->graphs[$pid]->push(self::sample($cpu));
        return $clone;
    }

    /**
     * Keep only the listed pids (btop's periodic dead-process sweep).
     *
     * @param iterable<int> $livePids
     */
    public function retain(iterable $livePids): self
    {
        $live = [];
        foreach ($livePids as $pid) {
            $live[$pid] = true;
        }
        $clone = clone $this;
        $clone->graphs = array_intersect_key($this->graphs, $live);
        $clone->idle = array_intersect_key($this->idle, $live);
        return $clone;
    }

    public function has(int $pid): bool
    {
        return isset($this->graphs[$pid]);
    }

    public function graph(int $pid): ?DualSampleGraph
    {
        return $this->graphs[$pid] ?? null;
    }

    /** Consecutive idle samples so far, or null when the pid has no graph. */
    public function idleCount(int $pid): ?int
    {
        return $this->idle[$pid] ?? null;
    }

    /** @return list<int> */
    public function pids(): array
    {
        return array_keys($this->graphs);
    }

    public function count(): int
    {
        return count($this->graphs);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function family(): string
    {
        return $this->family;
    }
}
