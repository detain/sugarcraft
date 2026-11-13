<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

/**
 * 7-bag random piece generator, the standard since Tetris '07.
 *
 * Each "bag" is a uniformly-shuffled permutation of all seven
 * Tetrominoes. The queue is held a few pieces deeper than any draw so
 * the preview panel never has to touch the RNG.
 *
 * VALUE semantics: next() hands back the drawn piece AND a NEW Bag
 * with the queue advanced; the instance it was called on is untouched.
 * A bag captured in an old game snapshot therefore keeps its own
 * future — and peek(), used by the renderer mid-view(), is a pure
 * slice. The pre-fix shape mutated one shared pending queue in place,
 * so rendering a frame silently advanced (and re-shuffled) the model
 * it was supposed to be merely looking at.
 *
 * Drought guarantee: consecutive bags are disjoint shuffles, so any
 * window of 13 consecutive pieces contains a whole bag — every kind
 * at least once. A single kind appears at most three times in such a
 * window (tail, middle, head across a double boundary); the old
 * docblock's "at most twice" was only true inside one bag.
 *
 * The shuffler is parameterised on a `Closure(int $max): int`
 * (defaults to `random_int(0, $max)`) so tests can inject a
 * deterministic source — every game-rules test runs against a
 * known piece sequence.
 */
final class Bag
{
    /**
     * Queue depth kept ready after every draw, so peek(3) is always
     * satisfied from materialised pieces.
     */
    private const PREVIEW_DEPTH = 3;

    /**
     * Materialised upcoming pieces. Never mutated after construction —
     * every advance builds a fresh instance (not `readonly` only
     * because the clone-and-swap happens inside this class).
     *
     * @var list<Tetromino>
     */
    private array $pending;
    /** @var \Closure(int): int */
    private \Closure $rand;

    /**
     * A reproducible RNG stream for a run seed (candy-tetris-018).
     *
     * A plain 31-bit linear congruential generator, NOT `mt_rand`/
     * `random_int`: those streams are implementation details of the
     * platform and carry no contract that two runs — or two PHP
     * versions — must agree. The LCG is: same seed, same numbers,
     * everywhere. Every derived stream (pieces, garbage holes) comes
     * from a different seed value of the same run, so the streams are
     * independent but each one is fully determined by the run.
     *
     * The returned closure is stateful BY DESIGN (the state cell is
     * captured by reference): one stream object per game lineage, and
     * every draw advances it. {@see Game::$garbageStream} carries the
     * cell through immutable updates so the sequence never restarts.
     *
     * @return \Closure(int): int
     */
    public static function stream(int $seed): \Closure
    {
        $state = $seed & 0x7FFFFFFF;

        return static function (int $max) use (&$state): int {
            // Numerical Recipes constants; the product fits in 64-bit
            // signed space, the mask keeps the state in its 31-bit ring.
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

            return $state % ($max + 1);
        };
    }

    /**
     * A bag whose shuffle stream is fully determined by `$seed` — the
     * door the injectable-RNG seam always lacked for the CLI (GAP-003).
     */
    public static function seeded(int $seed): self
    {
        return new self(self::stream($seed));
    }

    /**
     * @param (\Closure(int): int)|null $rand
     */
    public function __construct(?\Closure $rand = null)
    {
        // Default rand: pick a uniformly-random int in [0, $max].
        // Wrapping `random_int` directly with Closure::fromCallable would
        // give us `random_int($max)` — and `random_int` requires both
        // arguments — so spell the closure out instead.
        $this->rand = $rand ?? static fn(int $max): int => random_int(0, $max);
        $this->pending = self::shuffle(Tetromino::cases(), $this->rand);
    }

    /**
     * Draw the next piece.
     *
     * @return array{0: Tetromino, 1: self} the piece and the advanced bag
     */
    public function next(): array
    {
        $queue = $this->pending;
        if (count($queue) <= self::PREVIEW_DEPTH) {
            $queue = array_merge($queue, self::shuffle(Tetromino::cases(), $this->rand));
        }

        $kind = array_shift($queue);

        $advanced = clone $this;
        $advanced->pending = $queue;

        return [$kind, $advanced];
    }

    /**
     * Up to `$n` upcoming pieces, without consuming them or the RNG.
     * The queue is held at least PREVIEW_DEPTH deep after every draw,
     * so asking for three or fewer is always satisfied in full.
     *
     * @return list<Tetromino>
     */
    public function peek(int $n = 1): array
    {
        return array_slice($this->pending, 0, $n);
    }

    /**
     * Fisher-Yates with the injected RNG.
     *
     * @template T
     * @param list<T> $items
     * @param \Closure(int): int $rand
     * @return list<T>
     */
    private static function shuffle(array $items, \Closure $rand): array
    {
        $n = count($items);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = $rand($i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return array_values($items);
    }
}
