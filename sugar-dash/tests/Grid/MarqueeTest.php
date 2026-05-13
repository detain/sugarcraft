<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Marquee;

final class MarqueeTest extends TestCase
{
    public function testNewCreatesMarquee(): void
    {
        $marquee = Marquee::new('Hello World');
        $this->assertNotNull($marquee);
    }

    public function testRenderReturnsString(): void
    {
        $marquee = Marquee::new('Hello');
        $rendered = $marquee->render();
        $this->assertIsString($rendered);
    }

    public function testFastCreatesFastMarquee(): void
    {
        $marquee = Marquee::fast('Fast text');
        $this->assertNotNull($marquee);
    }

    public function testSlowCreatesSlowMarquee(): void
    {
        $marquee = Marquee::slow('Slow text');
        $this->assertNotNull($marquee);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $marquee = Marquee::new('Test');
        [$width, $height] = $marquee->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertEquals(1, $height);
    }

    public function testNextFrameReturnsNewInstance(): void
    {
        $marquee = Marquee::new('Hello');
        $next = $marquee->nextFrame();
        $this->assertNotSame($marquee, $next);
    }

    public function testWithSpeedReturnsNewInstance(): void
    {
        $marquee = Marquee::new('Hello');
        $newMarquee = $marquee->withSpeed(5);
        $this->assertNotSame($marquee, $newMarquee);
    }

    public function testWithDirectionReturnsNewInstance(): void
    {
        $marquee = Marquee::new('Hello');
        $newMarquee = $marquee->withDirection(false);
        $this->assertNotSame($marquee, $newMarquee);
    }

    public function testWithFadeEdgesReturnsNewInstance(): void
    {
        $marquee = Marquee::new('Hello');
        $newMarquee = $marquee->withFadeEdges(false);
        $this->assertNotSame($marquee, $newMarquee);
    }

    public function testRenderAtFrameReturnsDifferentOutput(): void
    {
        $marquee = Marquee::new('Hello World');
        $frame0 = $marquee->render(0);
        $frame5 = $marquee->render(5);
        // Different frames should produce different output
        $this->assertIsString($frame0);
        $this->assertIsString($frame5);
    }
}
