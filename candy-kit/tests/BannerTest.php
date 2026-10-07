<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Core\Util\Width;
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
        $out = Banner::title('CandyApp', 'v0.1.0', Theme::ansi());
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

    /**
     * Security: title/subtitle are interpolated into the bordered box. Under
     * the plain theme any ESC/BEL in the output came from the caller; a raw
     * newline would add an unbordered-width row. All must be neutralized.
     */
    public function testTitleAndSubtitleEscapeAndControlBytesNeutralized(): void
    {
        $out = Banner::title("a\x1b[2Jb\nc", "s\x1b]0;t\x07u\nv", Theme::plain(), Border::ascii());
        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertSame(
            "+-------+\n"
            . "|  abc  |\n"
            . "|  suv  |\n"
            . "+-------+",
            $out,
        );
    }

    /**
     * `$width` is the INNER content width (Style::width()'s semantics), so the
     * title is cut before the box is laid and the whole block stays at
     * width + 2*padding + 2*border cells instead of growing with the text.
     */
    public function testWidthParamCapsTheBox(): void
    {
        $out = Banner::title('LongTitleHere', 'sub', Theme::plain(), null, 6);
        $rows = explode("\n", $out);

        $this->assertCount(4, $rows, 'top, title, subtitle, bottom');
        // 6 inner + 2x2 padding + 2 border = 12 cells on every row.
        foreach ($rows as $i => $row) {
            $this->assertSame(12, Width::string($row), "row {$i}");
        }
        $this->assertSame('│  LongTi  │', $rows[1], 'hard cut, no ellipsis');
        $this->assertSame('│  sub     │', $rows[2], 'shorter lines keep their padding');
    }

    /** Omitting `$width` keeps the pre-existing auto-sizing output byte-for-byte. */
    public function testNullWidthLeavesTheBoxAutoSized(): void
    {
        $auto   = Banner::title('LongTitleHere', 'sub', Theme::plain());
        $explicit = Banner::title('LongTitleHere', 'sub', Theme::plain(), null, null);

        $this->assertSame($auto, $explicit);
        $this->assertSame('│  LongTitleHere  │', explode("\n", $auto)[1]);
    }

    /**
     * Banner takes the same side of the width argument as Section and HelpText:
     * a width below 1 is an authoring error, not an empty box.
     */
    public function testWidthBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Banner width must be at least 1 cell');
        Banner::title('App', '', Theme::plain(), null, 0);
    }
}
