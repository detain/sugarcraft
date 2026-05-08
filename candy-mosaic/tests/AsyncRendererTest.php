<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Mosaic\AdaptiveImage;
use SugarCraft\Mosaic\AsyncRenderer;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\SyncAsyncRenderer;

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
}
