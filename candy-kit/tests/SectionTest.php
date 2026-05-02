<?php

declare(strict_types=1);

namespace CandyCore\Kit\Tests;

use CandyCore\Core\Util\Width;
use CandyCore\Kit\Section;
use CandyCore\Kit\Theme;
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
}
