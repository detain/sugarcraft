<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Parser\Parser;

/**
 * Tests for DECOM (DEC Origin Mode) — CSI ? 6 h enable, CSI ? 6 l disable.
 */
final class OriginModeTest extends TestCase
{
    private function handler(int $cols = 20, int $rows = 5): ScreenHandler
    {
        return new ScreenHandler(new Buffer($cols, $rows));
    }

    private function feed(string $bytes, int $cols = 20, int $rows = 5): ScreenHandler
    {
        $h = $this->handler($cols, $rows);
        (new Parser($h))->feed($bytes);
        return $h;
    }

    // ─── Mode field & wither ────────────────────────────────────────────────

    public function testOriginModeDefaultsToFalse(): void
    {
        $m = new Mode();
        $this->assertFalse($m->originMode);
    }

    public function testWithOriginModeReturnsNewInstance(): void
    {
        $m = new Mode();
        $m2 = $m->withOriginMode(true);
        $this->assertFalse($m->originMode);
        $this->assertTrue($m2->originMode);
    }

    public function testOriginModeIncludedInEquals(): void
    {
        $a = (new Mode())->withOriginMode(true);
        $b = new Mode();
        $this->assertFalse($a->equals($b));
        $this->assertTrue($a->equals($a));
        $this->assertTrue($b->equals($b));
    }

    // ─── CSI ? 6 h / l ─────────────────────────────────────────────────────

    public function testCsiQuestion6hEnablesOriginMode(): void
    {
        $h = $this->handler();
        $this->assertFalse($h->mode->originMode);
        (new Parser($h))->feed("\x1b[?6h");
        $this->assertTrue($h->mode->originMode);
    }

    public function testCsiQuestion6lDisablesOriginMode(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[?6h");
        $this->assertTrue($h->mode->originMode);
        (new Parser($h))->feed("\x1b[?6l");
        $this->assertFalse($h->mode->originMode);
    }

    public function testOriginModeDisableIsIdempotent(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[?6l");
        $this->assertFalse($h->mode->originMode);
        (new Parser($h))->feed("\x1b[?6l");
        $this->assertFalse($h->mode->originMode);
    }
}
