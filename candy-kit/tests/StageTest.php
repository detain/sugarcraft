<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\Stage;
use SugarCraft\Kit\Theme;
use PHPUnit\Framework\TestCase;

final class StageTest extends TestCase
{
    public function testStepRendersGlyphCountAndMessage(): void
    {
        $out = Stage::step(2, 5, 'building', Theme::plain());
        $this->assertSame('▸ 2/5 building', $out);
    }

    public function testStepWithoutTotalOmitsSlash(): void
    {
        $out = Stage::step(7, 0, 'cleanup', Theme::plain());
        $this->assertSame('▸ 7 cleanup', $out);
    }

    public function testCustomGlyph(): void
    {
        $out = Stage::step(1, 1, 'go', Theme::plain(), Stage::GLYPH_HASH);
        $this->assertSame('# 1/1 go', $out);
    }

    public function testSubStepTeeAndCorner(): void
    {
        $tee = Stage::subStep('inner', Theme::plain(), isLast: false, indent: 2);
        $end = Stage::subStep('done',  Theme::plain(), isLast: true,  indent: 2);
        $this->assertStringStartsWith('  ├─ inner', $tee);
        $this->assertStringStartsWith('  └─ done',  $end);
    }

    public function testThemeAppliesAccentToGlyph(): void
    {
        $out = Stage::step(1, 2, 'x', Theme::ansi());
        // ANSI accent style emits SGR; raw string contains ESC sequence.
        $this->assertStringContainsString("\x1b[", $out);
    }

    /** GLYPH_BULLET is currently the only un-exercised constant — close that gap. */
    public function testBulletGlyph(): void
    {
        $out = Stage::step(1, 0, 'init', Theme::plain(), Stage::GLYPH_BULLET);
        $this->assertStringContainsString('•', $out);
        $this->assertStringContainsString('init', $out);
    }
}
