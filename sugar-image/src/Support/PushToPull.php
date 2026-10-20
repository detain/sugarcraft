<?php

declare(strict_types=1);

namespace SugarCraft\Image\Support;

/**
 * Turns a push (callback) producer into a lazy pull generator (D-05).
 *
 * The sixel encoder streams through a `callable(string):void $write` sink
 * ({@see \SugarCraft\Image\Renderer\SixelRenderer::encodeBandStream()});
 * {@see \SugarCraft\Image\Renderer\IncrementalRenderer} consumes pull-style
 * generators. This bridge runs the producer inside a {@see \Fiber}: each
 * `$write` suspends and hands its bytes out as the next yielded chunk, so the
 * producer only advances as far as the consumer pumps — the callback never
 * "collects then replays", which would reintroduce the whole-buffer freeze
 * D-05 exists to remove.
 *
 * Abandonment semantics: dropping the generator before exhaustion discards
 * the fiber's stack. Objects held only there (the GD canvas inside the
 * encoder) release by normal refcount teardown, but the producer's own
 * `finally` cleanups do NOT get a chance to emit trailing bytes — an
 * abandoned stream is a truncated stream. See the interface contract on
 * {@see \SugarCraft\Image\Renderer\IncrementalRenderer::renderRows()}.
 */
final class PushToPull
{
    /**
     * @param callable(callable(string):void):void $produce  Push-style producer:
     *                                                       it invokes its `$write`
     *                                                       argument once per chunk,
     *                                                       in append order.
     * @return \Generator<int,string> one yielded chunk per `$write` call
     */
    public static function chunks(callable $produce): \Generator
    {
        $fiber = new \Fiber(static function () use ($produce): void {
            $produce(static function (string $chunk): void {
                \Fiber::suspend($chunk);
            });
        });

        $value = $fiber->start();
        while (!$fiber->isTerminated()) {
            if (!is_string($value)) {
                // Only a suspended Fiber::suspend($chunk) reaches a driver
                // loop with a value; anything else means the producer broke
                // the contract. Fail loud — never yield a non-string on.
                throw new \TypeError(sprintf(
                    'Push producer suspended with %s, expected string bytes',
                    get_debug_type($value),
                ));
            }
            yield $value;
            $value = $fiber->resume();
        }
    }
}
