<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Synchronous async renderer — DEFERS the encode to the next event-loop
 * tick, but does not YIELD it back.
 *
 * Honest shape (finding 006): the promise settles without blocking the
 * CALLER's statement, and other queued work runs before the tick fires —
 * but once it does, the WHOLE encode occupies the loop thread to
 * completion. A poster-class render "may run minutes" per the
 * ChafaRenderer's own contract, so on a live TUI loop this backend can
 * starve input, timers, and sockets for one un-paced stretch. That is a
 * FEATURE for its real homes — tests that await a deterministic single
 * tick, and single-shot scripts with nothing else on the loop — and a
 * hazard for anything else: loop residents wanting interleaved progress
 * want {@see PacedAsyncRenderer} (AdaptiveImage::withAsync's default),
 * which slices the same encode against a per-slice budget. It is also
 * the documented drop-in for environments without ReactPHP ChildProcess.
 */
final class SyncAsyncRenderer implements AsyncRenderer
{
    public function __construct(
        private readonly Mosaic $mosaic,
    ) {}

    public function renderAsync(ImageSource $image, int $width, int $height): PromiseInterface
    {
        $deferred = new Deferred();

        // Defer the actual work to the next tick so the caller can set up
        // downstream consumers before the render runs. Wrap in try/catch so
        // that any exception escaping the futureTick callback is caught and
        // the promise is properly rejected instead of propagating into the
        // event loop (which could crash or leave the promise pending forever).
        try {
            Loop::futureTick(fn() => $this->doRender($image, $width, $height, $deferred));
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    private function doRender(
        ImageSource $image,
        int $width,
        int $height,
        Deferred $deferred,
    ): void {
        try {
            $bytes = $this->mosaic->render($image, $width, $height);
            $deferred->resolve($bytes);
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }
    }
}
