<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\Banner;
use SugarCraft\Kit\Theme;
use SugarCraft\Sprinkles\Border;
use PHPUnit\Framework\TestCase;

final class BannerTest extends TestCase
{
    public function testTitleOnlyRendersInRoundedBox(): void
    {
        $out = Banner::title('CandyApp', '', Theme::plain());
        // Three rows: top border, content, bottom border.
        $this->assertCount(3, explode("\n", $out));
        $this->assertStringContainsString('CandyApp', $out);
        $this->assertStringContainsString('╭', $out);  // rounded top-left
        $this->assertStringContainsString('╯', $out);  // rounded bottom-right
    }

    public function testSubtitleRendersAsSecondLine(): void
    {
        $out = Banner::title('CandyApp', 'v0.1.0', Theme::plain());
        // 4 rows: top, title, subtitle, bottom.
        $this->assertCount(4, explode("\n", $out));
        $this->assertStringContainsString('CandyApp', $out);
        $this->assertStringContainsString('v0.1.0', $out);
    }

    public function testCustomBorder(): void
    {
        $out = Banner::title('hi', '', Theme::plain(), Border::ascii());
        $this->assertStringContainsString('+', $out);  // ascii corners
        $this->assertStringNotContainsString('╭', $out);
    }

    public function testAnsiThemeWrapsTitleInSgr(): void
    {
        $out = Banner::title('CandyApp', 'v0.1.0');
        $this->assertStringContainsString("\x1b[", $out);
        $this->assertStringContainsString('CandyApp', $out);
    }

    public function testHorizontalPaddingOfTwo(): void
    {
        // Plain theme + plain title 'hi': inner row should be "  hi  "
        // wrapped in border characters → "│  hi  │".
        $out = Banner::title('hi', '', Theme::plain());
        $this->assertStringContainsString('│  hi  │', $out);
    }

    /**
     * The border+padding Style is rebuilt per call (the old process-lifetime
     * static cache is gone). Two consecutive renders with DIFFERENT themes must
     * each reflect their own theme — no cross-call carry-over — and a repeated
     * same-theme render must stay byte-identical. This pins the invariant the
     * cache removal protects: state cannot go stale between calls.
     */
    public function testThemeIsReflectedPerCallAndStable(): void
    {
        $plain = Banner::title('App', 'v1', Theme::plain());
        $ansi  = Banner::title('App', 'v1', Theme::ansi());
        // The ANSI theme colours the title with SGR; the plain theme does not.
        $this->assertStringContainsString("\x1b[", $ansi, 'ansi theme must emit SGR');
        $this->assertStringNotContainsString("\x1b[", $plain, 'plain theme must emit no SGR');
        $this->assertNotSame($plain, $ansi, 'each call must reflect its own theme');

        // A second plain render is byte-identical to the first (no drift).
        $this->assertSame($plain, Banner::title('App', 'v1', Theme::plain()));
    }
}
