<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Memoizing image renderer.
 *
 * Holds a reference to a source image and the Mosaic renderer, then
 * re-encodes on demand, caching the result keyed by [cellWidth, cellHeight].
 *
 * Use this when you need to render the same image at the same size
 * repeatedly (e.g. a TTY that redraws the same viewport each frame).
 */
final class AdaptiveImage
{
    /** @var array<string, string>  key: "wxh", value: encoded bytes */
    private array $cache = [];

    /** @var list<string>  LRU ordering (front = most recent) */
    private array $lru = [];

    public function __construct(
        private readonly ImageSource $image,
        private readonly Mosaic $mosaic,
        private readonly int $maxCache = 4,
        private readonly ?AsyncRenderer $asyncRenderer = null,
    ) {}

    /**
     * Return a version of this AdaptiveImage that uses async rendering.
     *
     * The async renderer is used only for renderAsync(); the normal render()
     * method is always synchronous.
     */
    public function withAsync(?AsyncRenderer $renderer = null): self
    {
        $asyncRenderer = $renderer ?? new SyncAsyncRenderer($this->mosaic);

        return new self(
            image:      $this->image,
            mosaic:     $this->mosaic,
            maxCache:   $this->maxCache,
            asyncRenderer: $asyncRenderer,
        );
    }

    /**
     * Render at the given cell dimensions, using the cache if available.
     *
     * Uses Mosaic::render() internally so scale, dither, and tmux wrapping
     * are all applied consistently.
     */
    public function render(int $cellWidth, int $cellHeight): string
    {
        $key = "{$cellWidth}x{$cellHeight}";

        if (isset($this->cache[$key])) {
            $this->touchLru($key);
            return $this->cache[$key];
        }

        $bytes = $this->mosaic->render($this->image, $cellWidth, $cellHeight);
        $this->cache[$key] = $bytes;
        $this->touchLru($key);

        return $bytes;
    }

    /**
     * Async render — resolves with ANSI bytes, using the cache.
     *
     * Requires withAsync() to have been called first; throws otherwise.
     *
     * @throws \BadMethodCallException if no async renderer is configured.
     */
    public function renderAsync(int $cellWidth, int $cellHeight): PromiseInterface
    {
        if ($this->asyncRenderer === null) {
            throw new \BadMethodCallException(
                'renderAsync() requires withAsync() to be called first.',
            );
        }

        $key = "{$cellWidth}x{$cellHeight}";

        // Serve from cache if available (resolve immediately).
        if (isset($this->cache[$key])) {
            $this->touchLru($key);

            // Resolve in the next tick so the behaviour is consistently async.
            $deferred = new \React\Promise\Deferred();
            Loop::futureTick(fn() => $deferred->resolve($this->cache[$key]));

            return $deferred->promise();
        }

        return $this->asyncRenderer->renderAsync($this->image, $cellWidth, $cellHeight)
            ->then(function (string $bytes) use ($key, $cellWidth, $cellHeight): string {
                $this->cache[$key] = $bytes;
                $this->touchLru($key);

                return $bytes;
            });
    }

    /**
     * Render once and return a PrecomputedImage that holds the result.
     */
    public function precompute(int $cellWidth, int $cellHeight): PrecomputedImage
    {
        return new PrecomputedImage(
            bytes:      $this->render($cellWidth, $cellHeight),
            cellWidth:  $cellWidth,
            cellHeight: $cellHeight,
        );
    }

    /**
     * Invalidate all cached entries.
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->lru   = [];
    }

    /**
     * Number of currently cached entries.
     */
    public function cacheSize(): int
    {
        return count($this->cache);
    }

    /**
     * Move a recently-used key to the front of the LRU list.
     */
    private function touchLru(string $key): void
    {
        $this->lru = array_values(array_filter($this->lru, static fn($k) => $k !== $key));
        array_unshift($this->lru, $key);

        while (count($this->lru) > $this->maxCache) {
            $evicted = array_pop($this->lru);
            unset($this->cache[$evicted]);
        }
    }
}
