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

    public function testWithMouseHighlights(): void
    {
        $m = (new Mode())->withMouseHighlights(true);
        $this->assertTrue($m->mouseHighlights);
    }

    public function testWithMouseHighlightsDefaultTrue(): void
    {
        $m = (new Mode())->withMouseHighlights();
        $this->assertTrue($m->mouseHighlights);
    }

    public function testEquals(): void
    {
        $a = (new Mode())->withAltScreen(true)->withMouseSgr(true);
        $b = (new Mode())->withAltScreen(true)->withMouseSgr(true);
        $c = (new Mode())->withAltScreen(false)->withMouseSgr(true);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testDefaultAltScreenVariantIsNone(): void
    {
        $m = new Mode();
        $this->assertSame(Mode::ALT_NONE, $m->altScreenVariant);
        $this->assertFalse($m->altScreen);
        $this->assertFalse($m->isAltScreen());
    }

    public function testWithAltScreenVariantNoSave(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_NO_SAVE);
        $this->assertSame(Mode::ALT_NO_SAVE, $m->altScreenVariant);
        $this->assertTrue($m->altScreen);
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantCursorOnly(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_CURSOR_ONLY);
        $this->assertSame(Mode::ALT_CURSOR_ONLY, $m->altScreenVariant);
        $this->assertTrue($m->altScreen);
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantFull(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertTrue($m->altScreen);
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantNoneClearsAltScreen(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $this->assertTrue($m->altScreen);
        $m = $m->withAltScreenVariant(Mode::ALT_NONE);
        $this->assertSame(Mode::ALT_NONE, $m->altScreenVariant);
        $this->assertFalse($m->altScreen);
        $this->assertFalse($m->isAltScreen());
    }

    public function testWithAltScreenBoolSetsFullVariant(): void
    {
        $m = (new Mode())->withAltScreen(true);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertTrue($m->altScreen);

        $m = $m->withAltScreen(false);
        $this->assertSame(Mode::ALT_NONE, $m->altScreenVariant);
        $this->assertFalse($m->altScreen);
    }

    public function testIsAltScreenReturnsTrueWhenVariantActive(): void
    {
        $m = new Mode();
        $this->assertFalse($m->isAltScreen());

        $m = $m->withAltScreenVariant(Mode::ALT_NO_SAVE);
        $this->assertTrue($m->isAltScreen());

        $m = $m->withAltScreenVariant(Mode::ALT_CURSOR_ONLY);
        $this->assertTrue($m->isAltScreen());

        $m = $m->withAltScreenVariant(Mode::ALT_FULL);
        $this->assertTrue($m->isAltScreen());

        $m = $m->withAltScreenVariant(Mode::ALT_NONE);
        $this->assertFalse($m->isAltScreen());
    }

    public function testWithMouseSgrPreservesAltScreenVariant(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL)->withMouseSgr(true);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertTrue($m->mouseSgr);
    }

    public function testEqualsIncludesAltScreenVariant(): void
    {
        $a = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $b = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $c = (new Mode())->withAltScreenVariant(Mode::ALT_NO_SAVE);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
