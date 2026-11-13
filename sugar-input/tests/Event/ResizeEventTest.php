<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests\Event;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\ResizeEvent;
use SugarCraft\Input\Event;

/**
 * Tests for ResizeEvent — terminal resize events (SIGWINCH).
 */
final class ResizeEventTest extends TestCase
{
    public function testConstructorSetsColsAndRows(): void
    {
        $event = new ResizeEvent(120, 40);

        $this->assertSame(120, $event->cols);
        $this->assertSame(40, $event->rows);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new ResizeEvent(80, 24);

        $this->assertInstanceOf(Event::class, $event);
    }

    public function testReadonlyProperties(): void
    {
        $event = new ResizeEvent(100, 50);

        // Properties must not be reassignable (readonly)
        $this->expectException(\Error::class);
        $event->cols = 200;
    }

    public function testSmallDimensions(): void
    {
        $event = new ResizeEvent(1, 1);

        $this->assertSame(1, $event->cols);
        $this->assertSame(1, $event->rows);
    }

    public function testLargeDimensions(): void
    {
        $event = new ResizeEvent(4096, 2160);

        $this->assertSame(4096, $event->cols);
        $this->assertSame(2160, $event->rows);
    }

    public function testZeroDimensionsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ResizeEvent(0, 0);
    }

    public function testEitherDimensionAloneRefusesTheFrame(): void
    {
        foreach ([[0, 24], [80, 0], [-1, 24], [80, -1]] as [$cols, $rows]) {
            try {
                new ResizeEvent($cols, $rows);
                $this->fail("expected refusal of {$cols}x{$rows}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("{$cols}x$rows", $e->getMessage());
            }
        }
    }

    public function testOneCellFrameIsTheSmallestLegalTerminal(): void
    {
        $event = new ResizeEvent(1, 1);

        $this->assertSame(1, $event->cols);
        $this->assertSame(1, $event->rows);
    }
}
