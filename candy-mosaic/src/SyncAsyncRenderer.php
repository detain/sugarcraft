<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Synchronous async renderer — defers work to the next event loop tick
 * without blocking the caller, then resolves when the work is complete.
 *
 * This is a drop-in for environments where ReactPHP ChildProcess is not
 * available, or for testing.  It still yields the event loop between
 * scheduling the work and completing it, allowing other events to fire.
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
