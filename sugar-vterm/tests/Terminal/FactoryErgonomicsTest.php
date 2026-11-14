<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Mode\Mode;
use SugarCraft\Vterm\Terminal\Terminal;

/**
 * vterm-03: the emulator façade factory mirrors the constructor's optional
 * params directly (no positional fillers for focus wiring or a pre-set DEC
 * mode) and the façade grows live cols()/rows() reads that skip the O(rows ×
 * cols) screen() rebuild.
 */
final class FactoryErgonomicsTest extends TestCase
{
    public function testFactoryThreadsFocusCallbackWithoutPositionalFillers(): void
    {
        $received = [];
        $t = Terminal::new(20, 5, onFocusEvent: function (object $msg) use (&$received): void {
            $received[] = $msg;
        });
        $t->feed("\x1b[?1004h\x1b[I");

        $this->assertCount(1, $received);
        $this->assertSame($t->focusEvents()[0], $received[0]);
    }

    public function testFactoryAcceptsAPresetMode(): void
    {
        $mode = (new Mode())->withAutoWrap(false);
        $t = Terminal::new(20, 5, mode: $mode);

        $this->assertFalse($t->mode()->autoWrap);
    }

    public function testColsRowsDefaultToTheFactoryGrid(): void
    {
        $t = Terminal::new();

        $this->assertSame([80, 24], [$t->cols(), $t->rows()]);
    }

    public function testColsRowsReadLiveAfterResize(): void
    {
        $t = Terminal::new(20, 5);
        $t->resize(7, 3);

        // Live dimensions, not the constructor echo: resize(7,3) is visible
        // through both accessors without any screen() rebuild.
        $this->assertSame([7, 3], [$t->cols(), $t->rows()]);
    }
}
