<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Mode\Mode;
use SugarCraft\Vterm\Mode\MouseEncoding;

final class ModeTest extends TestCase
{
    /**
     * vterm-06 structural pin: the with*() builders must route through the
     * single mutate() ctor-arg list. A 15th field added to the ctor is then
     * impossible to forget in a per-setter named-arg block — the mutate
     * override line is its only seat.
     */
    public function testBuildersRouteThroughSingleMutateList(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/Mode/Mode.php');
        $this->assertSame(
            1,
            substr_count($src, 'new self('),
            'only mutate() may hand the ctor its named-arg list',
        );
        $ctor = (new \ReflectionMethod(Mode::class, '__construct'))->getParameters();
        foreach ($ctor as $param) {
            $name = $param->getName();
            $this->assertStringContainsString(
                "\$changes['{$name}'] ?? \$this->{$name}",
                $src,
                "mutate() must carry a fallback line for every ctor field ({$name})",
            );
        }
    }

    public function testDefaultValues(): void
    {
        $m = new Mode();
        $this->assertFalse($m->isAltScreen());
        $this->assertTrue($m->cursorVisible);
        $this->assertFalse($m->bracketedPaste);
        $this->assertFalse($m->mouseUtf8);
        $this->assertFalse($m->mouseSgr);
        $this->assertFalse($m->mouseUrxvt);
        $this->assertSame(MouseEncoding::Default, $m->effectiveMouseEncoding());
        $this->assertFalse($m->mouseAny);
        $this->assertFalse($m->mouseHighlights);
        $this->assertFalse($m->mouseCellMotion);
        $this->assertFalse($m->syncUpdate);
        $this->assertFalse($m->mouseExtended);
    }

    /**
     * vterm-07 pin: the bool half-state is gone — the ctor cannot mint an
     * (altScreen=true, ALT_NONE) pair, and isAltScreen() derives from the
     * variant alone.
     */
    public function testAltScreenHalfStateUnrepresentable(): void
    {
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Mode::class, '__construct'))->getParameters(),
        );
        $this->assertNotContains('altScreen', $params);
        $this->assertFalse((new Mode(altScreenVariant: Mode::ALT_NONE))->isAltScreen());
        $this->assertTrue((new Mode(altScreenVariant: Mode::ALT_NO_SAVE))->isAltScreen());
        $this->assertTrue((new Mode(altScreenVariant: Mode::ALT_CURSOR_ONLY))->isAltScreen());
        $this->assertTrue((new Mode(altScreenVariant: Mode::ALT_FULL))->isAltScreen());
    }

    public function testWithAltScreen(): void
    {
        $m = (new Mode())->withAltScreen(true);
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithCursorVisible(): void
    {
        $m = (new Mode())->withCursorVisible(false);
        $this->assertFalse($m->cursorVisible);
    }

    public function testWithMouseEncoding(): void
    {
        $m = (new Mode())->withMouseSgr(true);
        $this->assertTrue($m->mouseSgr);
        $this->assertSame(MouseEncoding::Sgr, $m->effectiveMouseEncoding());
    }

    /**
     * vterm-12 pin: encoders co-activate (xterm switches each mode on its
     * own) and the EFFECTIVE form resolves SGR > urxvt > UTF-8 > X10; a
     * reset falls to the next flag still on instead of the old slot's
     * drop-to-X10.
     */
    public function testEffectiveEncodingPriorityAndFallThrough(): void
    {
        $all = (new Mode())->withMouseUtf8(true)->withMouseUrxvt(true)->withMouseSgr(true);
        $this->assertSame(MouseEncoding::Sgr, $all->effectiveMouseEncoding());
        $this->assertSame(MouseEncoding::Urxvt, $all->withMouseSgr(false)->effectiveMouseEncoding());
        $this->assertSame(MouseEncoding::Utf8, $all->withMouseSgr(false)->withMouseUrxvt(false)->effectiveMouseEncoding());
        $this->assertSame(
            MouseEncoding::Default,
            $all->withMouseSgr(false)->withMouseUrxvt(false)->withMouseUtf8(false)->effectiveMouseEncoding(),
        );
        $this->assertSame(MouseEncoding::Utf8, (new Mode())->withMouseUtf8(true)->effectiveMouseEncoding());
        $this->assertSame(MouseEncoding::Urxvt, (new Mode())->withMouseUrxvt(true)->effectiveMouseEncoding());
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
        $this->assertFalse($m->isAltScreen());
        $this->assertFalse($m->isAltScreen());
    }

    public function testWithAltScreenVariantNoSave(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_NO_SAVE);
        $this->assertSame(Mode::ALT_NO_SAVE, $m->altScreenVariant);
        $this->assertTrue($m->isAltScreen());
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantCursorOnly(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_CURSOR_ONLY);
        $this->assertSame(Mode::ALT_CURSOR_ONLY, $m->altScreenVariant);
        $this->assertTrue($m->isAltScreen());
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantFull(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertTrue($m->isAltScreen());
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantNoneClearsAltScreen(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $this->assertTrue($m->isAltScreen());
        $m = $m->withAltScreenVariant(Mode::ALT_NONE);
        $this->assertSame(Mode::ALT_NONE, $m->altScreenVariant);
        $this->assertFalse($m->isAltScreen());
        $this->assertFalse($m->isAltScreen());
    }

    public function testWithAltScreenBoolSetsFullVariant(): void
    {
        $m = (new Mode())->withAltScreen(true);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertTrue($m->isAltScreen());

        $m = $m->withAltScreen(false);
        $this->assertSame(Mode::ALT_NONE, $m->altScreenVariant);
        $this->assertFalse($m->isAltScreen());
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

    public function testWithMouseEncodingPreservesAltScreenVariant(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL)->withMouseSgr(true);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertSame(MouseEncoding::Sgr, $m->effectiveMouseEncoding());
    }

    public function testIsMouseReportingPolarity(): void
    {
        // Reporting flows iff a TRACKING mode is on; an encoder flag alone
        // must never claim reporting (xterm emits nothing for 1005h alone).
        $this->assertFalse((new Mode())->isMouseReporting());
        $this->assertFalse((new Mode())->withMouseSgr(true)->isMouseReporting());
        foreach (['withMouseAny', 'withMouseHighlights', 'withMouseCellMotion', 'withMouseExtended'] as $setter) {
            $this->assertTrue((new Mode())->$setter(true)->isMouseReporting(), $setter);
        }
    }

    public function testEqualsDistinguishesEncoders(): void
    {
        $a = (new Mode())->withMouseUtf8(true);
        $b = (new Mode())->withMouseUrxvt(true);
        $this->assertFalse($a->equals($b));
        $this->assertTrue($a->equals((new Mode())->withMouseUtf8(true)));
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
