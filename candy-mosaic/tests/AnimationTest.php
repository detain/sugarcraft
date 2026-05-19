<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Animation;
use SugarCraft\Mosaic\ImageSource;

/**
 * Tests for the Animation value object.
 */
final class AnimationTest extends TestCase
{
    /** @var list<ImageSource> */
    private array $frames;

    protected function setUp(): void
    {
        parent::setUp();
        $source = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->frames = array_fill(0, 5, $source);
    }

    public function testConstructorStoresFramesAndDelays(): void
    {
        $delays = [100, 200, 300, 400, 500];
        $anim = new Animation($this->frames, $delays);

        $this->assertSame($this->frames, $anim->frames);
        $this->assertSame($delays, $anim->delaysMs);
    }

    public function testEmptyFramesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Animation([], [100]);
    }

    public function testDelayCountMismatchThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Animation($this->frames, [100, 200]);  // 5 frames, 2 delays
    }

    public function testFixedFactoryCreatesUniformDelays(): void
    {
        $anim = Animation::fixed($this->frames, 150);

        $this->assertSame(5, $anim->frameCount());
        $this->assertSame([150, 150, 150, 150, 150], $anim->delaysMs);
    }

    public function testFrameCount(): void
    {
        $anim = Animation::fixed($this->frames, 100);
        $this->assertSame(5, $anim->frameCount());
    }

    public function testTotalDurationMs(): void
    {
        $delays = [100, 200, 300, 400, 500];
        $anim = new Animation($this->frames, $delays);

        $this->assertSame(1500, $anim->totalDurationMs());
    }

    public function testWithFrameReturnsNewInstance(): void
    {
        $anim = Animation::fixed($this->frames, 100);
        $newFrame = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $next = $anim->withFrame(2, $newFrame, 999);

        // New instance has the replaced frame and delay.
        $this->assertSame($newFrame, $next->frames[2]);
        $this->assertSame(999, $next->delaysMs[2]);

        // Original unchanged.
        $this->assertNotSame($newFrame, $anim->frames[2]);
        $this->assertSame(100, $anim->delaysMs[2]);
    }

    public function testWithFrameIndexOutOfRangeThrows(): void
    {
        $anim = Animation::fixed($this->frames, 100);

        $this->expectException(\OutOfRangeException::class);
        $anim->withFrame(99, $this->frames[0], 100);
    }

    public function testWithFrameNegativeIndexThrows(): void
    {
        $anim = Animation::fixed($this->frames, 100);

        $this->expectException(\OutOfRangeException::class);
        $anim->withFrame(-1, $this->frames[0], 100);
    }
}
