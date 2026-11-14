<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Terminal\Terminal;

/**
 * vterm-28 (finding sugar-vterm-026): facade intake guards.
 *
 * Terminal dimensions and scrollback size routinely come from external
 * input (XTWINOPS reports, window-size handshakes), so the public facade
 * must reject absurd values BEFORE allocating — the Buffer/Scrollback
 * `>= 1` low-level guards stay exactly as they were.
 */
final class IntakeGuardsTest extends TestCase
{
    public function testOversizedConstructionThrowsInConstantMemory(): void
    {
        $before = memory_get_usage();
        try {
            Terminal::new(100000, 100000);
            self::fail('expected InvalidArgumentException before allocation');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('MAX_COLS', $e->getMessage());
        }
        // One rejected intake must not have grown the heap by a grid's worth.
        self::assertLessThan(1_000_000, memory_get_usage() - $before);
    }

    public function testSingleAxisOverflowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Terminal::new(Terminal::MAX_COLS + 1, 2);
    }

    public function testBoundaryDimensionsAreAccepted(): void
    {
        $t = Terminal::new(Terminal::MAX_COLS, 4);
        self::assertSame(Terminal::MAX_COLS, $t->cols());
        self::assertSame(4, $t->rows());
    }

    public function testResizeHonoursTheCeilingAndBoundary(): void
    {
        $t = Terminal::new(10, 4);
        $t->resize(Terminal::MAX_ROWS, 3); // boundary passes
        self::assertSame(Terminal::MAX_ROWS, $t->cols());
        $this->expectException(\InvalidArgumentException::class);
        $t->resize(10, Terminal::MAX_ROWS + 1);
    }

    public function testScrollbackCeilingRejectsOverflowAndAcceptsBoundary(): void
    {
        $t = Terminal::new(8, 2, scrollbackSize: Terminal::MAX_SCROLLBACK);
        $prop = new \ReflectionProperty(Terminal::class, 'scrollbackSize');
        self::assertSame(Terminal::MAX_SCROLLBACK, $prop->getValue($t));
        $this->expectException(\InvalidArgumentException::class);
        Terminal::new(8, 2, scrollbackSize: Terminal::MAX_SCROLLBACK + 1);
    }

    public function testLowBarStillLivesInBufferNotFacade(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('>= 1');
        Terminal::new(0, 24);
    }

    public function testDeprecatedCreateRoutesThroughTheGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Terminal::create(100000, 2);
    }

    public function testInjectedBufferBypassesTheCeilingByDesign(): void
    {
        // The bytes were paid for above the library: the ceiling guards
        // facade-created grids, not caller-supplied buffers.
        $t = Terminal::new(cols: 80, rows: 4, buffer: new Buffer(10000, 2));
        self::assertSame(10000, $t->cols());
    }
}
