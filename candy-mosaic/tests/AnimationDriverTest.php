<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Animation;
use SugarCraft\Mosaic\AnimationDriver;
use SugarCraft\Mosaic\FrameTickMsg;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;

/**
 * Tests for AnimationDriver — verifies the render+delete cycle with a
 * 5-frame fixture animation driven through the HalfBlockRenderer
 * (deterministic, no probe/network required).
 */
final class AnimationDriverTest extends TestCase
{
    /** @var list<ImageSource> */
    private array $frames;

    private Animation $animation;

    private HalfBlockRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        // Build 5 identical frames from the fixture for a deterministic test.
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->frames = array_fill(0, 5, $source);

        $this->animation = Animation::fixed($this->frames, 100);
        $this->renderer  = new HalfBlockRenderer();
    }

    public function testInitReturnsTickCmdWhenNotPaused(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      0,
            paused:    false,
        );

        $cmd = $driver->init();

        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testInitReturnsNullWhenPaused(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      0,
            paused:    true,
        );

        $this->assertNull($driver->init());
    }

    public function testUpdateFrameTickMsgAdvancesIndexModuloFrameCount(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      2,
            paused:    false,
        );

        [$next, $cmd] = $driver->update(new FrameTickMsg());

        $this->assertSame(3, $next->index);
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testUpdateFrameTickMsgWrapsAroundAtEnd(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      4,  // last frame
            paused:    false,
        );

        [$next, $cmd] = $driver->update(new FrameTickMsg());

        // Wraps to 0.
        $this->assertSame(0, $next->index);
        $this->assertNotNull($cmd);
    }

    public function testUpdateIgnoresUnknownMessage(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      1,
            paused:    false,
        );

        $unknown = new class implements \SugarCraft\Core\Msg {};
        [$next, $cmd] = $driver->update($unknown);

        // State unchanged.
        $this->assertSame(1, $next->index);
        $this->assertNull($cmd);
    }

    public function testViewEmitsDeletePlusRender(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      0,
            paused:    false,
            imageId:    7,
        );

        $out = $driver->view();

        // HalfBlockRenderer::delete('') returns '' (no-op for text renderers).
        // So view() is just the render output for HalfBlock.
        $expected = $this->renderer->render($this->frames[0], 4, 2);

        $this->assertSame($expected, $out);
    }

    public function testWithIndexReturnsNewInstance(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      0,
            paused:    false,
            imageId:    1,
        );

        $next = $driver->withIndex(3);

        $this->assertSame(3, $next->index);
        $this->assertSame(0, $driver->index);  // original unchanged
    }

    public function testWithPausedReturnsNewInstance(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      0,
            paused:    false,
        );

        $paused = $driver->withPaused(true);

        $this->assertTrue($paused->paused);
        $this->assertFalse($driver->paused);  // original unchanged
    }

    public function testWithImageIdReturnsNewInstance(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
            index:      0,
            paused:    false,
            imageId:    1,
        );

        $next = $driver->withImageId(42);

        $this->assertSame(42, $next->imageId);
        $this->assertSame(1, $driver->imageId);  // original unchanged
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $driver = new AnimationDriver(
            animation:  $this->animation,
            renderer:   $this->renderer,
            cellWidth:  4,
            cellHeight: 2,
        );

        $this->assertNull($driver->subscriptions());
    }
}
