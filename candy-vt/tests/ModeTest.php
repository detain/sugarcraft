<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Mode\Mode;

final class ModeTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $m = new Mode();
        $this->assertFalse($m->altScreen);
        $this->assertTrue($m->cursorVisible);
        $this->assertFalse($m->bracketedPaste);
        $this->assertFalse($m->mouseSgr);
        $this->assertFalse($m->mouseAny);
        $this->assertFalse($m->mouseHighlights);
        $this->assertFalse($m->mouseCellMotion);
        $this->assertFalse($m->syncUpdate);
        $this->assertFalse($m->mouseExtended);
    }

    public function testWithAltScreen(): void
    {
        $m = (new Mode())->withAltScreen(true);
        $this->assertTrue($m->altScreen);
    }

    public function testWithCursorVisible(): void
    {
        $m = (new Mode())->withCursorVisible(false);
        $this->assertFalse($m->cursorVisible);
    }

    public function testWithMouseSgr(): void
    {
        $m = (new Mode())->withMouseSgr(true);
        $this->assertTrue($m->mouseSgr);
    }

    public function testEquals(): void
    {
        $a = (new Mode())->withAltScreen(true)->withMouseSgr(true);
        $b = (new Mode())->withAltScreen(true)->withMouseSgr(true);
        $c = (new Mode())->withAltScreen(false)->withMouseSgr(true);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
