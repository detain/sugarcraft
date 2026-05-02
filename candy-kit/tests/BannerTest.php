<?php

declare(strict_types=1);

namespace CandyCore\Kit\Tests;

use CandyCore\Kit\Banner;
use CandyCore\Kit\Theme;
use CandyCore\Sprinkles\Border;
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
}
