<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Viewport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Viewport\Viewport;

/**
 * Regression tests for "Viewport may not scroll to the end of large
 * text" (mirrors upstream Bubbles #479).
 *
 * Our Viewport does not yet support soft-wrap (per AUDIT_2026_05_06.md
 * — SoftWrap is in the deferred backlog), so every line in `setContent`
 * is one rendered row and `maxOffset = totalLines - height`. These
 * tests pin the current behavior so a future soft-wrap port can't
 * silently regress the scroll-to-end semantics.
 */
final class ViewportScrollEndTest extends TestCase
{
    private function vp(int $height, string $content): Viewport
    {
        return Viewport::new(40, $height)->setContent($content);
    }

    public function testGotoBottomLandsExactlyAtMaxOffset(): void
    {
        $lines = implode("\n", range(1, 100));
        $v = $this->vp(10, $lines)->gotoBottom();
        $this->assertTrue($v->atBottom(), 'gotoBottom should set atBottom() == true');
        // 100 lines - 10 height = 90 max offset
        $this->assertSame(90, $v->yOffset);
    }

    public function testAtBottomWhenContentFitsEntirelyInViewport(): void
    {
        // 5 lines in a 10-line viewport — already at "bottom".
        $v = $this->vp(10, implode("\n", range(1, 5)));
        $this->assertTrue($v->atBottom());
        $this->assertSame(0, $v->yOffset);
    }

    public function testScrollPercentReachesOneAtBottom(): void
    {
        $v = $this->vp(5, implode("\n", range(1, 50)))->gotoBottom();
        $this->assertSame(1.0, $v->scrollPercent());
    }

    public function testScrollPercentIsOneWhenContentFits(): void
    {
        // Content shorter than viewport — defined as "fully visible" = 1.0.
        $v = $this->vp(20, implode("\n", range(1, 5)));
        $this->assertSame(1.0, $v->scrollPercent());
    }

    public function testGotoBottomThenSetContentDoesNotOvershoot(): void
    {
        $v = $this->vp(5, implode("\n", range(1, 100)))->gotoBottom();
        $shorter = $v->setContent(implode("\n", range(1, 10)));
        // After replacing with shorter content, the offset must clamp to the
        // new maxOffset (10 - 5 = 5) — never overshoot.
        $this->assertLessThanOrEqual(5, $shorter->yOffset);
        $this->assertTrue($shorter->atBottom());
    }

    public function testEmptyContentIsAtTopAndBottom(): void
    {
        $v = $this->vp(10, '');
        $this->assertTrue($v->atTop());
        $this->assertTrue($v->atBottom());
    }
}
