<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Flip\Frame;
use SugarCraft\Flip\Player;
use SugarCraft\Flip\Renderer;

/**
 * Pins for the negative-size defence line: a WindowSizeMsg with 0 rows must
 * floor the renderer's row budget at 0 rather than hand withConstraints -1,
 * and withConstraints itself must reject negatives loudly (the @param
 * int<0, max> contract is not enforced by PHP) while still accepting 0x0.
 */
final class PlayerWindowSizeFloorTest extends TestCase
{
    private function player(): Player
    {
        return new Player([new Frame([[0, 0, 0]], 10), new Frame([[1, 1, 1]], 10)]);
    }

    public function testZeroRowWindowSizeMsgFloorsTheRendererRowAtZero(): void
    {
        [$resized] = $this->player()->update(new WindowSizeMsg(cols: 10, rows: 0));
        $renderer = $resized->renderer;
        $this->assertNotNull($renderer);
        $rows = (new \ReflectionProperty(Renderer::class, 'adaptiveRows'))->getValue($renderer);
        $cols = (new \ReflectionProperty(Renderer::class, 'adaptiveCols'))->getValue($renderer);
        $this->assertSame(0, $rows);
        $this->assertSame(10, $cols);
    }

    public function testWithConstraintsRejectsNegativesAndAcceptsTheZeroBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Renderer::withConstraints(-1, 5);
    }

    public function testZeroByZeroConstraintsRemainLegal(): void
    {
        $this->assertInstanceOf(Renderer::class, Renderer::withConstraints(0, 0));
    }
}
