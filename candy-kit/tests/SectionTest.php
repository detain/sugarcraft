<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\Kit\Section;
use SugarCraft\Kit\Theme;
use PHPUnit\Framework\TestCase;

final class SectionTest extends TestCase
{
    public function testHeaderFillsToWidth(): void
    {
        $out = Section::header('SETUP', Theme::plain(), leftPad: 2, width: 20);
        $this->assertSame(20, Width::string($out));
        $this->assertStringContainsString('SETUP', $out);
        $this->assertStringStartsWith('──', $out);
    }

    public function testHeaderWithoutWidthEndsAfterTrailingRune(): void
    {
        $out = Section::header('A', Theme::plain(), leftPad: 1, width: null);
        $this->assertSame('─ A ─', $out);
    }

    public function testRule(): void
    {
        $this->assertSame(str_repeat('─', 10), Section::rule(Theme::plain(), 10));
    }

    public function testCustomRune(): void
    {
        $out = Section::header('X', Theme::plain(), leftPad: 2, width: 8, rune: '=');
        $this->assertStringContainsString('==', $out);
        $this->assertSame(8, Width::string($out));
    }

    /** Multi-cell runes must never overshoot the requested width. */
    public function testMultiCellRuneNeverOvershoots(): void
    {
        // '──' is a 2-cell rune; leftPad=2 means 2 runes = 4 cells.
        // With label ' X ' (3 cells) + fill, total is 19 (not 20 — the
        // 2-cell rune cannot fill the last slot exactly). The important
        // guarantee is that it never EXCEEDS 20.
        $out = Section::header('X', Theme::plain(), leftPad: 2, width: 20, rune: '──');
        $this->assertLessThanOrEqual(20, Width::string($out));
        // Verify exact width for a single-cell rune case (leftPad=2, rune='─')
        $out2 = Section::header('X', Theme::plain(), leftPad: 2, width: 20, rune: '─');
        $this->assertSame(20, Width::string($out2));
    }

    /** rule() applies the theme's muted style (emits SGR for non-plain themes). */
    public function testRuleAppliesTheme(): void
    {
        $out = Section::rule(Theme::ansi(), 10);
        // Theme::ansi()->muted is Style::new()->faint() which emits SGR 2 (faint).
        $this->assertStringContainsString("\x1b[2m", $out);
    }
}
