<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Image\AdaptiveImage;
use SugarCraft\Image\AsyncRenderer;
use SugarCraft\Image\Capability;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\PacedAsyncRenderer;
use SugarCraft\Image\Renderer\IncrementalRenderer;
use SugarCraft\Image\Renderer\Renderer;
use SugarCraft\Image\SyncAsyncRenderer;

final class AsyncRendererTest extends TestCase
{
    /** Await a promise by running the event loop until it resolves. */
    private function awaitPromise(PromiseInterface $promise): mixed
    {
        $result = null;
        $rejected = null;

        $promise->then(
            function ($v) use (&$result) {
                $result = $v;
                Loop::stop();
            },
            function ($e) use (&$rejected) {
                $rejected = $e;
                Loop::stop();
            },
        );

        Loop::run();

        if ($rejected !== null) {
            throw $rejected;
        }

        return $result;
    }

    public function testRenderAsyncResolvesWithBytes(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $adaptive = (new AdaptiveImage($image, $mosaic))->withAsync();

        $promise = $adaptive->renderAsync(8, 4);
        $this->assertInstanceOf(PromiseInterface::class, $promise);

        $bytes = $this->awaitPromise($promise);
        $this->assertNotEmpty($bytes);
    }

    public function testRenderAsyncMatchesSyncRender(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $adaptive = (new AdaptiveImage($image, $mosaic))->withAsync();

        $syncBytes = $adaptive->render(8, 4);
        $asyncBytes = $this->awaitPromise($adaptive->renderAsync(8, 4));

        $this->assertSame($syncBytes, $asyncBytes);
    }

    public function testRenderAsyncServesFromCache(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $adaptive = (new AdaptiveImage($image, $mosaic, maxCache: 4))->withAsync();

        // Warm the cache via async path.
        $this->awaitPromise($adaptive->renderAsync(8, 4));

        // A second async render of the same size should come back.
        $bytes = $this->awaitPromise($adaptive->renderAsync(8, 4));
        $this->assertNotEmpty($bytes);
    }

    public function testRenderAsyncRejectsWithoutWithAsync(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $adaptive = new AdaptiveImage($image, $mosaic);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('renderAsync() requires withAsync()');

        $adaptive->renderAsync(8, 4);
    }

    public function testWithAsyncAcceptsCustomRenderer(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $customRenderer = new class($mosaic) implements AsyncRenderer {
            public function __construct(private readonly Mosaic $mosaic) {}

            public function renderAsync(ImageSource $image, int $width, int $height): PromiseInterface
            {
                $deferred = new Deferred();
                Loop::futureTick(
                    fn() => $deferred->resolve($this->mosaic->render($image, $width, $height)),
                );

                return $deferred->promise();
            }
        };

        $adaptive = (new AdaptiveImage($image, $mosaic))->withAsync($customRenderer);

        $bytes = $this->awaitPromise($adaptive->renderAsync(8, 4));
        $this->assertNotEmpty($bytes);
    }

    public function testWithAsyncCreatesNewInstance(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $original = new AdaptiveImage($image, $mosaic);
        $async    = $original->withAsync();

        $this->assertNotSame($original, $async);
    }

    public function testTheDefaultAsyncBackendIsPacedNotFutureTickWholeEncode(): void
    {
        // Finding 006's contract: the DEFAULT withAsync() must hand the
        // encode to the paced pump, not the defer-but-not-yield wrapper.
        $mosaic   = Mosaic::sixel();
        $image    = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $property = new \ReflectionProperty(AdaptiveImage::class, 'asyncRenderer');
        $property->setAccessible(true);

        $default = (new AdaptiveImage($image, $mosaic))->withAsync();
        $this->assertInstanceOf(
            PacedAsyncRenderer::class,
            $property->getValue($default),
            'the shipped default is the paced pump (G2 ruling)',
        );

        $explicit = (new AdaptiveImage($image, $mosaic))->withAsync(new SyncAsyncRenderer($mosaic));
        $this->assertInstanceOf(
            SyncAsyncRenderer::class,
            $property->getValue($explicit),
            'an explicit renderer is still honored verbatim',
        );
    }

    public function testThePacedDefaultYieldsTheLoopBetweenSlices(): void
    {
        // Interleaving proof (acceptance): with a forced-slow incremental
        // tier, a periodic timer armed BEFORE the render keeps firing
        // WHILE the encode spans loop iterations — one loop spin per
        // slice. The whole-encode-on-one-tick shape of SyncAsyncRenderer
        // pins the loop thread for all five 40 ms chunks before it ever
        // returns, so the timer can only collect the post-encode spins
        // (measured 2 < 3 floor). Mutation-proven: swapping the default
        // back reddens this arm AND the type pin.
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $slow  = new class implements Renderer, IncrementalRenderer {
            public function render(ImageSource $image, int $width, ?int $height = null): string
            {
                return \implode('', iterator_to_array($this->renderRows($image, $width, $height), false));
            }

            public function renderRows(ImageSource $image, int $width, ?int $height = null): \Generator
            {
                // Five 40 ms chunks against the 8 ms default slice budget:
                // slice 1 runs inline, slices 2-5 arrive on futureTicks —
                // at least four loop spins, each granting the timer.
                foreach (['A', 'B', 'C', 'D', 'E'] as $chunk) {
                    \usleep(40_000);
                    yield $chunk;
                }
            }

            public function name(): string
            {
                return 'slow-fake';
            }

            public function supportsAlpha(): bool
            {
                return false;
            }

            public function isInline(): bool
            {
                return false;
            }

            public function delete(string $imageId): string
            {
                return '';
            }
        };
        $mosaic   = new Mosaic($slow, Capability::universal(), null, null, null);
        $adaptive = (new AdaptiveImage($image, $mosaic))->withAsync();

        $spins = 0;
        $timer = Loop::addPeriodicTimer(0.001, static function () use (&$spins): void {
            $spins++;
        });

        $bytes = $this->awaitPromise($adaptive->renderAsync(2, 1));
        Loop::cancelTimer($timer);

        $this->assertSame('ABCDE', $bytes, 'paced chunks concatenate to the sync bytes');
        $this->assertGreaterThanOrEqual(3, $spins, 'the loop served its timer between encode slices, not just after them');
    }
}
