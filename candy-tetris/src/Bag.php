<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

/**
 * 7-bag random piece generator, the standard since Tetris '07.
 *
 * Each "bag" is a uniformly-shuffled permutation of all seven
 * Tetrominoes. Pieces are drawn from the bag in order; once it's
 * empty, a fresh bag is shuffled. Net effect: in any window of
 * 14 consecutive pieces you're guaranteed to see every kind at
 * least once and at most twice. No drought of the I-piece for
 * fifty draws straight.
 *
 * The shuffler is parameterised on a `Closure(int $max): int`
 * (defaults to `random_int(0, $max)`) so tests can inject a
 * deterministic source — every game-rules test runs against a
 * known piece sequence.
 */
final class Bag
{
    /** @var list<Tetromino> */
    private array $pending = [];
    /** @var \Closure(int): int */
    private \Closure $rand;

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
    }

    public function next(): Tetromino
    {
        if ($this->pending === []) {
            $this->pending = self::shuffle(Tetromino::cases(), $this->rand);
        }
        return array_shift($this->pending);
    }

    /**
     * Peek `$n` pieces ahead without consuming them. Used by the
     * "next pieces" preview panel. Refills the bag as needed but
     * does not modify the consumption order.
     *
     * @return list<Tetromino>
     */
    public function peek(int $n): array
    {
        $pendingCount = count($this->pending);
        while ($pendingCount < $n) {
            $this->pending = array_merge(
                $this->pending,
                self::shuffle(Tetromino::cases(), $this->rand),
            );
            $pendingCount = count($this->pending);
        }
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
