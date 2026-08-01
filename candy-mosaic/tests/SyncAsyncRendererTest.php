<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\SyncAsyncRenderer;

/**
 * @covers \SugarCraft\Mosaic\SyncAsyncRenderer
 */
final class SyncAsyncRendererTest extends TestCase
{
    private string $fixture4x2;

    protected function setUp(): void
    {
        $this->fixture4x2 = __DIR__ . '/fixtures/4x2.png';
        if (!file_exists($this->fixture4x2)) {
            $this->markTestSkipped('Fixture tests/fixtures/4x2.png missing');
        }
    }

    public function testRenderAsyncResolvesSuccessfully(): void
    {
        $mosaic = Mosaic::sixel();
        $image = ImageSource::fromFile($this->fixture4x2);
        $renderer = new SyncAsyncRenderer($mosaic);

        $promise = $renderer->renderAsync($image, 8, 4);

        $result = $this->awaitPromise($promise);

        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }

    public function testRenderAsyncRejectsOnInvalidWidth(): void
    {
        $mosaic = Mosaic::sixel();
        $image = ImageSource::fromFile($this->fixture4x2);
        $renderer = new SyncAsyncRenderer($mosaic);

        // Width must be positive for halfblock (used as fallback for sixel probing)
        // Create an image that exceeds any practical limit to trigger error
        $promise = $renderer->renderAsync($image, 0, 0);

        $this->expectException(\Throwable::class);
        $this->awaitPromise($promise);
    }

    private function awaitPromise(\React\Promise\PromiseInterface $promise): mixed
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
}
